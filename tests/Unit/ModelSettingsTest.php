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
use Piwik\Plugins\Claude\Config;
use Piwik\Plugins\Claude\Settings\ModelUpgradeNotice;
use Piwik\Plugins\Claude\Settings\SingleValue;
use Piwik\Plugins\Claude\Settings\SiteSettingsStorage;

/**
 * @group Claude
 * @group ModelSettingsTest
 * @group Plugins
 */
class ModelSettingsTest extends TestCase
{
    public function testLatestRecommendedResolvesToTheRecommendedModel(): void
    {
        $this->assertSame('claude-sonnet-5-5', Config::RECOMMENDED_MODEL);
        $this->assertSame(Config::RECOMMENDED_MODEL, Config::resolveModel(Config::LATEST_RECOMMENDED_MODEL));
        $this->assertSame(Config::RECOMMENDED_MODEL, Config::resolveModel(''));
        $this->assertSame('claude-opus-5-5', Config::resolveModel(' claude-opus-5-5 '));
        $this->assertSame(Config::LATEST_RECOMMENDED_MODEL, Config::DEFAULT_MODEL);
    }

    public function testRecommendedModelIsListedAndNotDeprecated(): void
    {
        $this->assertArrayHasKey(Config::RECOMMENDED_MODEL, Config::getAvailableModels());
        $this->assertTrue(Config::isAvailableModel(Config::LATEST_RECOMMENDED_MODEL));

        foreach (array_keys(Config::getAvailableModels()) as $model) {
            $this->assertFalse(Config::isDeprecatedModel($model), $model);
        }
    }

    public function testListsTheCurrentClaudeModels(): void
    {
        $models = Config::getAvailableModels();

        $this->assertSame('Claude Sonnet 5.5', $models['claude-sonnet-5-5']);
        $this->assertSame('Claude Opus 5.5', $models['claude-opus-5-5']);
        $this->assertSame('Claude Fable 5.1', $models['claude-fable-5-1']);
        $this->assertSame('Claude Haiku 4.5', $models['claude-haiku-4-5-20251001']);
        $this->assertSame(['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-fable-5-1', 'claude-haiku-4-5-20251001'], array_slice(array_keys($models), 0, 4));
    }

    public function testRetiredModelsAreDeprecated(): void
    {
        $this->assertTrue(Config::isDeprecatedModel('claude-3-5-sonnet-20241022'));
        $this->assertTrue(Config::isDeprecatedModel(' Claude-3-Opus-20240229 '));
        $this->assertTrue(Config::isDeprecatedModel('claude-sonnet-4-5-20250929'));
        $this->assertFalse(Config::isAvailableModel('claude-3-haiku-20240307'));
    }

    public function testReadsSiteValuesSavedByTheWebsiteForm(): void
    {
        $values = SiteSettingsStorage::fromStoredValues([
            'host' => '',
            'apiKey' => 'key',
            'modelPreset' => ['claude-haiku-4-5-20251001'],
            'modelCustom' => '',
            'chatBasePrompt' => 'Chat',
        ]);

        $this->assertSame('claude-haiku-4-5-20251001', $values['modelPreset']);
        $this->assertSame('', $values['modelCustom']);
        $this->assertSame('key', $values['apiKey']);
        $this->assertSame('Chat', $values['chatBasePrompt']);
        $this->assertSame('', $values['insightBasePrompt']);

        // an empty preset means the general settings apply
        $this->assertSame('', SiteSettingsStorage::fromStoredValues(['modelPreset' => ['']])['modelPreset']);
        $this->assertSame('', SiteSettingsStorage::fromStoredValues([])['modelPreset']);
    }

    public function testHostsAndMessagesUrl(): void
    {
        $this->assertTrue(Config::isDefaultHost('https://api.anthropic.com'));
        $this->assertTrue(Config::isDefaultHost(' https://API.anthropic.com/ '));
        $this->assertTrue(Config::isDefaultHost('https://api.anthropic.com/v1/messages'));
        $this->assertFalse(Config::isDefaultHost('https://gateway.example.com'));

        $this->assertSame('https://api.anthropic.com/v1/messages', Config::getMessagesUrl('https://api.anthropic.com'));
        $this->assertSame('https://api.anthropic.com/v1/messages', Config::getMessagesUrl('https://api.anthropic.com/'));
        $this->assertSame('https://gateway.example.com/anthropic/v1/messages', Config::getMessagesUrl('https://gateway.example.com/anthropic'));
        $this->assertSame('https://gateway.example.com/v1/messages', Config::getMessagesUrl('https://gateway.example.com/v1/messages'));
    }

    public function testUnwrap(): void
    {
        $this->assertSame('a', SingleValue::toString(['a']));
        $this->assertSame('a', SingleValue::toString(' a '));
        $this->assertSame('', SingleValue::toString([]));
        $this->assertSame('', SingleValue::toString(null));
    }

    public function testClassifiesModelErrors(): void
    {
        $this->assertSame(ModelUpgradeNotice::REASON_UNAVAILABLE, ModelUpgradeNotice::classifyApiError(
            404,
            '{"type":"error","error":{"type":"not_found_error","message":"model: claude-3-opus-20240229"},"request_id":"req_1"}'
        ));
        $this->assertSame(ModelUpgradeNotice::REASON_UNAVAILABLE, ModelUpgradeNotice::classifyApiError(
            400,
            '{"type":"error","error":{"type":"invalid_request_error","message":"invalid model: my-model"}}'
        ));

        $this->assertNull(ModelUpgradeNotice::classifyApiError(
            404,
            '{"type":"error","error":{"type":"not_found_error","message":"The requested resource could not be found."}}'
        ));
        $this->assertNull(ModelUpgradeNotice::classifyApiError(
            429,
            '{"type":"error","error":{"type":"rate_limit_error","message":"Number of request tokens has exceeded your per-minute rate limit"}}'
        ));
        $this->assertNull(ModelUpgradeNotice::classifyApiError(
            401,
            '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}'
        ));
    }
}
