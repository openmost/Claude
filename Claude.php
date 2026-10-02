<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Claude;

use Piwik\Plugins\Claude\Agent\McpAgent;
use Piwik\Plugins\Claude\Settings\SiteSettingsStorage;

class Claude extends \Piwik\Plugin
{
    public function registerEvents()
    {
        return array(
            'AssetManager.getJavaScriptFiles' => 'getJavaScriptFiles',
            'AssetManager.getStylesheetFiles' => 'getStylesheetFiles',
            'Translate.getClientSideTranslationKeys' => 'getClientSideTranslationKeys',
        );
    }

    public function getClientSideTranslationKeys(&$translationKeys)
    {
        $translationKeys[] = 'Claude_Insights';
        $translationKeys[] = 'Claude_Loading';
        $translationKeys[] = 'Claude_Submit';
        $translationKeys[] = 'Claude_You';
        $translationKeys[] = 'Claude_AI';
        $translationKeys[] = 'Claude_ErrorMessage';
        $translationKeys[] = 'Claude_MessagePlaceholder';
        $translationKeys[] = 'Claude_AskQuestion';
        $translationKeys[] = 'Claude_InvalidResponse';
        $translationKeys[] = 'Claude_AnErrorOccurred';
        $translationKeys[] = 'Claude_NoResponseBody';
        $translationKeys[] = 'Claude_WaitingForResponse';
        $translationKeys[] = 'Claude_SiteSettingsTitle';
        $translationKeys[] = 'Claude_SiteSettingsIntro';
        $translationKeys[] = 'Claude_SiteSettingsGeneralSettings';
        $translationKeys[] = 'General_GeneralSettings';
        $translationKeys[] = 'General_YourChangesHaveBeenSaved';
        $translationKeys[] = 'Claude_AgentToolStep';
        $translationKeys[] = 'Claude_AgentMcpUnavailable';
        $translationKeys[] = 'Claude_AskAdministrator';
        $translationKeys[] = 'Claude_SiteSettingsAiProvidersNotice';
        $translationKeys[] = 'Claude_SystemSettingsMenu';
        $translationKeys[] = 'Claude_SystemSettingsIntro';
        $translationKeys[] = 'Claude_SystemSettingsLink';
        $translationKeys[] = 'Claude_SettingsConnectionTitle';
        $translationKeys[] = 'Claude_SettingsPromptsTitle';
        $translationKeys[] = 'Claude_ResetPromptToDefault';
        $translationKeys[] = 'Claude_ResetPromptToDefaultHelp';
        $translationKeys[] = 'Claude_UseGeneralPrompt';
        $translationKeys[] = 'Claude_UseGeneralPromptHelp';
        $translationKeys[] = 'Claude_SiteSettingsPromptsIntro';
        $translationKeys[] = 'Claude_DeleteApiKey';
        $translationKeys[] = 'Claude_DeleteApiKeyConfirmTitle';
        $translationKeys[] = 'Claude_DeleteApiKeyConfirmText';
        $translationKeys[] = 'Claude_DeleteSiteApiKeyConfirmText';
        $translationKeys[] = 'Claude_DeleteApiKeyDone';
        $translationKeys[] = 'General_Yes';
        $translationKeys[] = 'General_No';
        $translationKeys[] = 'Claude_CloseInsights';
        $translationKeys[] = 'Claude_CopyAnswer';
        $translationKeys[] = 'Claude_AnswerCopied';
        $translationKeys[] = 'Claude_ScrollToLatest';
        $translationKeys[] = 'Claude_ComposerHint';
        $translationKeys[] = 'Claude_AnswerAnnouncement';
        $translationKeys[] = 'Claude_AgentStepsSummary';
        $translationKeys[] = 'Claude_AgentStepsFailed';
        $translationKeys[] = 'Claude_AgentStepRunning';
        $translationKeys[] = 'Claude_AgentStepDone';
        $translationKeys[] = 'Claude_AgentStepError';
        $translationKeys[] = 'Claude_EmptyStateTitle';
        $translationKeys[] = 'Claude_EmptyStateText';
        $translationKeys[] = 'Claude_SuggestionsLabel';
        $translationKeys[] = 'Claude_SuggestionWeeklyKpis';
        $translationKeys[] = 'Claude_SuggestionTopPages';
        $translationKeys[] = 'Claude_SuggestionTrafficSources';
        $translationKeys[] = 'Claude_SuggestionGoals';
        $translationKeys[] = 'Claude_NewConversation';
        $translationKeys[] = 'Claude_ScrollableTable';
        $translationKeys[] = 'Claude_ScrollableCode';
        $translationKeys[] = 'Claude_CopyCode';
        $translationKeys[] = 'Claude_CodeCopied';
        foreach (['ActivateAiProviders', 'ConnectProvider', 'InstallMcpServer', 'ActivateMcpServer', 'EnableMcp', 'EnableWriteMode'] as $step) {
            $translationKeys[] = 'Claude_Recommend' . $step;
            $translationKeys[] = 'Claude_Recommend' . $step . 'Action';
        }
    }

    public function getJavaScriptFiles(&$files)
    {
        if ($this->pluginIsConfigured()) {
            $files[] = "plugins/Claude/assets/js/app.js";
        }
    }

    public function getStylesheetFiles(&$files)
    {
        if ($this->pluginIsConfigured()) {
            $files[] = "plugins/Claude/assets/css/app.css";
        }
    }

    private function pluginIsConfigured(): bool
    {
        // a custom host may have no key: general settings, AI Providers or the key or custom host of a website
        return $this->chatIsConfigured() || McpAgent::isAvailable() || SiteSettingsStorage::hasAnySiteConnection();
    }

    private function chatIsConfigured(): bool
    {
        try {
            $settings = new SystemSettings();

            // Check if settings properties exist and are properly initialized
            if (!isset($settings->host) || $settings->host === null) {
                return false;
            }
            if (!isset($settings->apiKey) || $settings->apiKey === null) {
                return false;
            }

            $host = trim((string) $settings->host->getValue());
            $apiKey = trim((string) $settings->apiKey->getValue());

            return $host !== '' && ($apiKey !== '' || !Config::isDefaultHost($host));
        } catch (\Throwable $e) {
            // Catch any error during plugin installation/initialization
            return false;
        }
    }
}
