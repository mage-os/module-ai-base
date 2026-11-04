<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ModelList;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\ModelGuidanceInterface;
use MageOS\AiBase\Api\Data\ModelTier;
use MageOS\AiBase\Api\ModelGuideProviderInterface;

/**
 * Turns a provider's guidance into what the admin form's model picker renders.
 *
 * Two shapes come out of it: the tier cards an administrator picks from, and the option rows of the
 * full model list behind them, each tagged with its tier so picking a specific model still lights
 * up the matching card. One place for both paths that fill the model field, the page render and
 * Refresh Models; with the tiers only on the first, a refresh would have left every card dark until
 * the next reload.
 *
 * @phpstan-type TierCard array{
 *     tier: string,
 *     title: string,
 *     description: string,
 *     model: string,
 *     modelLabel: string,
 *     recommended: bool
 * }
 */
class ModelOptions
{
    /**
     * Option key holding the tier a model belongs to.
     */
    public const KEY_TIER = 'tier';

    /**
     * Build the option rows for a provider's model list.
     *
     * An option gets a tier and, when recommended, a marked label only if the provider implements
     * {@see ModelGuideProviderInterface} and knows that model; every other option keeps the plain
     * `{value, label}` shape.
     *
     * @param AiServiceConfigurationInterface $service
     * @param array<string,string> $models Map of model value => label
     * @return list<array{value:string,label:string,tier?:string}>
     */
    public function build(AiServiceConfigurationInterface $service, array $models): array
    {
        return array_map(
            fn (string|int $value, string $label): array => $this->buildOption(
                (string) $value,
                $label,
                $this->getGuidance($service, (string) $value),
            ),
            array_keys($models),
            array_values($models),
        );
    }

    /**
     * The tier cards shown above the model list, in tier order; empty for a provider without guidance.
     *
     * @param AiServiceConfigurationInterface $service
     * @return list<TierCard>
     */
    public function buildTierCards(AiServiceConfigurationInterface $service): array
    {
        if (!$service instanceof ModelGuideProviderInterface) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (ModelTier $tier): ?array => $this->buildTierCard($service, $tier),
            ModelTier::cases(),
        )));
    }

    /**
     * The model a new row starts on: the recommended tier model, or null to keep the list's first.
     *
     * @param AiServiceConfigurationInterface $service
     * @return string|null
     */
    public function getDefaultModel(AiServiceConfigurationInterface $service): ?string
    {
        $recommended = array_filter(
            $this->buildTierCards($service),
            static fn (array $card): bool => $card['recommended'],
        );

        return $recommended === [] ? null : reset($recommended)['model'];
    }

    /**
     * One tier card, or null when the provider offers no model for that tier.
     *
     * @param AiServiceConfigurationInterface&ModelGuideProviderInterface $service
     * @param ModelTier $tier
     * @return TierCard|null
     */
    private function buildTierCard(
        ModelGuideProviderInterface&AiServiceConfigurationInterface $service,
        ModelTier $tier
    ): ?array {
        $model = $service->getTierModel($tier);
        if ($model === null) {
            return null;
        }

        return [
            'tier' => $tier->value,
            'title' => $this->describeTitle($tier),
            'description' => $this->describeTier($tier),
            'model' => $model,
            'modelLabel' => $service->getSupportedModels()[$model] ?? $model,
            'recommended' => $service->getModelGuidance($model)?->isRecommended() === true,
        ];
    }

    /**
     * The provider's guidance for one model, or null when it gives none.
     *
     * @param AiServiceConfigurationInterface $service
     * @param string $model
     * @return ModelGuidanceInterface|null
     */
    private function getGuidance(AiServiceConfigurationInterface $service, string $model): ?ModelGuidanceInterface
    {
        return $service instanceof ModelGuideProviderInterface ? $service->getModelGuidance($model) : null;
    }

    /**
     * One option row, tagged with its tier when there is guidance.
     *
     * @param string $value
     * @param string $label
     * @param ModelGuidanceInterface|null $guidance
     * @return array{value:string,label:string,tier?:string}
     */
    private function buildOption(string $value, string $label, ?ModelGuidanceInterface $guidance): array
    {
        if ($guidance === null) {
            return ['value' => $value, 'label' => $label];
        }

        return [
            'value' => $value,
            'label' => $guidance->isRecommended() ? (string) __('%1 (recommended)', $label) : $label,
            self::KEY_TIER => $guidance->getTier()->value,
        ];
    }

    /**
     * The name on a tier's card.
     *
     * @param ModelTier $tier
     * @return string
     */
    private function describeTitle(ModelTier $tier): string
    {
        return (string) match ($tier) {
            ModelTier::Fast => __('Fast'),
            ModelTier::Balanced => __('Balanced'),
            ModelTier::MostCapable => __('Most capable'),
        };
    }

    /**
     * The sentence on a tier's card.
     *
     * The same wording for every provider, so "Fast" means the same thing whichever backend a row
     * configures. The match has no default arm on purpose: a new tier fails PHPStan here until it
     * has a sentence, instead of showing up in the form without one.
     *
     * @param ModelTier $tier
     * @return string
     */
    private function describeTier(ModelTier $tier): string
    {
        return (string) match ($tier) {
            ModelTier::Fast => __('Quick replies for simple jobs, like short texts and labels.'),
            ModelTier::Balanced => __('Good quality at a reasonable speed. Suits most tasks.'),
            ModelTier::MostCapable => __('The best results on complex tasks, but slower.'),
        };
    }
}
