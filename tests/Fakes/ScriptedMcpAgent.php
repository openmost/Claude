<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Claude\tests\Fakes;

use Piwik\Plugins\AIProviders\AIConversationRequest;
use Piwik\Plugins\AIProviders\AIConversationResponse;
use Piwik\Plugins\Claude\Agent\McpAgent;
use Piwik\Plugins\Claude\Settings\EffectiveSettings;

/**
 * Agent with scripted answers of AI Providers and of the Anthropic Messages API, MCP tool catalog and tool results
 */
class ScriptedMcpAgent extends McpAgent
{
    /** @var list<AIConversationResponse> */
    public $responses = [];

    /** @var list<AIConversationRequest> */
    public $requests = [];

    /** @var list<array<string, mixed>> */
    public $catalog = [];

    /** @var \Throwable|null thrown when the catalog is fetched */
    public $catalogError = null;

    /** @var int */
    public $catalogFetches = 0;

    /** @var list<array<string, mixed>|\Throwable> */
    public $toolResults = [];

    /** @var list<array{string, array<string, mixed>, string}> */
    public $toolCalls = [];

    /** @var string */
    public $keySource = EffectiveSettings::SOURCE_AI_PROVIDERS;

    /** @var bool */
    public $superUser = true;

    /** @var list<array<string, mixed>|\Throwable> answers of the Anthropic Messages API, thrown when a \Throwable */
    public $anthropicResponses = [];

    /** @var list<array{payload: array<string, mixed>, connection: array<string, string>, json: string}> */
    public $anthropicRequests = [];

    /** @var bool */
    public $clientGone = false;

    protected function converseWithAnthropic(array $payload, array $connection): array
    {
        // the payload is encoded as it would be sent, so the test sees the JSON objects and lists of the request
        $this->anthropicRequests[] = ['payload' => json_decode((string) json_encode($payload), true), 'connection' => $connection, 'json' => (string) json_encode($payload)];
        $response = array_shift($this->anthropicResponses);
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    protected function isClientGone(): bool
    {
        return $this->clientGone;
    }

    protected function converse(AIConversationRequest $request): AIConversationResponse
    {
        $this->requests[] = $request;

        return array_shift($this->responses);
    }

    protected function fetchToolCatalog(): array
    {
        $this->catalogFetches++;
        if ($this->catalogError !== null) {
            throw $this->catalogError;
        }

        return $this->catalog;
    }

    protected function callInternalTool(string $name, array $arguments, string $sessionKey): array
    {
        $this->toolCalls[] = [$name, $arguments, $sessionKey];
        $result = array_shift($this->toolResults);
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    protected function translate(string $translationKey, array $params = []): string
    {
        return $params === [] ? $translationKey : $translationKey . ':' . implode(',', $params);
    }

    protected function getKeySource(int $idSite): string
    {
        return $this->keySource;
    }

    protected function isSuperUser(): bool
    {
        return $this->superUser;
    }
}
