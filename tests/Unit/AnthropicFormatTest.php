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
use Piwik\Plugins\Claude\Agent\AnthropicClient;
use Piwik\Plugins\Claude\Agent\AnthropicFormat;

/**
 * @group Claude
 * @group ClaudeAnthropicFormatTest
 * @group Plugins
 */
class AnthropicFormatTest extends TestCase
{
    public function test_buildRequest_sendsTheSystemPromptAsATopLevelField(): void
    {
        $request = AnthropicFormat::buildRequest([
            ['role' => 'system', 'content' => 'You are a Matomo expert.'],
            ['role' => 'user', 'content' => 'Hello'],
        ], 'claude-sonnet-5-5', 16000);

        $this->assertSame([
            'model' => 'claude-sonnet-5-5',
            'max_tokens' => 16000,
            'system' => 'You are a Matomo expert.',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ], $request);
        $this->assertArrayNotHasKey('stream', $request);
        $this->assertArrayNotHasKey('temperature', $request);
    }

    public function test_buildRequest_streams_whenAsked(): void
    {
        $request = AnthropicFormat::buildRequest([['role' => 'user', 'content' => 'Hello']], 'claude-opus-5-5', 8000, true);

        $this->assertTrue($request['stream']);
        $this->assertArrayNotHasKey('system', $request);
    }

    public function test_buildRequest_alternatesTheRoles_andStartsWithTheUser(): void
    {
        $request = AnthropicFormat::buildRequest([
            ['role' => 'assistant', 'content' => 'Greeting before any question'],
            ['role' => 'user', 'content' => 'First'],
            ['role' => 'user', 'content' => 'Second'],
            ['role' => 'assistant', 'content' => 'Answer'],
            ['role' => 'assistant', 'content' => 'More'],
            ['role' => 'tool', 'content' => 'ignored'],
            ['role' => 'user', 'content' => '   '],
            ['role' => 'user', 'content' => 'Third'],
        ], 'claude-sonnet-5-5', 1000);

        $this->assertSame([
            ['role' => 'user', 'content' => "First\n\nSecond"],
            ['role' => 'assistant', 'content' => "Answer\n\nMore"],
            ['role' => 'user', 'content' => 'Third'],
        ], $request['messages']);
    }

    public function test_buildRequest_joinsTheSystemMessages(): void
    {
        $request = AnthropicFormat::buildRequest([
            ['role' => 'system', 'content' => 'Base prompt'],
            ['role' => 'system', 'content' => 'Report data'],
            ['role' => 'user', 'content' => 'Hi'],
        ], 'claude-sonnet-5-5', 1000);

        $this->assertSame("Base prompt\n\nReport data", $request['system']);
    }

    public function test_toMessages_startsAnInsightWithTheFirstUserMessage(): void
    {
        $this->assertSame(
            [['role' => 'user', 'content' => 'Analyze this report']],
            AnthropicFormat::toMessages([], 'Analyze this report')
        );
        $this->assertSame(
            [
                ['role' => 'user', 'content' => 'Analyze this report'],
                ['role' => 'assistant', 'content' => 'Insight'],
                ['role' => 'user', 'content' => 'Why?'],
            ],
            AnthropicFormat::toMessages([
                ['role' => 'assistant', 'content' => 'Insight'],
                ['role' => 'user', 'content' => 'Why?'],
            ], 'Analyze this report')
        );
    }

    public function test_toMessages_neverEndsWithAnAssistantMessage(): void
    {
        // the current models refuse an assistant prefill
        $this->assertSame(
            [['role' => 'user', 'content' => 'Question']],
            AnthropicFormat::toMessages([
                ['role' => 'user', 'content' => 'Question'],
                ['role' => 'assistant', 'content' => 'Draft'],
            ])
        );
        $this->assertSame([], AnthropicFormat::toMessages([['role' => 'assistant', 'content' => 'Alone']]));
    }

    public function test_toTools_mapsTheMcpCatalog_toTheToolsOfTheMessagesApi(): void
    {
        $tools = AnthropicFormat::toTools([
            [
                'name' => 'matomo_site_list',
                'title' => 'List sites',
                'description' => 'Lists the websites',
                'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']], 'required' => ['limit']],
                'readOnly' => true,
            ],
            ['name' => 'matomo_ping', 'inputSchema' => []],
            ['name' => '', 'description' => 'No name'],
            'not a tool',
        ]);

        $this->assertCount(2, $tools);
        $this->assertSame([
            'name' => 'matomo_site_list',
            'description' => 'Lists the websites',
            'input_schema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']], 'required' => ['limit']],
        ], $tools[0]);
        $this->assertSame('matomo_ping', $tools[1]['name']);
        $this->assertSame('', $tools[1]['description']);
        // an empty schema is still a JSON object with properties
        $this->assertSame('{"type":"object","properties":{}}', json_encode($tools[1]['input_schema']));
    }

    public function test_toInputSchema_removesTheTopLevelCombinators(): void
    {
        $schema = AnthropicFormat::toInputSchema([
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']],
            'oneOf' => [['required' => ['a']], ['required' => ['b']]],
            'anyOf' => [],
            'allOf' => [],
        ]);

        $this->assertSame(['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]], $schema);
    }

    public function test_getText_onlyReadsTheTextBlocks(): void
    {
        $content = [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'text', 'text' => 'Visits are up '],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'matomo_site_list', 'input' => []],
            ['type' => 'text', 'text' => '12%.'],
        ];

        $this->assertSame('Visits are up 12%.', AnthropicFormat::getText($content));
        $this->assertSame('plain', AnthropicFormat::getText('plain'));
        $this->assertSame('', AnthropicFormat::getText(null));
    }

    public function test_getToolUses_readsTheToolUseBlocks(): void
    {
        $toolUses = AnthropicFormat::getToolUses([
            ['type' => 'text', 'text' => 'Let me check.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'matomo_report_get', 'input' => ['idSite' => 1, 'period' => 'day']],
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'matomo_site_list', 'input' => new \stdClass()],
            ['type' => 'tool_use', 'id' => 'toolu_3', 'name' => 'matomo_bad', 'input' => ['a', 'b']],
            ['type' => 'tool_use', 'id' => '', 'name' => 'matomo_no_id', 'input' => []],
        ]);

        $this->assertSame([
            ['id' => 'toolu_1', 'name' => 'matomo_report_get', 'input' => ['idSite' => 1, 'period' => 'day']],
            ['id' => 'toolu_2', 'name' => 'matomo_site_list', 'input' => []],
            ['id' => 'toolu_3', 'name' => 'matomo_bad', 'input' => null],
        ], $toolUses);
    }

    public function test_replayContent_keepsTheBlocksUnchanged_withTheirJsonObjects(): void
    {
        $raw = json_decode('[{"type":"thinking","thinking":"","signature":"abc"},{"type":"tool_use","id":"toolu_1","name":"matomo_site_list","input":{}}]');

        $this->assertSame($raw, AnthropicFormat::replayContent(['content' => [], AnthropicClient::RAW_CONTENT => $raw]));
        $this->assertSame(
            '[{"type":"thinking","thinking":"","signature":"abc"},{"type":"tool_use","id":"toolu_1","name":"matomo_site_list","input":{}}]',
            json_encode(AnthropicFormat::replayContent(['content' => [], AnthropicClient::RAW_CONTENT => $raw]))
        );
    }

    public function test_replayContent_restoresAnEmptyToolInput_asAJsonObject(): void
    {
        $content = AnthropicFormat::replayContent(['content' => [
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'matomo_site_list', 'input' => []],
        ]]);

        $this->assertSame('[{"type":"tool_use","id":"toolu_1","name":"matomo_site_list","input":{}}]', json_encode($content));
    }

    public function test_toolResultBlock_answersTheToolUse(): void
    {
        $this->assertSame([
            'type' => 'tool_result',
            'tool_use_id' => 'toolu_1',
            'content' => '{"visits":120,"url":"https://example.com/a"}',
        ], AnthropicFormat::toolResultBlock('toolu_1', [
            'content' => [['type' => 'text', 'text' => 'ignored when structured']],
            'structuredContent' => ['visits' => 120, 'url' => 'https://example.com/a'],
            'isError' => false,
        ]));

        $this->assertSame([
            'type' => 'tool_result',
            'tool_use_id' => 'toolu_2',
            'content' => "Unknown method\n{\"type\":\"image\"}",
            'is_error' => true,
        ], AnthropicFormat::toolResultBlock('toolu_2', [
            'content' => [['type' => 'text', 'text' => 'Unknown method'], ['type' => 'image']],
            'structuredContent' => null,
            'isError' => true,
        ]));
    }

    public function test_toolResultText_isNeverEmpty_andIsTruncated(): void
    {
        $this->assertSame('The tool returned no data.', AnthropicFormat::toolResultText(['content' => [], 'structuredContent' => null, 'isError' => false]));
        $this->assertSame('The tool failed.', AnthropicFormat::toolResultText(['content' => [], 'structuredContent' => null, 'isError' => true]));

        $long = AnthropicFormat::toolResultText([
            'content' => [['type' => 'text', 'text' => str_repeat('a', AnthropicFormat::MAX_TOOL_RESULT_CHARS + 10)]],
            'structuredContent' => null,
            'isError' => false,
        ]);
        $this->assertStringStartsWith(str_repeat('a', AnthropicFormat::MAX_TOOL_RESULT_CHARS) . "\n[truncated", $long);
    }

    public function test_toChatResponse_usesTheResponseFormatOfTheChatClients(): void
    {
        $this->assertSame([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hello!'],
                'finish_reason' => 'end_turn',
            ]],
        ], AnthropicFormat::toChatResponse([
            'type' => 'message',
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => ' Hello! ']],
            'stop_reason' => 'end_turn',
        ]));
    }
}
