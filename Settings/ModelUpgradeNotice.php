<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Claude\Settings;

use Piwik\Piwik;
use Piwik\Plugins\Claude\Config;
use Piwik\Url;

/**
 * Message asking to switch to another model, displayed in the chat instead of the raw API error when the model is
 * deprecated, retired or not available to the Anthropic account, with a link to the settings the user can change.
 */
final class ModelUpgradeNotice
{
    public const REASON_OUTDATED = 'outdated';
    public const REASON_UNAVAILABLE = 'unavailable';

    /**
     * Finds whether an API error is caused by the model.
     *
     * @return string|null one of the REASON_* constants, null when the error is not related to the model
     */
    public static function classifyApiError(int $httpCode, string $body): ?string
    {
        $error = json_decode($body, true);
        if (!is_array($error)) {
            $error = [];
        } elseif (isset($error['error']) && is_array($error['error'])) {
            $error = $error['error'];
        }

        $type = is_string($error['type'] ?? null) ? $error['type'] : '';
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';

        // Anthropic answers an unknown model with a not_found_error whose message starts with "model:"
        if (
            ($type === 'not_found_error' && preg_match('/\bmodel\b/i', $message))
            || (in_array($httpCode, [400, 404], true) && preg_match('/^model:|invalid model|\bmodel\b.*\b(not found|does not exist|deprecated|retired|not supported)/i', $message))
        ) {
            return self::REASON_UNAVAILABLE;
        }

        return null;
    }

    /**
     * @return array{message: string, settingsUrl: string, settingsLabel: string}|null null when the model is not
     *                                                                                  deprecated
     */
    public static function forOutdatedModel(EffectiveSettings $settings): ?array
    {
        if (!Config::isDeprecatedModel($settings->getModel())) {
            return null;
        }

        return self::build(self::REASON_OUTDATED, $settings);
    }

    /**
     * @return array{message: string, settingsUrl: string, settingsLabel: string}
     */
    public static function build(string $reason, EffectiveSettings $settings): array
    {
        $translationKey = $reason === self::REASON_OUTDATED ? 'Claude_ModelOutdated' : 'Claude_ModelUnavailable';
        $message = Piwik::translate($translationKey, [$settings->getModel(), Config::getModelLabel(Config::RECOMMENDED_MODEL)]);

        $settingsUrl = self::getSettingsUrl($settings);
        if ($settingsUrl === '') {
            $message .= ' ' . Piwik::translate('Claude_AskAdministratorToChangeModel');
        }

        return [
            'message' => $message,
            'settingsUrl' => $settingsUrl,
            'settingsLabel' => $settingsUrl === '' ? '' : Piwik::translate('Claude_ChangeModelInSettings'),
        ];
    }

    /**
     * Settings page where the current user can change the model of the website, empty when the user cannot.
     */
    public static function getSettingsUrl(EffectiveSettings $settings): string
    {
        $idSite = $settings->getIdSite();
        $params = ['idSite' => $idSite, 'period' => 'day', 'date' => 'yesterday'];

        if ($settings->getModelSource() === EffectiveSettings::SOURCE_SYSTEM && Piwik::hasUserSuperUserAccess()) {
            return SystemSettingsForm::getUrl($params);
        }

        // a website admin can override the model of the general settings for the website
        if ($idSite > 0 && Piwik::isUserHasAdminAccess($idSite)) {
            return 'index.php?' . Url::getQueryStringFromParameters(['module' => SiteSettingsStorage::PLUGIN_NAME, 'action' => 'manage'] + $params);
        }

        return '';
    }
}
