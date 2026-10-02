<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\Claude\Services;

use Exception;
use Piwik\Piwik;
use Piwik\Plugins\Claude\Config;
use Piwik\Plugins\Claude\Settings\EffectiveSettings;
use Piwik\Plugins\Claude\SystemSettings;

/**
 * Host, key and model of a request to the Anthropic Messages API of the plugin settings.
 *
 * The API key is optional on a custom host (proxy or gateway): no x-api-key header is sent without a key. The default
 * Anthropic host always needs a key.
 */
final class ApiConnection
{
    /**
     * @return array{host: string, url: string, apiKey: string, model: string}
     * @throws Exception with a translated message when the settings cannot be used
     */
    public static function fromSettings(EffectiveSettings $settings): array
    {
        $host = trim($settings->getHost());
        $apiKey = trim($settings->getApiKey());
        $model = $settings->getModel();

        if ($host === '') {
            throw new Exception(Piwik::translate('Claude_HostNotConfigured'));
        }

        if ($apiKey === '' && !$settings->isCustomHost()) {
            throw new Exception(Piwik::translate('Claude_ApiKeyNotConfigured'));
        }

        if ($model === '') {
            throw new Exception(Piwik::translate('Claude_ModelNotConfigured'));
        }

        if (!SystemSettings::isHttpsUrl($host)) {
            throw new Exception(Piwik::translate('Claude_InvalidApiUrl'));
        }

        return [
            'host' => $host,
            'url' => Config::getMessagesUrl($host),
            'apiKey' => $apiKey,
            'model' => $model,
        ];
    }

    /**
     * HTTP headers of a request to the Messages API, without x-api-key header when there is no API key
     *
     * @return list<string>
     */
    public static function headers(string $apiKey, string $accept = 'application/json'): array
    {
        $headers = [
            'content-type: application/json',
            'accept: ' . $accept,
            'anthropic-version: ' . Config::ANTHROPIC_VERSION,
        ];

        if ($apiKey !== '') {
            $headers[] = 'x-api-key: ' . $apiKey;
        }

        return $headers;
    }
}
