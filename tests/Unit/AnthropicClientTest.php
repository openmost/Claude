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
use Piwik\Plugins\Claude\Agent\AnthropicApiException;
use Piwik\Plugins\Claude\Agent\AnthropicClient;
use Piwik\Plugins\Claude\tests\Fakes\FakeAnthropicClient;

/**
 * @group Claude
 * @group ClaudeAnthropicClientTest
 * @group Plugins
 */
class AnthropicClientTest extends TestCase
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    private const MESSAGE = '{"id":"msg_1","type":"message","role":"assistant","model":"claude-sonnet-5-5","content":['
        . '{"type":"thinking","thinking":"","signature":"sig"},'
        . '{"type":"text","text":"Let me check."},'
        . '{"type":"tool_use","id":"toolu_1","name":"matomo_site_list","input":{}}'
        . '],"stop_reason":"tool_use","usage":{"input_tokens":10,"output_tokens":20}}';

    private const OVERLOADED = '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}';

    /** @var FakeAnthropicClient */
    private $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeAnthropicClient();
    }

    public function test_send_postsTheMessagesRequest_withTheAnthropicHeaders(): void
    {
        $this->client->responses = [FakeAnthropicClient::response(200, self::MESSAGE)];

        $response = $this->client->send(['model' => 'claude-sonnet-5-5', 'max_tokens' => 100, 'messages' => [], 'stream' => true], self::URL, 'sk-ant-test', 120);

        $this->assertSame('tool_use', $response['stop_reason']);
        $this->assertCount(1, $this->client->requests);
        $request = $this->client->requests[0];
        $this->assertSame(self::URL, $request['url']);
        $this->assertSame(120, $request['timeout']);
        $this->assertContains('x-api-key: sk-ant-test', $request['headers']);
        $this->assertContains('anthropic-version: 2023-06-01', $request['headers']);
        $this->assertContains('content-type: application/json', $request['headers']);
        // a non-streamed request never asks for a stream
        $this->assertSame(['model' => 'claude-sonnet-5-5', 'max_tokens' => 100, 'messages' => []], json_decode($request['body'], true));
    }

    public function test_send_keepsTheContentAsJsonObjects_forTheReplay(): void
    {
        $this->client->responses = [FakeAnthropicClient::response(200, self::MESSAGE)];

        $response = $this->client->send([], self::URL, 'key', 60);

        $this->assertSame([], $response['content'][2]['input']);
        $this->assertSame(
            '{"type":"tool_use","id":"toolu_1","name":"matomo_site_list","input":{}}',
            json_encode($response[AnthropicClient::RAW_CONTENT][2])
        );
    }

    public function test_send_withoutKey_sendsNoApiKeyHeader(): void
    {
        $this->client->responses = [FakeAnthropicClient::response(200, self::MESSAGE)];

        $this->client->send([], 'https://gateway.example.com/v1/messages', '', 60);

        $this->assertStringNotContainsString('x-api-key', implode("\n", $this->client->requests[0]['headers']));
    }

    public function test_send_retriesAnOverloadedApi_thenSucceeds(): void
    {
        $this->client->responses = [
            FakeAnthropicClient::response(529, self::OVERLOADED),
            FakeAnthropicClient::response(500, '{"type":"error","error":{"type":"api_error","message":"Internal"}}'),
            FakeAnthropicClient::response(200, self::MESSAGE),
        ];

        $response = $this->client->send([], self::URL, 'key', 60);

        $this->assertSame('msg_1', $response['id']);
        $this->assertCount(3, $this->client->requests);
        $this->assertSame([1.0, 3.0], $this->client->pauses);
    }

    public function test_send_reportsAPersistentOverload(): void
    {
        $this->client->responses = array_fill(0, 3, FakeAnthropicClient::response(529, self::OVERLOADED));

        try {
            $this->client->send([], self::URL, 'key', 60);
            $this->fail('A persistent overload must be reported');
        } catch (AnthropicApiException $e) {
            $this->assertSame(529, $e->getHttpCode());
            $this->assertSame(AnthropicApiException::OVERLOADED, $e->getErrorType());
        }
        $this->assertCount(AnthropicClient::MAX_RETRIES + 1, $this->client->requests);
    }

    public function test_send_waitsForTheRetryAfterDelay_ofARateLimit(): void
    {
        $this->client->responses = [
            FakeAnthropicClient::response(429, '{"type":"error","error":{"type":"rate_limit_error","message":"Rate limited"}}', ['retry-after' => '4']),
            FakeAnthropicClient::response(200, self::MESSAGE),
        ];

        $this->client->send([], self::URL, 'key', 60);

        $this->assertSame([4.0], $this->client->pauses);
    }

    /**
     * @dataProvider getRateLimitsReportedAtOnce
     */
    public function test_send_reportsALongOrUnboundedRateLimit_atOnce(array $headers): void
    {
        $this->client->responses = [FakeAnthropicClient::response(429, '{"type":"error","error":{"type":"rate_limit_error","message":"Rate limited"}}', $headers)];

        try {
            $this->client->send([], self::URL, 'key', 60);
            $this->fail('The rate limit must be reported');
        } catch (AnthropicApiException $e) {
            $this->assertSame(AnthropicApiException::RATE_LIMIT, $e->getErrorType());
        }
        $this->assertCount(1, $this->client->requests);
        $this->assertSame([], $this->client->pauses);
    }

    public function getRateLimitsReportedAtOnce(): array
    {
        return [
            'long retry-after' => [['retry-after' => '60']],
            'spend cap, no retry-after' => [[]],
        ];
    }

    public function test_send_neverRetriesAnAuthenticationError(): void
    {
        $this->client->responses = [FakeAnthropicClient::response(401, '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}')];

        try {
            $this->client->send([], self::URL, 'bad-key', 60);
            $this->fail('The authentication error must be reported');
        } catch (AnthropicApiException $e) {
            $this->assertSame(AnthropicApiException::AUTHENTICATION, $e->getErrorType());
        }
        $this->assertCount(1, $this->client->requests);
    }

    public function test_send_reportsAConnectionError(): void
    {
        $this->client->responses = [['status' => 0, 'headers' => [], 'body' => '', 'error' => 'Could not resolve host']];

        $this->expectException(AnthropicApiException::class);
        $this->expectExceptionMessage('Connection error: Could not resolve host');

        $this->client->send([], self::URL, 'key', 60);
    }

    public function test_send_refusesAHostWithoutHttps(): void
    {
        $this->expectException(AnthropicApiException::class);

        $this->client->send([], 'http://api.anthropic.com/v1/messages', 'key', 60);
    }

    public function test_stream_deliversTheEvents_ofTheEventStream(): void
    {
        $this->client->stream = [
            'status' => 200,
            'headers' => ['HTTP/1.1 200 OK', 'Content-Type: text/event-stream; charset=utf-8'],
            'chunks' => str_split(AnthropicStreamTest::STREAM, 50),
            'error' => '',
        ];
        $events = [];

        $this->client->stream(['model' => 'claude-sonnet-5-5'], self::URL, 'key', function (array $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertSame([
            ['type' => 'text', 'text' => 'Hello'],
            ['type' => 'text', 'text' => ' world, 12% more visits'],
            ['type' => 'stop', 'reason' => 'end_turn'],
        ], $events);
        $this->assertSame(['model' => 'claude-sonnet-5-5', 'stream' => true], json_decode($this->client->requests[0]['body'], true));
        $this->assertContains('accept: text/event-stream', $this->client->requests[0]['headers']);
    }

    public function test_stream_throwsAnErrorAnswer_sentBeforeTheStream(): void
    {
        $this->client->stream = [
            'status' => 401,
            'headers' => ['HTTP/1.1 401 Unauthorized', 'Content-Type: application/json'],
            'chunks' => ['{"type":"error","error":{"type":"authentication_error",', '"message":"invalid x-api-key"}}'],
            'error' => '',
        ];

        try {
            $this->client->stream([], self::URL, 'bad-key', function () {
                $this->fail('No event is expected');
            });
            $this->fail('The error must be thrown');
        } catch (AnthropicApiException $e) {
            $this->assertSame(401, $e->getHttpCode());
            $this->assertSame(AnthropicApiException::AUTHENTICATION, $e->getErrorType());
            $this->assertSame('invalid x-api-key', $e->getMessage());
        }
    }

    public function test_stream_throwsARateLimit_withItsRetryAfterHeader(): void
    {
        $this->client->stream = [
            'status' => 429,
            'headers' => ['HTTP/1.1 429 Too Many Requests', 'retry-after: 20', 'content-type: application/json'],
            'chunks' => ['{"type":"error","error":{"type":"rate_limit_error","message":"Rate limited"}}'],
            'error' => '',
        ];

        try {
            $this->client->stream([], self::URL, 'key', function () {
            });
            $this->fail('The error must be thrown');
        } catch (AnthropicApiException $e) {
            $this->assertSame(AnthropicApiException::RATE_LIMIT, $e->getErrorType());
            $this->assertSame(20, $e->getRetryAfter());
        }
    }

    public function test_stream_reportsAConnectionError(): void
    {
        $this->client->stream = ['status' => 0, 'headers' => [], 'chunks' => [], 'error' => 'Connection refused'];

        $this->expectException(AnthropicApiException::class);
        $this->expectExceptionMessage('Connection error: Connection refused');

        $this->client->stream([], self::URL, 'key', function () {
        });
    }
}
