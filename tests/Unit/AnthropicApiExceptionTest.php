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
use Piwik\Plugins\Claude\Settings\ModelUpgradeNotice;

/**
 * @group Claude
 * @group ClaudeAnthropicApiExceptionTest
 * @group Plugins
 */
class AnthropicApiExceptionTest extends TestCase
{
    public function test_401_isAnAuthenticationError(): void
    {
        $exception = AnthropicApiException::fromResponse(
            401,
            '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"},"request_id":"req_1"}'
        );

        $this->assertSame(401, $exception->getHttpCode());
        $this->assertSame(AnthropicApiException::AUTHENTICATION, $exception->getErrorType());
        $this->assertSame('invalid x-api-key', $exception->getMessage());
        $this->assertFalse($exception->isRetryable());
        $this->assertNull($exception->getReason());
        $this->assertSame('Claude_ApiErrorAuthentication', $exception->getUserMessage($this->translator()));
    }

    public function test_429_isARateLimit_withItsRetryAfterDelay(): void
    {
        $exception = AnthropicApiException::fromResponse(
            429,
            '{"type":"error","error":{"type":"rate_limit_error","message":"Number of requests has exceeded your rate limit"}}',
            ['retry-after' => '12.4', 'request-id' => 'req_2']
        );

        $this->assertSame(AnthropicApiException::RATE_LIMIT, $exception->getErrorType());
        $this->assertSame(13, $exception->getRetryAfter());
        $this->assertTrue($exception->isRetryable());
        $this->assertSame('Claude_ApiErrorRateLimit:13', $exception->getUserMessage($this->translator()));
    }

    public function test_429_withoutRetryAfter_isASpendLimit(): void
    {
        $exception = AnthropicApiException::fromResponse(429, '{"type":"error","error":{"type":"rate_limit_error","message":"Spend cap reached"}}');

        $this->assertNull($exception->getRetryAfter());
        $this->assertSame('Claude_ApiErrorRateLimitNoDelay', $exception->getUserMessage($this->translator()));
    }

    public function test_529_isAnOverloadedApi(): void
    {
        $exception = AnthropicApiException::fromResponse(529, '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}');

        $this->assertSame(AnthropicApiException::OVERLOADED, $exception->getErrorType());
        $this->assertTrue($exception->isRetryable());
        $this->assertSame('Claude_ApiErrorOverloaded', $exception->getUserMessage($this->translator()));
    }

    /**
     * @dataProvider getBodiesWithoutJson
     */
    public function test_theErrorType_comesFromTheStatus_whenTheBodyIsNotJson(int $status, string $expectedType): void
    {
        $exception = AnthropicApiException::fromResponse($status, '<html><body>Bad gateway</body></html>');

        $this->assertSame($expectedType, $exception->getErrorType());
        $this->assertSame('API error (HTTP ' . $status . '): <html><body>Bad gateway</body></html>', $exception->getMessage());
    }

    public function getBodiesWithoutJson(): array
    {
        return [
            '401' => [401, AnthropicApiException::AUTHENTICATION],
            '403' => [403, AnthropicApiException::PERMISSION],
            '429' => [429, AnthropicApiException::RATE_LIMIT],
            '500' => [500, AnthropicApiException::API_ERROR],
            '529' => [529, AnthropicApiException::OVERLOADED],
            '418' => [418, AnthropicApiException::API_ERROR],
        ];
    }

    public function test_anEmptyBody_isExplained(): void
    {
        $exception = AnthropicApiException::fromResponse(502, '');

        $this->assertSame('API request failed (HTTP 502) with no response body', $exception->getMessage());
        $this->assertTrue($exception->isRetryable());
    }

    public function test_anOtherError_keepsTheMessageOfTheApi(): void
    {
        $exception = AnthropicApiException::fromResponse(400, '{"type":"error","error":{"type":"invalid_request_error","message":"messages: at least one message is required"}}');

        $this->assertFalse($exception->isRetryable());
        $this->assertSame('messages: at least one message is required', $exception->getUserMessage($this->translator()));
    }

    public function test_anUnknownModel_isAModelError(): void
    {
        $exception = AnthropicApiException::fromResponse(404, '{"type":"error","error":{"type":"not_found_error","message":"model: claude-old"}}');

        $this->assertSame(ModelUpgradeNotice::REASON_UNAVAILABLE, $exception->getReason());
    }

    /**
     * @return callable(string, array<int, string>): string
     */
    private function translator(): callable
    {
        return static function (string $key, array $params): string {
            return $params === [] ? $key : $key . ':' . implode(',', $params);
        };
    }
}
