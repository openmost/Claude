<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Claude;

/**
 * Configuration constants and static data for Claude plugin.
 * This class has no dependencies to avoid circular loading issues.
 */
class Config
{
    /**
     * Base URL of the Anthropic API: the requests are sent to {host}/v1/messages
     */
    public const DEFAULT_HOST = 'https://api.anthropic.com';

    public const MESSAGES_PATH = '/v1/messages';

    public const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Whether a host is the API of the provider, which always needs a key. Any other host is a custom host
     * (proxy or gateway serving the Messages API), where the key is optional.
     */
    public static function isDefaultHost(string $host): bool
    {
        return self::normalizeHost($host) === self::DEFAULT_HOST;
    }

    /**
     * The host without trailing slash nor Messages API path, so "https://api.anthropic.com/v1/messages" and
     * "https://api.anthropic.com/" are the same host
     */
    public static function normalizeHost(string $host): string
    {
        $host = rtrim(strtolower(trim($host)), '/');
        if (substr($host, -strlen(self::MESSAGES_PATH)) === self::MESSAGES_PATH) {
            $host = rtrim(substr($host, 0, -strlen(self::MESSAGES_PATH)), '/');
        }

        return $host;
    }

    /**
     * URL of the Messages API of a host. A host already ending with the Messages API path is used as is.
     */
    public static function getMessagesUrl(string $host): string
    {
        $host = rtrim(trim($host), '/');
        if (substr(strtolower($host), -strlen(self::MESSAGES_PATH)) === self::MESSAGES_PATH) {
            return $host;
        }

        return $host . self::MESSAGES_PATH;
    }

    /**
     * Model option resolved to RECOMMENDED_MODEL when a request is sent, so the installs using it follow the
     * recommendation of each plugin release without changing their settings.
     */
    public const LATEST_RECOMMENDED_MODEL = 'latest-recommended';

    // the best balance of speed, cost and quality for a conversational assistant
    public const RECOMMENDED_MODEL = 'claude-sonnet-5-5';

    public const DEFAULT_MODEL = self::LATEST_RECOMMENDED_MODEL;

    /**
     * Returns the list of available preset models: the current models first, then the previous models Anthropic still
     * serves.
     */
    public static function getAvailableModels(): array
    {
        return [
            'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
            'claude-opus-5-5' => 'Claude Opus 5.5',
            'claude-fable-5-1' => 'Claude Fable 5.1',
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',

            'claude-opus-5' => 'Claude Opus 5',
            'claude-sonnet-5' => 'Claude Sonnet 5',
            'claude-fable-5' => 'Claude Fable 5',
            'claude-opus-4-8' => 'Claude Opus 4.8',
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
        ];
    }

    /**
     * Models deprecated or retired by Anthropic, or removed from the preset list of a previous plugin version.
     *
     * @return string[]
     */
    public static function getDeprecatedModels(): array
    {
        return [
            'claude-sonnet-4-5-20250929',
            'claude-sonnet-4-5',
            'claude-opus-4-1-20250805',
            'claude-opus-4-1',
            'claude-opus-4-20250514',
            'claude-opus-4-0',
            'claude-sonnet-4-20250514',
            'claude-sonnet-4-0',
            'claude-3-7-sonnet-20250219',
            'claude-3-7-sonnet-latest',
            'claude-3-5-sonnet-20241022',
            'claude-3-5-sonnet-20240620',
            'claude-3-5-sonnet-latest',
            'claude-3-5-haiku-20241022',
            'claude-3-5-haiku-latest',
            'claude-3-opus-20240229',
            'claude-3-opus-latest',
            'claude-3-sonnet-20240229',
            'claude-3-haiku-20240307',
            'claude-2.1',
            'claude-2.0',
            'claude-instant-1.2',
        ];
    }

    public static function isAvailableModel(string $model): bool
    {
        return $model === self::LATEST_RECOMMENDED_MODEL || array_key_exists($model, self::getAvailableModels());
    }

    public static function isDeprecatedModel(string $model): bool
    {
        return in_array(strtolower(trim($model)), self::getDeprecatedModels(), true);
    }

    /**
     * Model sent to the API for a configured value.
     */
    public static function resolveModel(string $model): string
    {
        $model = trim($model);

        return ($model === '' || $model === self::LATEST_RECOMMENDED_MODEL) ? self::RECOMMENDED_MODEL : $model;
    }

    public static function getModelLabel(string $model): string
    {
        return self::getAvailableModels()[$model] ?? $model;
    }
}
