<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\Claude;

use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Piwik;
use Piwik\Plugins\Claude\Agent\AnthropicApiException;
use Piwik\Plugins\Claude\Agent\AnthropicClient;
use Piwik\Plugins\Claude\Agent\AnthropicFormat;
use Piwik\Plugins\Claude\Agent\McpAgent;
use Piwik\Plugins\Claude\Services\ApiConnection;
use Piwik\Plugins\Claude\Services\ChatRequestParser;
use Piwik\Plugins\Claude\Services\InsightNotAvailableException;
use Piwik\Plugins\Claude\Services\InsightReport;
use Piwik\Plugins\Claude\Services\RateLimiter;
use Piwik\Plugins\Claude\Services\SafeErrorMessage;
use Piwik\Plugins\Claude\Settings\DefaultPrompts;
use Piwik\Plugins\Claude\Settings\EffectiveSettings;
use Piwik\Plugins\Claude\Settings\ModelUpgradeNotice;
use Piwik\Plugins\Claude\Settings\SiteSettingsStorage;
use Piwik\Plugins\Claude\Settings\SystemSettingsForm;
use Exception;

/**
 * API for plugin Claude
 *
 * @method static \Piwik\Plugins\Claude\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    private $logger;

    /**
     * Request timeout in seconds
     */
    private const REQUEST_TIMEOUT = 120;

    // the current models think before they answer, and the thinking tokens count toward max_tokens
    private const MAX_TOKENS = 16000;

    public function __construct(
        \Piwik\Log\LoggerInterface $logger,
        private ChatRequestParser $requestParser,
        private InsightReport $insightReport,
        private RateLimiter $rateLimiter,
        private AnthropicClient $client
    ) {
        $this->logger = $logger;
    }

    public function getResponse(int $idSite, string $period, string $date, $messages = []): array
    {
        Piwik::checkUserHasSomeViewAccess();

        $idSite = (int) Common::getRequestVar('idSite');
        Piwik::checkUserHasViewAccess($idSite);

        // Get messages from request if not passed or if passed as JSON string
        $messages = $this->requestParser->parseMessages($messages);

        $this->rateLimiter->check($idSite);

        $settings = EffectiveSettings::forSite($idSite);
        $chatBasePrompt = $settings->getChatBasePrompt();

        if ($settings->usesAiProviders()) {
            return $this->answerWithAiProviders($messages, $chatBasePrompt, 'chat');
        }

        return $this->fetchModelAi($chatBasePrompt, $messages, $settings);
    }

    public function getInsights(int $idSite, string $period, string $date, $messages = [], $widgetParams = []): array
    {
        Piwik::checkUserHasSomeViewAccess();

        $idSite = (int) Common::getRequestVar('idSite');
        Piwik::checkUserHasViewAccess($idSite);

        // Parse messages and widgetParams from POST
        $messages = $this->requestParser->parseMessages($messages);
        $widgetParams = $this->requestParser->parseWidgetParams($widgetParams);

        $this->rateLimiter->check($idSite);

        $settings = EffectiveSettings::forSite($idSite);
        $insightBasePrompt = $settings->getInsightBasePrompt();

        $insight = $this->fetchInsightData($widgetParams, $idSite, $date, $period);
        if (isset($insight['error'])) {
            return ['error' => $insight['error']];
        }
        $data = $insight['data'];

        if ($settings->usesAiProviders()) {
            return $this->answerWithAiProviders($messages, "$insightBasePrompt $data", 'insights', true);
        }

        return $this->fetchModelAi("$insightBasePrompt $data", $messages, $settings, Piwik::translate('Claude_InsightAgentPrompt'));
    }

    /**
     * Streams a response from the AI model using Server-Sent Events
     * If widgetParams are present, fetches report data first (insight mode)
     */
    public function getStreamingResponse(int $idSite, string $period, string $date, $messages = [], $widgetParams = []): void
    {
        Piwik::checkUserHasSomeViewAccess();

        $idSite = (int) Common::getRequestVar('idSite');
        Piwik::checkUserHasViewAccess($idSite);

        // Parse messages and widgetParams from POST
        $messages = $this->requestParser->parseMessages($messages);
        $widgetParams = $this->requestParser->parseWidgetParams($widgetParams);

        $this->rateLimiter->check($idSite);

        $settings = EffectiveSettings::forSite($idSite);

        if ($settings->usesAiProviders()) {
            if ($this->insightReport->isInsightRequest($widgetParams)) {
                $insight = $this->fetchInsightData($widgetParams, $idSite, $date, $period);
                $answer = isset($insight['error'])
                    ? ['error' => $insight['error']]
                    : $this->answerWithAiProviders($messages, $settings->getInsightBasePrompt() . ' ' . $insight['data'], 'insights', true);
            } else {
                $answer = $this->answerWithAiProviders($messages, $settings->getChatBasePrompt(), 'chat');
            }
            $this->streamAnswer($answer);
            return;
        }

        if ($this->insightReport->isInsightRequest($widgetParams)) {
            // Insight mode: fetch report data and use insight prompt
            $insight = $this->fetchInsightData($widgetParams, $idSite, $date, $period);
            if (isset($insight['error'])) {
                $this->streamAnswer(['error' => $insight['error']]);
                return;
            }

            $this->streamModelAi(
                $settings->getInsightBasePrompt() . ' ' . $insight['data'],
                $messages,
                $settings,
                Piwik::translate('Claude_InsightAgentPrompt')
            );
            return;
        }

        $this->streamModelAi($settings->getChatBasePrompt(), $messages, $settings);
    }

    /**
     * Settings of a website, empty values use the general settings. The API key is replaced by a placeholder.
     *
     * @return array<string, string>
     */
    public function getSiteSettings(int $idSite): array
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $values = SiteSettingsStorage::read($idSite);
        if ($values['apiKey'] !== '') {
            $values['apiKey'] = SiteSettingsStorage::API_KEY_PLACEHOLDER;
        }

        return $values;
    }

    /**
     * Sets the settings of a website, empty values use the general settings. A parameter left out keeps its saved
     * value, so each card of the settings page saves only its own fields.
     *
     * A prompt equal to the general prompt, or to a default while the general prompt is a default too, is saved empty:
     * the website then follows the general prompt.
     *
     * @param string|null $apiKey the placeholder returned by getSiteSettings or an empty value keeps the saved key
     * @param string|null $modelPreset empty, "latest-recommended" or a model of the preset list
     * @param bool $deleteApiKey removes the API key of the website, the only way to remove it
     */
    public function setSiteSettings(
        int $idSite,
        ?string $host = null,
        ?string $apiKey = null,
        ?string $modelPreset = null,
        ?string $modelCustom = null,
        ?string $chatBasePrompt = null,
        ?string $insightBasePrompt = null,
        bool $deleteApiKey = false
    ): bool {
        Piwik::checkUserHasAdminAccess($idSite);

        $values = [];
        foreach ([
            'host' => $host,
            'apiKey' => $apiKey,
            'modelPreset' => $modelPreset,
            'modelCustom' => $modelCustom,
            'chatBasePrompt' => $chatBasePrompt,
            'insightBasePrompt' => $insightBasePrompt,
        ] as $name => $value) {
            if ($value !== null) {
                $values[$name] = trim(Common::unsanitizeInputValue($value));
            }
        }

        // only an explicit request deletes the key of the website, an empty value keeps it
        if ($deleteApiKey) {
            $values['apiKey'] = '';
        } elseif (isset($values['apiKey']) && $values['apiKey'] === '') {
            unset($values['apiKey']);
        }

        if (($values['host'] ?? '') !== '' && !$this->isValidApiUrl($values['host'])) {
            throw new Exception(Piwik::translate('Claude_InvalidApiUrl'));
        }

        // a saved model that is no longer listed can be kept, so the other settings can still be saved
        $modelPresetValue = $values['modelPreset'] ?? '';
        if ($modelPresetValue !== '' && $modelPresetValue !== SiteSettingsStorage::read($idSite)['modelPreset'] && !Config::isAvailableModel($modelPresetValue)) {
            throw new Exception(Piwik::translate('Claude_InvalidModel', [$modelPresetValue]));
        }

        if (isset($values['modelCustom']) && !preg_match('/^[A-Za-z0-9._:\/@-]{0,200}$/', $values['modelCustom'])) {
            throw new Exception(Piwik::translate('Claude_InvalidModel', [$values['modelCustom']]));
        }

        $generalPrompts = null;
        foreach (DefaultPrompts::SETTING_NAMES as $name => $kind) {
            if (!isset($values[$name]) || $values[$name] === '') {
                continue;
            }
            if ($generalPrompts === null) {
                $systemSettings = new SystemSettings();
                $generalPrompts = [
                    'chatBasePrompt' => $systemSettings->getChatBasePrompt(),
                    'insightBasePrompt' => $systemSettings->getInsightBasePrompt(),
                ];
            }
            $values[$name] = DefaultPrompts::toStoredSitePrompt($kind, $values[$name], $generalPrompts[$name]);
        }

        SiteSettingsStorage::save($idSite, $values);

        return true;
    }

    /**
     * Sets the general settings, edited on the Claude page of the System administration. A parameter left out keeps
     * its saved value.
     *
     * @param string|null $apiKey the placeholder of the settings page keeps the saved key, like an empty value
     * @param bool $deleteApiKey removes the saved API key, the only way to remove it
     */
    public function setSystemSettings(
        ?string $host = null,
        #[\SensitiveParameter]
        ?string $apiKey = null,
        ?string $modelPreset = null,
        ?string $modelCustom = null,
        ?string $chatBasePrompt = null,
        ?string $insightBasePrompt = null,
        bool $deleteApiKey = false
    ): bool {
        Piwik::checkUserHasSuperUserAccess();

        $values = [
            'host' => $host,
            'apiKey' => $apiKey,
            'modelPreset' => $modelPreset,
            'modelCustom' => $modelCustom,
            'chatBasePrompt' => $chatBasePrompt,
            'insightBasePrompt' => $insightBasePrompt,
        ];
        foreach ($values as $name => $value) {
            if ($value !== null) {
                $values[$name] = Common::unsanitizeInputValue($value);
            }
        }

        (new SystemSettingsForm())->save($values, $deleteApiKey);

        return true;
    }

    /**
     * Validates that the URL is a valid HTTPS API endpoint, with the rule of the general settings
     */
    private function isValidApiUrl(?string $url): bool
    {
        return SystemSettings::isHttpsUrl((string) $url);
    }

    /**
     * Sends a conversation to the Anthropic Messages API and returns the answer in the response format of the chat
     * clients, or the error to display
     *
     * @param array<int, mixed> $messages messages of the user, only the user and assistant roles are kept
     * @param string $firstUserMessage sent when the conversation has no user message, such as a new insight
     * @return array{choices?: list<array<string, mixed>>, error?: array<string, string>}
     * @throws Exception with a translated message when the settings cannot be used
     */
    private function fetchModelAi(string $systemPrompt, array $messages, EffectiveSettings $settings, string $firstUserMessage = ''): array
    {
        $config = ApiConnection::fromSettings($settings);
        $payload = $this->buildPayload($systemPrompt, $messages, $config['model'], false, $firstUserMessage);
        if ($payload['messages'] === []) {
            return ['error' => ['message' => Piwik::translate('Claude_InvalidResponse')]];
        }

        $this->logger->info('Claude API request to model: ' . $config['model']);

        try {
            $response = $this->client->send($payload, $config['url'], $config['apiKey'], self::REQUEST_TIMEOUT);
        } catch (AnthropicApiException $e) {
            $this->logger->warning('Claude API error (HTTP {code}, {type}) for model {model}: {message}', [
                'code' => $e->getHttpCode(),
                'type' => $e->getErrorType(),
                'model' => $config['model'],
                'message' => $e->getMessage(),
            ]);
            return ['error' => $this->toErrorPayload($e, $settings)];
        }

        $answer = AnthropicFormat::toChatResponse($response);
        if ($answer['choices'][0]['message']['content'] === '' && $answer['choices'][0]['finish_reason'] === AnthropicFormat::STOP_REFUSAL) {
            return ['error' => ['message' => Piwik::translate('Claude_Refused')]];
        }

        return $answer;
    }

    /**
     * Streams an answer of the Anthropic Messages API as Server-Sent Events, in the chunk format of the chat clients.
     * This method outputs directly to the response stream.
     *
     * @param array<int, mixed> $messages messages of the user, only the user and assistant roles are kept
     */
    private function streamModelAi(string $systemPrompt, array $messages, EffectiveSettings $settings, string $firstUserMessage = ''): void
    {
        $config = ApiConnection::fromSettings($settings);
        $payload = $this->buildPayload($systemPrompt, $messages, $config['model'], true, $firstUserMessage);

        if ($payload['messages'] === []) {
            $this->streamAnswer(['error' => ['message' => Piwik::translate('Claude_InvalidResponse')]]);
            return;
        }

        $this->startEventStream();

        $hasText = false;
        $stopReason = '';
        $streamError = null;

        try {
            $this->client->stream($payload, $config['url'], $config['apiKey'], function (array $event) use (&$hasText, &$stopReason, &$streamError) {
                if ($event['type'] === 'text') {
                    $hasText = true;
                    echo "data: " . json_encode(['choices' => [['delta' => ['role' => 'assistant', 'content' => $event['text']]]]]) . "\n\n";
                    flush();
                } elseif ($event['type'] === 'stop') {
                    $stopReason = $event['reason'];
                } elseif ($event['type'] === 'error' && $streamError === null) {
                    // an error after the stream started, such as an overloaded API
                    $streamError = new AnthropicApiException($event['message'], 200, $event['errorType']);
                }
            });
        } catch (AnthropicApiException $e) {
            $streamError = $e;
        }

        if ($streamError !== null) {
            $this->logger->warning('Claude streaming API error (HTTP {code}, {type}) for model {model}: {message}', [
                'code' => $streamError->getHttpCode(),
                'type' => $streamError->getErrorType(),
                'model' => $config['model'],
                'message' => $streamError->getMessage(),
            ]);
            echo "data: " . json_encode(['error' => $this->toErrorPayload($streamError, $settings)]) . "\n\n";
            flush();
        } elseif (!$hasText && $stopReason === AnthropicFormat::STOP_REFUSAL) {
            echo "data: " . json_encode(['error' => ['message' => Piwik::translate('Claude_Refused')]]) . "\n\n";
            flush();
        }

        echo "data: [DONE]\n\n";
        flush();
    }

    /**
     * @param array<int, mixed> $messages
     * @return array<string, mixed>
     */
    private function buildPayload(string $systemPrompt, array $messages, string $model, bool $stream, string $firstUserMessage): array
    {
        // a system message sent by the browser is ignored: the system prompt only comes from the settings
        $conversation = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $this->requestParser->sanitizeConversation($messages, ['user', 'assistant'])
        );

        return AnthropicFormat::buildRequest($conversation, $model, self::MAX_TOKENS, $stream, $firstUserMessage);
    }

    /**
     * The error displayed in the chat: a model that is not available links to the settings to change it
     *
     * @return array<string, string>
     */
    private function toErrorPayload(AnthropicApiException $exception, EffectiveSettings $settings): array
    {
        if ($exception->getReason() !== null) {
            return ModelUpgradeNotice::build($exception->getReason(), $settings);
        }

        return ['message' => $exception->getUserMessage()];
    }

    /**
     * Answer of the Anthropic provider of AI Providers, in the response format of the chat clients so they handle both
     * engines the same way
     *
     * @param bool $isInsight the insights panel starts the conversation without a message: ask for the analysis
     * @return array{choices?: list<array<string, mixed>>, error?: array{message: string}}
     */
    private function answerWithAiProviders(array $messages, string $systemPrompt, string $featureKey, bool $isInsight = false): array
    {
        $messages = $this->requestParser->sanitizeConversation($messages, ['user', 'assistant']);
        if ($isInsight && ($messages === [] || $messages[0]['role'] !== 'user')) {
            array_unshift($messages, ['role' => 'user', 'content' => Piwik::translate('Claude_InsightAgentPrompt')]);
        }

        try {
            $content = StaticContainer::get(McpAgent::class)->answer($messages, $systemPrompt, $featureKey);
        } catch (\Throwable $e) {
            $this->logger->warning('Claude AI Providers error: ' . $e->getMessage());
            return ['error' => ['message' => $e->getMessage()]];
        }

        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]];
    }

    /**
     * The compact report payload of an insight, or the error to answer with instead: never a backtrace
     *
     * @return array{data?: string, error?: array{message: string}}
     */
    private function fetchInsightData(array $widgetParams, int $idSite, string $date, string $period): array
    {
        try {
            return ['data' => $this->insightReport->fetch($widgetParams, $idSite, $date, $period)];
        } catch (InsightNotAvailableException $e) {
            return ['error' => ['message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            $this->logger->error('Claude insight error: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            return ['error' => ['message' => SafeErrorMessage::fromThrowable($e)]];
        }
    }

    /**
     * Sends a complete answer as Server-Sent Events, in the chunk format of the chat clients
     *
     * @param array{choices?: list<array<string, mixed>>, error?: array{message: string}} $answer
     */
    private function streamAnswer(array $answer): void
    {
        $this->startEventStream();

        if (isset($answer['error'])) {
            echo "data: " . json_encode(['error' => $answer['error']]) . "\n\n";
        } else {
            $content = (string) ($answer['choices'][0]['message']['content'] ?? '');
            echo "data: " . json_encode(['choices' => [['delta' => ['role' => 'assistant', 'content' => $content]]]]) . "\n\n";
        }
        echo "data: [DONE]\n\n";
        flush();
    }

    private function startEventStream(): void
    {
        // Disable all output buffering for streaming
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Disable PHP time limit for long streams
        set_time_limit(0);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Nginx
        header('X-Content-Type-Options: nosniff');

        // Immediately flush headers
        flush();
    }

}
