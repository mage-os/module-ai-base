<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\ModelList;

require_once __DIR__ . '/../../Stubs/FakeAiServiceConfiguration.php';
require_once __DIR__ . '/../../Stubs/FakeGuidedAiService.php';

use MageOS\AiBase\Api\Data\ModelTier;
use MageOS\AiBase\Model\ModelGuidance;
use MageOS\AiBase\Model\ModelList\ModelOptions;
use MageOS\AiBase\Test\Unit\Stubs\FakeAiServiceConfiguration;
use MageOS\AiBase\Test\Unit\Stubs\FakeGuidedAiService;
use PHPUnit\Framework\TestCase;

final class ModelOptionsTest extends TestCase
{
    public function test_a_provider_without_guidance_keeps_plain_options(): void
    {
        $service = new FakeAiServiceConfiguration('ollama');

        $options = (new ModelOptions())->build($service, ['llama3' => 'Llama 3']);

        self::assertSame([['value' => 'llama3', 'label' => 'Llama 3']], $options);
    }

    public function test_a_guided_model_is_tagged_with_its_tier(): void
    {
        $service = new FakeGuidedAiService('anthropic', [
            'claude-haiku-5-5' => new ModelGuidance(ModelTier::Fast),
        ]);

        $options = (new ModelOptions())->build($service, ['claude-haiku-5-5' => 'Claude Haiku 5.5']);

        self::assertSame(
            [['value' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5', 'tier' => 'fast']],
            $options
        );
    }

    public function test_the_recommended_model_says_so_in_its_label(): void
    {
        $service = new FakeGuidedAiService('anthropic', [
            'claude-sonnet-5-5' => new ModelGuidance(ModelTier::Balanced, isRecommended: true),
        ]);

        $options = (new ModelOptions())->build($service, ['claude-sonnet-5-5' => 'Claude Sonnet 5.5']);

        self::assertSame('Claude Sonnet 5.5 (recommended)', $options[0]['label']);
    }

    public function test_a_model_the_provider_does_not_know_stays_untagged(): void
    {
        $service = new FakeGuidedAiService('anthropic', [
            'claude-opus-5-5' => new ModelGuidance(ModelTier::MostCapable),
        ]);

        $options = (new ModelOptions())->build($service, [
            'claude-opus-5-5' => 'Claude Opus 5.5',
            'something-new' => 'Something new',
        ]);

        self::assertSame(['value' => 'something-new', 'label' => 'Something new'], $options[1]);
    }

    public function test_a_provider_without_guidance_gets_no_tier_cards(): void
    {
        $service = new FakeAiServiceConfiguration('ollama');

        $cards = (new ModelOptions())->buildTierCards($service);

        self::assertSame([], $cards);
    }

    public function test_each_tier_card_names_its_model_and_says_whether_it_is_recommended(): void
    {
        $service = $this->anthropicLike();

        $cards = (new ModelOptions())->buildTierCards($service);

        self::assertSame(
            [
                ['fast', 'claude-haiku-5-5', 'Claude Haiku 5.5', false],
                ['balanced', 'claude-sonnet-5-5', 'Claude Sonnet 5.5', true],
                ['most-capable', 'claude-opus-5-5', 'Claude Opus 5.5', false],
            ],
            array_map(
                static fn (array $card): array => [$card['tier'], $card['model'], $card['modelLabel'], $card['recommended']],
                $cards
            )
        );
    }

    public function test_every_tier_card_has_a_title_and_a_description(): void
    {
        $service = $this->anthropicLike();

        $cards = (new ModelOptions())->buildTierCards($service);

        self::assertSame(
            [],
            array_filter($cards, static fn (array $card): bool => $card['title'] === '' || $card['description'] === '')
        );
    }

    public function test_a_tier_without_a_model_gets_no_card(): void
    {
        $service = new FakeGuidedAiService('deepseek', [], ['balanced' => 'deepseek-chat']);

        $cards = (new ModelOptions())->buildTierCards($service);

        self::assertSame(['balanced'], array_column($cards, 'tier'));
    }

    public function test_a_new_row_starts_on_the_recommended_model(): void
    {
        $service = $this->anthropicLike();

        $default = (new ModelOptions())->getDefaultModel($service);

        self::assertSame('claude-sonnet-5-5', $default);
    }

    public function test_without_a_recommendation_a_new_row_keeps_the_lists_first_model(): void
    {
        $service = new FakeAiServiceConfiguration('ollama');

        $default = (new ModelOptions())->getDefaultModel($service);

        self::assertNull($default);
    }

    private function anthropicLike(): FakeGuidedAiService
    {
        return new FakeGuidedAiService(
            'anthropic',
            [
                'claude-haiku-5-5' => new ModelGuidance(ModelTier::Fast),
                'claude-sonnet-5-5' => new ModelGuidance(ModelTier::Balanced, isRecommended: true),
                'claude-opus-5-5' => new ModelGuidance(ModelTier::MostCapable),
            ],
            [
                'fast' => 'claude-haiku-5-5',
                'balanced' => 'claude-sonnet-5-5',
                'most-capable' => 'claude-opus-5-5',
            ],
            [
                'claude-haiku-5-5' => 'Claude Haiku 5.5',
                'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
                'claude-opus-5-5' => 'Claude Opus 5.5',
            ],
        );
    }
}
