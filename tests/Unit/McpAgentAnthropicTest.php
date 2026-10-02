<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\Claude\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Claude\Agent\AnthropicApiException;
use Piwik\Plugins\Claude\Agent\AnthropicClient;
use Piwik\Plugins\Claude\Agent\McpAgent;
use Piwik\Plugins\Claude\Agent\PluginDependencies;
use Piwik\Plugins\Claude\Config;
use Piwik\Plugins\Claude\Settings\EffectiveSettings;
use Piwik\Plugins\Claude\tests\Fakes\FakePluginDependencies;
use Piwik\Plugins\Claude\tests\Fakes\ScriptedMcpAgent;

/**
 * The agent on the Anthropic Messages API, with the key of the plugin settings
 *
 * @group Claude
 * @group ClaudeMcpAgentAnthropicTest
 * @group Plugins
 */
class McpAgentAnthropicTest extends TestCase
{
    private const CATALOG = [
        [
            'name' => 'matomo_site_list',
            'title' => 'List sites',
            'description' => 'Lists the websites',
            'inputSchema' => ['type' => 'object'],
            'readOnly' => true,
        ],
        [
            'name' => 'matomo_report_get',
            'title' => null,
            'description' => 'Gets a report',
            'inputSchema' => ['type' => 'object', 'properties' => ['method' => ['type' => 'string']]],
            'readOnly' => true,
        ],
    ];

    /** @var list<array{string, array<string, mixed>}> */
    private $events = [];

    /** @var ScriptedMcpAgent */
    private $agent;

    /** @var FakePluginDependencies */
    private $dependencies;

    public function setUp(): void
    {
        parent::setUp();

        $this->events = [];
        // AI Providers is connected, but the key of the website overrides it
        $this->dependencies = FakePluginDependencies::connected();
        $this->agent = new ScriptedMcpAgent($this->createMock(LoggerInterface::class), $this->dependencies);
        $this->agent->catalog = self::CATALOG;
        $this->agent->keySource = EffectiveSettings::SOURCE_SITE;
    }

    public function test_run_sendsTheSystemPromptAsATopLevelField_withTheTools(): void
    {
        $this->agent->anthropicResponses = [$this->textResponse('Answer')];

        $this->runAgent([['role' => 'assistant', 'content' => 'Ignored greeting'], ['role' => 'user', 'content' => 'How many visits?']]);

        $this->assertSame([['text', ['content' => 'Answer']]], $this->events);
        $this->assertCount(1, $this->agent->anthropicRequests);
        $request = $this->agent->anthropicRequests[0];
        $this->assertSame('https://api.anthropic.com/v1/messages', $request['connection']['url']);
        $this->assertSame('site-key', $request['connection']['apiKey']);

        $payload = $request['payload'];
        $this->assertSame('claude-opus-5-5', $payload['model']);
        $this->assertSame(McpAgent::ANTHROPIC_MAX_TOKENS, $payload['max_tokens']);
        $this->assertSame('System prompt', $payload['system']);
        $this->assertSame([['role' => 'user', 'content' => 'How many visits?']], $payload['messages']);
        $this->assertSame([
            ['name' => 'matomo_site_list', 'description' => 'Lists the websites', 'input_schema' => ['type' => 'object', 'properties' => []]],
            ['name' => 'matomo_report_get', 'description' => 'Gets a report', 'input_schema' => ['type' => 'object', 'properties' => ['method' => ['type' => 'string']]]],
        ], $payload['tools']);
        $this->assertStringContainsString('"input_schema":{"type":"object","properties":{}}', $request['json']);
        $this->assertArrayNotHasKey('tool_choice', $payload);
        $this->assertArrayNotHasKey('temperature', $payload);
        // AI Providers is never called when the website has its own key
        $this->assertSame([], $this->agent->requests);
    }

    public function test_run_answersTheToolUses_withToolResultsInOneUserMessage(): void
    {
        $assistantContent = [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
            ['type' => 'text', 'text' => 'Let me look.'],
            ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'matomo_site_list', 'input' => []],
            ['type' => 'tool_use', 'id' => 'toolu_02', 'name' => 'matomo_report_get', 'input' => ['method' => 'VisitsSummary.get']],
        ];
        $raw = json_decode((string) json_encode([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
            ['type' => 'text', 'text' => 'Let me look.'],
            ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'matomo_site_list', 'input' => new \stdClass()],
            ['type' => 'tool_use', 'id' => 'toolu_02', 'name' => 'matomo_report_get', 'input' => ['method' => 'VisitsSummary.get']],
        ]));
        $this->agent->anthropicResponses = [
            ['content' => $assistantContent, 'stop_reason' => 'tool_use', AnthropicClient::RAW_CONTENT => $raw],
            $this->textResponse('You had 120 visits.'),
        ];
        $this->agent->toolResults = [
            ['content' => [['type' => 'text', 'text' => 'Site 1']], 'structuredContent' => null, 'isError' => false],
            new \RuntimeException('Unknown method'),
        ];

        $this->runAgent([['role' => 'user', 'content' => 'How many visits?']]);

        $this->assertSame([
            ['text', ['content' => 'Let me look.']],
            ['tool_call', ['id' => 'toolu_01', 'name' => 'matomo_site_list', 'title' => 'List sites']],
            ['tool_result', ['id' => 'toolu_01', 'isError' => false]],
            ['tool_call', ['id' => 'toolu_02', 'name' => 'matomo_report_get', 'title' => 'matomo_report_get']],
            ['tool_result', ['id' => 'toolu_02', 'isError' => true]],
            ['text', ['content' => 'You had 120 visits.']],
        ], $this->events);

        $this->assertSame([
            ['matomo_site_list', [], 'session-key'],
            ['matomo_report_get', ['method' => 'VisitsSummary.get'], 'session-key'],
        ], $this->agent->toolCalls);

        $second = $this->agent->anthropicRequests[1];
        $messages = $second['payload']['messages'];
        $this->assertCount(3, $messages);
        // the assistant turn is replayed unchanged, thinking block and empty tool input included
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertStringContainsString(
            '{"role":"assistant","content":[{"type":"thinking","thinking":"","signature":"sig-1"},{"type":"text","text":"Let me look."},'
            . '{"type":"tool_use","id":"toolu_01","name":"matomo_site_list","input":{}},',
            $second['json']
        );
        $this->assertSame([
            'role' => 'user',
            'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_01', 'content' => 'Site 1'],
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_02', 'content' => 'Unknown method', 'is_error' => true],
            ],
        ], $messages[2]);
    }

    public function test_run_reportsAnInvalidToolInput_toTheModel(): void
    {
        $this->agent->anthropicResponses = [
            ['content' => [['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'matomo_site_list', 'input' => ['a', 'b']]], 'stop_reason' => 'tool_use'],
            $this->textResponse('Sorry.'),
        ];

        $this->runAgent([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame([], $this->agent->toolCalls);
        $this->assertSame(
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_01', 'content' => 'The input of the tool call is not a valid JSON object.', 'is_error' => true],
            $this->agent->anthropicRequests[1]['payload']['messages'][2]['content'][0]
        );
    }

    public function test_run_stopsAtTheTokenLimit_withoutRunningTheTools(): void
    {
        $this->agent->anthropicResponses = [[
            'content' => [['type' => 'text', 'text' => 'Partial']],
            'stop_reason' => 'max_tokens',
        ]];

        $this->runAgent([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame([['text', ['content' => 'Partial']]], $this->events);
        $this->assertCount(1, $this->agent->anthropicRequests);
    }

    public function test_run_explainsARefusal(): void
    {
        $this->agent->anthropicResponses = [['content' => [], 'stop_reason' => 'refusal']];

        $this->runAgent([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame([['error', ['message' => 'Claude_Refused']]], $this->events);
    }

    /**
     * @dataProvider getApiErrors
     */
    public function test_run_explainsTheApiErrors(int $status, string $body, array $headers, string $expectedMessage): void
    {
        $this->agent->anthropicResponses = [AnthropicApiException::fromResponse($status, $body, $headers)];

        $this->runAgent([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame([['error', ['message' => $expectedMessage]]], $this->events);
    }

    public function getApiErrors(): array
    {
        return [
            '401 invalid key' => [401, '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}', [], 'Claude_ApiErrorAuthentication'],
            '429 rate limit' => [429, '{"type":"error","error":{"type":"rate_limit_error","message":"Rate limited"}}', ['retry-after' => '30'], 'Claude_ApiErrorRateLimit:30'],
            '529 overloaded' => [529, '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', [], 'Claude_ApiErrorOverloaded'],
            '400 other' => [400, '{"type":"error","error":{"type":"invalid_request_error","message":"prompt is too long"}}', [], 'prompt is too long'],
        ];
    }

    public function test_run_stopsWhenTheUserLeaves(): void
    {
        $this->agent->anthropicResponses = [
            ['content' => [['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'matomo_site_list', 'input' => []]], 'stop_reason' => 'tool_use'],
            $this->textResponse('Never sent'),
        ];
        $this->agent->toolResults = [['content' => [], 'structuredContent' => ['sites' => []], 'isError' => false]];
        $this->agent->clientGone = true;

        $this->runAgent([['role' => 'user', 'content' => 'Hi']]);

        $this->assertCount(1, $this->agent->anthropicRequests);
    }

    public function test_run_stopsAfterTheMaximumNumberOfIterations(): void
    {
        for ($i = 0; $i < McpAgent::MAX_ITERATIONS; $i++) {
            $this->agent->anthropicResponses[] = ['content' => [['type' => 'tool_use', 'id' => 'toolu_' . $i, 'name' => 'matomo_site_list', 'input' => []]], 'stop_reason' => 'tool_use'];
            $this->agent->toolResults[] = ['content' => [], 'structuredContent' => ['sites' => []], 'isError' => false];
        }

        $this->runAgent([['role' => 'user', 'content' => 'Hi']]);

        $this->assertCount(McpAgent::MAX_ITERATIONS, $this->agent->anthropicRequests);
        $this->assertSame(['error', ['message' => 'Claude_AgentMaxIterations']], end($this->events));
    }

    public function test_run_answersWithoutTools_whenMcpServerIsMissing(): void
    {
        $this->dependencies->plugins[PluginDependencies::MCP_SERVER] = PluginDependencies::PLUGIN_MISSING;
        $this->agent->anthropicResponses = [$this->textResponse('Answer')];

        $this->runAgent([['role' => 'user', 'content' => 'Hi']]);

        $this->assertArrayNotHasKey('tools', $this->agent->anthropicRequests[0]['payload']);
        $this->assertSame(0, $this->agent->catalogFetches);
    }

    public function test_run_onAiProviders_neverCallsTheAnthropicTransport(): void
    {
        $this->agent->responses = [new \Piwik\Plugins\AIProviders\AIConversationResponse(
            'anthropic',
            'Anthropic',
            'claude-sonnet-5-5',
            [['type' => 'text', 'text' => 'From AI Providers']],
            \Piwik\Plugins\AIProviders\AIConversationResponse::STOP_END_TURN
        )];

        $this->agent->run([['role' => 'user', 'content' => 'Hi']], 'System prompt', 'chat', 'session-key', function (string $type, array $data) {
            $this->events[] = [$type, $data];
        }, $this->settings([]));

        $this->assertSame([['text', ['content' => 'From AI Providers']]], $this->events);
        $this->assertSame([], $this->agent->anthropicRequests);
        $this->assertCount(1, $this->agent->requests);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    private function runAgent(array $messages): void
    {
        $this->agent->run($messages, 'System prompt', 'chat', 'session-key', function (string $type, array $data) {
            $this->events[] = [$type, $data];
        }, $this->settings(['apiKey' => 'site-key', 'modelPreset' => 'claude-opus-5-5']));
    }

    /**
     * @param array<string, string> $site
     */
    private function settings(array $site): EffectiveSettings
    {
        return EffectiveSettings::fromValues(1, $site, [
            'host' => Config::DEFAULT_HOST,
            'apiKey' => '',
            'model' => Config::LATEST_RECOMMENDED_MODEL,
            'chatBasePrompt' => 'Chat',
            'insightBasePrompt' => 'Insight',
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function textResponse(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn'];
    }
}
