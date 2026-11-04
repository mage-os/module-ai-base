<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\AiServices;

use MageOS\AiBase\AiServices\Anthropic;
use MageOS\AiBase\Api\Data\ModelTier;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Test\Unit\Stubs\FakeFieldDescriptorFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog;

final class AnthropicTest extends TestCase
{
    public function test_every_curated_model_is_known_to_the_symfony_bridge(): void
    {
        $service = new Anthropic(new FakeFieldDescriptorFactory(), $this->fetcher());

        $unknown = array_diff(
            array_keys($service->getSupportedModels()),
            array_keys((new ModelCatalog())->getModels())
        );

        self::assertSame([], array_values($unknown));
    }

    public function test_the_current_claude_generation_is_offered(): void
    {
        $service = new Anthropic(new FakeFieldDescriptorFactory(), $this->fetcher());

        $models = $service->getSupportedModels();

        foreach (['claude-fable-5-1', 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'] as $model) {
            self::assertArrayHasKey($model, $models);
        }
    }

    #[DataProvider('model_tiers')]
    public function test_each_claude_family_gets_its_tier(string $model, ModelTier $expected): void
    {
        $service = new Anthropic(new FakeFieldDescriptorFactory(), $this->fetcher());

        $guidance = $service->getModelGuidance($model);

        self::assertSame($expected, $guidance?->getTier());
    }

    /**
     * @return array<string, array{0: string, 1: ModelTier}>
     */
    public static function model_tiers(): array
    {
        return [
            'haiku'                      => ['claude-haiku-5-5', ModelTier::Fast],
            'sonnet'                     => ['claude-sonnet-5-5', ModelTier::Balanced],
            'opus'                       => ['claude-opus-5-5', ModelTier::MostCapable],
            'fable'                      => ['claude-fable-5-1', ModelTier::MostCapable],
            'dated snapshot from refresh' => ['claude-haiku-4-5-20251001', ModelTier::Fast],
            'family not curated yet'     => ['claude-sonnet-6', ModelTier::Balanced],
        ];
    }

    public function test_only_the_current_sonnet_is_recommended(): void
    {
        $service = new Anthropic(new FakeFieldDescriptorFactory(), $this->fetcher());

        $recommended = array_keys(array_filter(
            $service->getSupportedModels(),
            static fn (string $label, string $model): bool => $service->getModelGuidance($model)?->isRecommended() === true,
            ARRAY_FILTER_USE_BOTH,
        ));

        self::assertSame(['claude-sonnet-5-5'], $recommended);
    }

    public function test_the_tier_cards_use_the_current_haiku_sonnet_and_opus(): void
    {
        $service = new Anthropic(new FakeFieldDescriptorFactory(), $this->fetcher());

        $tierModels = array_map(
            static fn (ModelTier $tier): ?string => $service->getTierModel($tier),
            [ModelTier::Fast, ModelTier::Balanced, ModelTier::MostCapable],
        );

        self::assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'], $tierModels);
    }

    public function test_each_tier_model_is_classified_as_its_own_tier(): void
    {
        $service = new Anthropic(new FakeFieldDescriptorFactory(), $this->fetcher());

        $mismatched = array_filter(
            ModelTier::cases(),
            static fn (ModelTier $tier): bool => $service->getModelGuidance((string) $service->getTierModel($tier))
                ?->getTier() !== $tier,
        );

        self::assertSame([], $mismatched);
    }

    public function test_an_unknown_model_gets_no_guidance_instead_of_a_guess(): void
    {
        $service = new Anthropic(new FakeFieldDescriptorFactory(), $this->fetcher());

        $guidance = $service->getModelGuidance('claude-instant-1');

        self::assertNull($guidance);
    }

    public function test_every_curated_model_has_guidance(): void
    {
        $service = new Anthropic(new FakeFieldDescriptorFactory(), $this->fetcher());

        $unguided = array_filter(
            array_keys($service->getSupportedModels()),
            static fn (string $model): bool => $service->getModelGuidance($model) === null,
        );

        self::assertSame([], array_values($unguided));
    }

    private function fetcher(): JsonFetcherInterface
    {
        return new class implements JsonFetcherInterface {
            public function getJson(string $url, #[\SensitiveParameter] array $headers = []): array
            {
                return [];
            }
        };
    }
}
