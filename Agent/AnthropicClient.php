<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\Claude\Agent;

use Piwik\Plugins\Claude\Services\ApiConnection;
use Piwik\Plugins\Claude\SystemSettings;

/**
 * HTTP transport of the Anthropic Messages API (POST {host}/v1/messages), used with the host and key of the plugin
 * settings. The AI Providers path does not go through it.
 */
class AnthropicClient
{
    /**
     * Key of the decoded response holding the content blocks decoded as objects, replayed unchanged by the agent
     */
    public const RAW_CONTENT = 'rawContent';

    public const MAX_RETRIES = 2;
    public const RETRY_DELAYS_SECONDS = [1, 3];
    public const MAX_RETRY_AFTER_SECONDS = 10;
    public const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * One request, retried when the API is rate limited, overloaded or failing for a short while
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> the decoded response
     * @throws AnthropicApiException
     */
    public function send(array $payload, string $url, string $apiKey, int $timeoutSeconds): array
    {
        $this->checkUrl($url);
        unset($payload['stream']);

        for ($attempt = 0; ; $attempt++) {
            $response = $this->sendRequest($url, ApiConnection::headers($apiKey), (string) json_encode($payload), $timeoutSeconds);

            if ($response['error'] !== '') {
                throw new AnthropicApiException('Connection error: ' . $response['error'], 0, AnthropicApiException::CONNECTION);
            }

            if ($response['status'] === 200) {
                $decoded = json_decode($response['body'], true);
                if (!is_array($decoded)) {
                    throw new AnthropicApiException('Invalid JSON response from the Anthropic API', 200);
                }
                if (($decoded['type'] ?? '') === 'error') {
                    throw AnthropicApiException::fromResponse(200, $response['body'], $response['headers']);
                }
                $raw = json_decode($response['body']);
                if ($raw instanceof \stdClass && is_array($raw->content ?? null)) {
                    $decoded[self::RAW_CONTENT] = $raw->content;
                }

                return $decoded;
            }

            $exception = AnthropicApiException::fromResponse($response['status'], $response['body'], $response['headers']);
            if (!$exception->isRetryable() || $attempt >= self::MAX_RETRIES || !$this->canWaitFor($exception)) {
                throw $exception;
            }

            $this->pause($this->getRetryDelay($exception, $attempt));
        }
    }

    /**
     * Streams an answer: $onEvent receives the AnthropicStream events (text, stop, error) as they arrive. An error
     * response sent before the stream starts is thrown.
     *
     * @param array<string, mixed> $payload
     * @param callable(array<string, string>): void $onEvent
     * @throws AnthropicApiException
     */
    public function stream(array $payload, string $url, string $apiKey, callable $onEvent): void
    {
        $this->checkUrl($url);
        $payload['stream'] = true;

        $parser = new AnthropicStream();
        $isStream = null;
        $headers = [];
        $errorBody = '';

        $onHeader = static function (string $header) use (&$isStream, &$headers): void {
            $parts = explode(':', $header, 2);
            if (count($parts) !== 2) {
                return;
            }
            $name = strtolower(trim($parts[0]));
            $headers[$name] = trim($parts[1]);
            if ($name === 'content-type') {
                $isStream = stripos($parts[1], 'text/event-stream') !== false;
            }
        };

        $onChunk = static function (string $chunk) use (&$isStream, &$errorBody, $parser, $onEvent): void {
            // an error answer is plain JSON, not an event stream
            if (!$isStream) {
                $errorBody .= $chunk;
                return;
            }
            foreach ($parser->feed($chunk) as $event) {
                $onEvent($event);
            }
        };

        $result = $this->openStream($url, ApiConnection::headers($apiKey, 'text/event-stream'), (string) json_encode($payload), $onHeader, $onChunk);

        if ($result['error'] !== '') {
            throw new AnthropicApiException('Connection error: ' . $result['error'], 0, AnthropicApiException::CONNECTION);
        }

        if ($isStream && $errorBody === '') {
            foreach ($parser->finish() as $event) {
                $onEvent($event);
            }
            return;
        }

        throw AnthropicApiException::fromResponse($result['status'] !== 200 ? $result['status'] : 0, $errorBody, $headers);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, headers: array<string, string>, body: string, error: string} headers in lowercase
     */
    protected function sendRequest(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, $header) use (&$responseHeaders) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        });

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($responseBody) ? $responseBody : '',
            'error' => $error,
        ];
    }

    /**
     * @param list<string> $headers
     * @param callable(string): void $onHeader receives each response header line
     * @param callable(string): void $onChunk receives the body as it arrives
     * @return array{status: int, error: string}
     */
    protected function openStream(string $url, array $headers, string $body, callable $onHeader, callable $onChunk): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, $header) use ($onHeader) {
            $onHeader($header);
            return strlen($header);
        });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, $chunk) use ($onChunk) {
            $onChunk($chunk);
            return strlen($chunk);
        });

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['status' => $status, 'error' => $error];
    }

    protected function pause(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }

    public function getRetryDelay(AnthropicApiException $exception, int $attempt): float
    {
        if ($exception->getRetryAfter() !== null) {
            return (float) min($exception->getRetryAfter(), self::MAX_RETRY_AFTER_SECONDS);
        }

        return (float) self::RETRY_DELAYS_SECONDS[min($attempt, count(self::RETRY_DELAYS_SECONDS) - 1)];
    }

    /**
     * A long retry-after is reported at once instead of keeping the user waiting, and so is a rate limit without
     * retry-after: Anthropic sends none when a spend cap is reached, which keeps failing until access resumes
     */
    private function canWaitFor(AnthropicApiException $exception): bool
    {
        if ($exception->getRetryAfter() === null) {
            return $exception->getErrorType() !== AnthropicApiException::RATE_LIMIT;
        }

        return $exception->getRetryAfter() <= self::MAX_RETRY_AFTER_SECONDS;
    }

    private function checkUrl(string $url): void
    {
        if (!SystemSettings::isHttpsUrl($url)) {
            throw new AnthropicApiException('Invalid API host URL - HTTPS required');
        }
    }
}
