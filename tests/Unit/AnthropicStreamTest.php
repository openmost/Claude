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
use Piwik\Plugins\Claude\Agent\AnthropicStream;

/**
 * @group Claude
 * @group ClaudeAnthropicStreamTest
 * @group Plugins
 */
class AnthropicStreamTest extends TestCase
{
    public const STREAM = "event: message_start\n"
        . 'data: {"type": "message_start", "message": {"id": "msg_1", "type": "message", "role": "assistant", "content": [], "model": "claude-sonnet-5-5", "stop_reason": null, "usage": {"input_tokens": 25, "output_tokens": 1}}}' . "\n\n"
        . "event: content_block_start\n"
        . 'data: {"type": "content_block_start", "index": 0, "content_block": {"type": "thinking", "thinking": ""}}' . "\n\n"
        . "event: content_block_delta\n"
        . 'data: {"type": "content_block_delta", "index": 0, "delta": {"type": "thinking_delta", "thinking": "Private reasoning"}}' . "\n\n"
        . "event: content_block_delta\n"
        . 'data: {"type": "content_block_delta", "index": 0, "delta": {"type": "signature_delta", "signature": "EqQB"}}' . "\n\n"
        . "event: content_block_stop\n"
        . 'data: {"type": "content_block_stop", "index": 0}' . "\n\n"
        . "event: content_block_start\n"
        . 'data: {"type": "content_block_start", "index": 1, "content_block": {"type": "text", "text": ""}}' . "\n\n"
        . "event: ping\n"
        . 'data: {"type": "ping"}' . "\n\n"
        . "event: content_block_delta\n"
        . 'data: {"type": "content_block_delta", "index": 1, "delta": {"type": "text_delta", "text": "Hello"}}' . "\n\n"
        . "event: content_block_delta\n"
        . 'data: {"type": "content_block_delta", "index": 1, "delta": {"type": "text_delta", "text": " world, 12% more visits"}}' . "\n\n"
        . "event: content_block_stop\n"
        . 'data: {"type": "content_block_stop", "index": 1}' . "\n\n"
        . "event: message_delta\n"
        . 'data: {"type": "message_delta", "delta": {"stop_reason": "end_turn", "stop_sequence": null}, "usage": {"output_tokens": 15}}' . "\n\n"
        . "event: message_stop\n"
        . 'data: {"type": "message_stop"}' . "\n\n";

    private const EXPECTED = [
        ['type' => 'text', 'text' => 'Hello'],
        ['type' => 'text', 'text' => ' world, 12% more visits'],
        ['type' => 'stop', 'reason' => 'end_turn'],
    ];

    public function test_readsTheTextDeltas_andTheStopReason_only(): void
    {
        $stream = new AnthropicStream();

        $this->assertSame(self::EXPECTED, $stream->feed(self::STREAM));
        $this->assertTrue($stream->isStopped());
        $this->assertSame([], $stream->finish());
    }

    /**
     * @dataProvider getChunkSizes
     */
    public function test_eventsSplitAcrossChunks_areReadOnceComplete(int $chunkSize): void
    {
        $stream = new AnthropicStream();
        $events = [];
        foreach (str_split(self::STREAM, $chunkSize) as $chunk) {
            foreach ($stream->feed($chunk) as $event) {
                $events[] = $event;
            }
        }

        $this->assertSame(self::EXPECTED, $events);
        $this->assertTrue($stream->isStopped());
    }

    public function getChunkSizes(): array
    {
        return [
            'one byte' => [1],
            'seven bytes' => [7],
            'one hundred bytes' => [100],
        ];
    }

    public function test_windowsLineEndings_areAccepted(): void
    {
        $stream = new AnthropicStream();

        $this->assertSame(self::EXPECTED, $stream->feed(str_replace("\n", "\r\n", self::STREAM)));
    }

    public function test_anErrorEvent_isReported(): void
    {
        $stream = new AnthropicStream();

        $events = $stream->feed(
            "event: content_block_delta\n"
            . 'data: {"type": "content_block_delta", "index": 0, "delta": {"type": "text_delta", "text": "Partial"}}' . "\n\n"
            . "event: error\n"
            . 'data: {"type": "error", "error": {"type": "overloaded_error", "message": "Overloaded"}}' . "\n\n"
        );

        $this->assertSame([
            ['type' => 'text', 'text' => 'Partial'],
            ['type' => 'error', 'errorType' => 'overloaded_error', 'message' => 'Overloaded'],
        ], $events);
        $this->assertFalse($stream->isStopped());
    }

    public function test_aToolUseDelta_andARefusal(): void
    {
        $stream = new AnthropicStream();

        $events = $stream->feed(
            'data: {"type": "content_block_delta", "index": 1, "delta": {"type": "input_json_delta", "partial_json": "{\"idSite\": 1"}}' . "\n\n"
            . 'data: {"type": "message_delta", "delta": {"stop_reason": "refusal"}}' . "\n\n"
        );

        $this->assertSame([['type' => 'stop', 'reason' => 'refusal']], $events);
    }

    public function test_anIncompleteLastEvent_isReadWhenTheStreamEnds(): void
    {
        $stream = new AnthropicStream();

        $this->assertSame([], $stream->feed('data: {"type": "content_block_delta", "index": 0, "delta": {"type": "text_delta", "text": "Last"}}'));
        $this->assertSame([['type' => 'text', 'text' => 'Last']], $stream->finish());
    }

    public function test_invalidData_isIgnored(): void
    {
        $stream = new AnthropicStream();

        $this->assertSame([], $stream->feed("event: ping\n\n: comment\n\ndata: not json\n\ndata: [DONE]\n\n"));
    }
}
