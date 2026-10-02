<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Claude\tests\Fakes;

use Piwik\Plugins\Claude\Agent\AnthropicClient;

/**
 * Anthropic client with a scripted HTTP transport: no request leaves the test
 */
class FakeAnthropicClient extends AnthropicClient
{
    /** @var list<array{status: int, headers: array<string, string>, body: string, error: string}> */
    public $responses = [];

    /** @var list<array{url: string, headers: list<string>, body: string, timeout?: int}> */
    public $requests = [];

    /** @var list<float> */
    public $pauses = [];

    /** @var array{status: int, headers: list<string>, chunks: list<string>, error: string} scripted streamed answer */
    public $stream = ['status' => 200, 'headers' => [], 'chunks' => [], 'error' => ''];

    public static function response(int $status, string $body, array $headers = []): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => ''];
    }

    protected function sendRequest(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeoutSeconds];

        return array_shift($this->responses);
    }

    protected function openStream(string $url, array $headers, string $body, callable $onHeader, callable $onChunk): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

        foreach ($this->stream['headers'] as $header) {
            $onHeader($header . "\r\n");
        }
        foreach ($this->stream['chunks'] as $chunk) {
            $onChunk($chunk);
        }

        return ['status' => $this->stream['status'], 'error' => $this->stream['error']];
    }

    protected function pause(float $seconds): void
    {
        $this->pauses[] = $seconds;
    }
}
