<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\Claude\Agent;

use Piwik\Piwik;
use Piwik\Plugins\Claude\Settings\ModelUpgradeNotice;

/**
 * Failed request to the Anthropic Messages API, with the error type of the API and the retry-after delay
 */
class AnthropicApiException extends \RuntimeException
{
    public const AUTHENTICATION = 'authentication_error';
    public const PERMISSION = 'permission_error';
    public const NOT_FOUND = 'not_found_error';
    public const RATE_LIMIT = 'rate_limit_error';
    public const OVERLOADED = 'overloaded_error';
    public const API_ERROR = 'api_error';
    public const CONNECTION = 'connection_error';

    private const TYPES_BY_STATUS = [
        401 => self::AUTHENTICATION,
        403 => self::PERMISSION,
        404 => self::NOT_FOUND,
        429 => self::RATE_LIMIT,
        500 => self::API_ERROR,
        529 => self::OVERLOADED,
    ];

    /** @var int */
    private $httpCode;

    /** @var string */
    private $errorType;

    /** @var int|null */
    private $retryAfter;

    /** @var string|null */
    private $reason;

    /**
     * @param string|null $reason a ModelUpgradeNotice::REASON_* constant when the model causes the error
     */
    public function __construct(string $message, int $httpCode = 0, string $errorType = self::API_ERROR, ?int $retryAfter = null, ?string $reason = null)
    {
        parent::__construct($message, $httpCode);
        $this->httpCode = $httpCode;
        $this->errorType = $errorType;
        $this->retryAfter = $retryAfter;
        $this->reason = $reason;
    }

    /**
     * @param array<string, string> $headers response headers, names in lowercase
     */
    public static function fromResponse(int $httpCode, string $body, array $headers = []): self
    {
        $decoded = json_decode(trim($body), true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

        $errorType = is_string($error['type'] ?? null) && $error['type'] !== ''
            ? $error['type']
            : (self::TYPES_BY_STATUS[$httpCode] ?? self::API_ERROR);

        if (is_string($error['message'] ?? null) && $error['message'] !== '') {
            $message = $error['message'];
        } else {
            $snippet = trim((string) preg_replace('/\s+/', ' ', $body));
            $message = $snippet === ''
                ? 'API request failed (HTTP ' . $httpCode . ') with no response body'
                : 'API error (HTTP ' . $httpCode . '): ' . mb_substr($snippet, 0, 300);
        }

        $retryAfter = null;
        $header = trim($headers['retry-after'] ?? '');
        if ($header !== '' && is_numeric($header)) {
            $retryAfter = max(0, (int) ceil((float) $header));
        }

        return new self($message, $httpCode, $errorType, $retryAfter, ModelUpgradeNotice::classifyApiError($httpCode, $body));
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    /**
     * Seconds to wait before retrying, from the retry-after header
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Whether the same request may succeed later: rate limits, overload and server errors
     */
    public function isRetryable(): bool
    {
        return in_array($this->errorType, [self::RATE_LIMIT, self::OVERLOADED, self::API_ERROR], true)
            || in_array($this->httpCode, [429, 500, 502, 503, 504, 529], true);
    }

    /**
     * Message shown in the chat: the frequent errors are explained in the language of the user, the others keep the
     * message of the API
     *
     * @param callable(string, array<int, string>): string|null $translate
     */
    public function getUserMessage(?callable $translate = null): string
    {
        $translate = $translate ?? static function (string $key, array $params = []): string {
            return Piwik::translate($key, $params);
        };

        switch ($this->errorType) {
            case self::AUTHENTICATION:
                return $translate('Claude_ApiErrorAuthentication', []);
            case self::RATE_LIMIT:
                return $this->retryAfter !== null
                    ? $translate('Claude_ApiErrorRateLimit', [(string) $this->retryAfter])
                    : $translate('Claude_ApiErrorRateLimitNoDelay', []);
            case self::OVERLOADED:
                return $translate('Claude_ApiErrorOverloaded', []);
            default:
                return $this->getMessage();
        }
    }
}
