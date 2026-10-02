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
use Piwik\Plugins\Claude\Settings\DefaultPrompts;
use Piwik\Plugins\Claude\Settings\EffectiveSettings;
use Piwik\Plugins\Claude\Settings\LegacyPrompts;

/**
 * Default prompts: the defaults of previous versions are upgraded, a default is never stored, custom prompts are kept.
 *
 * @group Claude
 * @group ClaudeDefaultPromptsTest
 * @group Plugins
 */
class DefaultPromptsTest extends TestCase
{
    private const NEW_DEFAULT = 'New default prompt';

    public function test_theFirstRelease_hasNoPreviousDefaultPrompts(): void
    {
        $this->assertSame([], LegacyPrompts::DEFAULTS[LegacyPrompts::CHAT]);
        $this->assertSame([], LegacyPrompts::DEFAULTS[LegacyPrompts::INSIGHT]);
        $this->assertFalse(LegacyPrompts::isLegacyDefault(LegacyPrompts::CHAT, 'You are a Matomo expert.'));
    }

    public function test_aCurrentDefault_isNeverAPreviousDefault(): void
    {
        foreach ($this->getCurrentDefaults() as $kind => $prompts) {
            foreach ($prompts as $language => $prompt) {
                $this->assertFalse(LegacyPrompts::isLegacyDefault($kind, $prompt), "$kind $language");
            }
        }
    }

    public function test_everyLanguage_hasItsOwnDefaultPrompts(): void
    {
        $defaults = $this->getCurrentDefaults();

        $this->assertCount(13, $defaults[LegacyPrompts::CHAT]);
        foreach ($defaults as $kind => $prompts) {
            foreach ($prompts as $language => $prompt) {
                $this->assertNotSame('', trim($prompt), "$kind $language");
            }
        }
    }

    /**
     * @dataProvider getKinds
     */
    public function test_aCustomPrompt_isKept(string $kind): void
    {
        $custom = 'You are a Matomo expert. Answer in two sentences.';

        $this->assertSame($custom, DefaultPrompts::resolve($kind, $custom, self::NEW_DEFAULT));
        $this->assertFalse(DefaultPrompts::isDefault($kind, $custom));
        $this->assertSame($custom, DefaultPrompts::toStoredGeneralPrompt($kind, $custom));
        // a prompt starting with a default is a custom prompt
        $extended = $this->getCurrentDefaults()[$kind]['en'] . ' Always answer in French.';
        $this->assertSame($extended, DefaultPrompts::resolve($kind, $extended, self::NEW_DEFAULT));
    }

    /**
     * @dataProvider getKinds
     */
    public function test_anEmptyPrompt_fallsBackToTheDefault(string $kind): void
    {
        $this->assertSame(self::NEW_DEFAULT, DefaultPrompts::resolve($kind, '', self::NEW_DEFAULT));
        $this->assertSame(self::NEW_DEFAULT, DefaultPrompts::resolve($kind, " \n\t ", self::NEW_DEFAULT));
        $this->assertNull(DefaultPrompts::toStoredGeneralPrompt($kind, ''));
    }

    public function test_aCurrentDefault_inAnyLanguage_isNotStored(): void
    {
        foreach ($this->getCurrentDefaults() as $kind => $prompts) {
            foreach ($prompts as $language => $prompt) {
                $this->assertTrue(DefaultPrompts::isDefault($kind, $prompt), "$kind $language");
                $this->assertNull(DefaultPrompts::toStoredGeneralPrompt($kind, $prompt), "$kind $language");
            }
        }
    }

    public function test_aWebsitePrompt_equalToTheGeneralPrompt_isStoredEmpty(): void
    {
        $this->assertSame('', DefaultPrompts::toStoredSitePrompt(LegacyPrompts::CHAT, ' Custom general ', 'Custom general'));
        $this->assertSame('', DefaultPrompts::toStoredSitePrompt(LegacyPrompts::CHAT, '', 'Custom general'));
    }

    public function test_aDefaultWebsitePrompt_isStoredEmpty_whenTheGeneralPromptIsADefault(): void
    {
        $defaults = $this->getCurrentDefaults();

        $this->assertSame('', DefaultPrompts::toStoredSitePrompt(LegacyPrompts::CHAT, $defaults[LegacyPrompts::CHAT]['fr'], $defaults[LegacyPrompts::CHAT]['en']));
        $this->assertSame('', DefaultPrompts::toStoredSitePrompt(LegacyPrompts::INSIGHT, $defaults[LegacyPrompts::INSIGHT]['de'], $defaults[LegacyPrompts::INSIGHT]['en']));
    }

    public function test_aDefaultWebsitePrompt_isKept_whenTheGeneralPromptIsCustom(): void
    {
        $default = $this->getCurrentDefaults()[LegacyPrompts::CHAT]['en'];

        $this->assertSame($default, DefaultPrompts::toStoredSitePrompt(LegacyPrompts::CHAT, $default, 'Custom general'));
        $this->assertSame('Site prompt', DefaultPrompts::toStoredSitePrompt(LegacyPrompts::CHAT, 'Site prompt', 'Custom general'));
    }

    public function test_theEffectivePrompt_ofAWebsite(): void
    {
        $this->assertSame('Site prompt', EffectiveSettings::resolvePrompt(LegacyPrompts::CHAT, 'Site prompt', 'General prompt'));
        $this->assertSame('General prompt', EffectiveSettings::resolvePrompt(LegacyPrompts::CHAT, '', 'General prompt'));
        $this->assertSame('General prompt', EffectiveSettings::resolvePrompt(LegacyPrompts::CHAT, "  \n", 'General prompt'));
    }

    public function getKinds(): array
    {
        return [
            'chat' => [LegacyPrompts::CHAT],
            'insight' => [LegacyPrompts::INSIGHT],
        ];
    }

    /**
     * @return array<string, array<string, string>> kind => language => default prompt
     */
    private function getCurrentDefaults(): array
    {
        $defaults = [];
        foreach (glob(__DIR__ . '/../../lang/*.json') as $file) {
            $translations = json_decode((string) file_get_contents($file), true)['Claude'];
            $language = basename($file, '.json');
            $defaults[LegacyPrompts::CHAT][$language] = $translations['ChatBasePromptDefault'];
            $defaults[LegacyPrompts::INSIGHT][$language] = $translations['InsightBasePromptDefault'];
        }

        return $defaults;
    }
}
