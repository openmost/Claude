<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Claude\Settings;

use Piwik\Piwik;

/**
 * Default prompts of previous plugin versions.
 *
 * A default prompt is never stored (see DefaultPrompts), so a rewrite of the defaults reaches every install. A stored
 * prompt equal to a listed previous default, in any language, is replaced by the new default in the language of the
 * current user. A custom prompt is never changed, nothing is written to the database.
 */
final class LegacyPrompts
{
    public const CHAT = 'chat';
    public const INSIGHT = 'insight';

    public const TRANSLATION_KEYS = [
        self::CHAT => 'Claude_ChatBasePromptDefault',
        self::INSIGHT => 'Claude_InsightBasePromptDefault',
    ];

    /**
     * Previous default prompts, by prompt and language, trimmed. The first release has none: when a later release
     * rewrites a default prompt, the replaced prompt is listed here so the installs that saved it get the new one.
     */
    public const DEFAULTS = [
        self::CHAT => [],
        self::INSIGHT => [],
    ];

    public static function isLegacyDefault(string $kind, string $prompt): bool
    {
        $prompt = trim($prompt);
        foreach (self::DEFAULTS[$kind] ?? [] as $legacyPrompts) {
            if (in_array($prompt, $legacyPrompts, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The default replaces an empty prompt and a default prompt of a previous version, a custom prompt is kept.
     */
    public static function resolve(string $kind, string $prompt, string $default): string
    {
        if (trim($prompt) === '' || self::isLegacyDefault($kind, $prompt)) {
            return $default;
        }

        return $prompt;
    }

    /**
     * Current default prompt, in the language of the current user
     */
    public static function getDefault(string $kind): string
    {
        return Piwik::translate(self::TRANSLATION_KEYS[$kind]);
    }
}
