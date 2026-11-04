<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\Data\ModelGuidanceInterface;
use MageOS\AiBase\Api\Data\ModelTier;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Api\ModelGuideProviderInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\ModelGuidance;

class Anthropic extends AbstractAiService implements ModelListProviderInterface, ModelGuideProviderInterface
{
    use ModelListTrait;

    /**
     * Anthropic model listing endpoint.
     */
    private const MODELS_URL = 'https://api.anthropic.com/v1/models';

    /**
     * API version header value required by the Anthropic API.
     */
    private const API_VERSION = '2023-06-01';

    /**
     * Tier per model family, matched against the model id.
     *
     * Keyed on the family rather than on full ids, so a model that only arrives through Refresh
     * Models (`claude-haiku-6`, a dated snapshot) gets the same label as the curated one.
     */
    private const FAMILY_TIERS = [
        'haiku'  => ModelTier::Fast,
        'sonnet' => ModelTier::Balanced,
        'opus'   => ModelTier::MostCapable,
        'fable'  => ModelTier::MostCapable,
    ];

    /**
     * The model suggested to an administrator without a preference: the current Sonnet, which
     * Anthropic positions as speed and capability for everyday work.
     */
    private const RECOMMENDED_MODEL = 'claude-sonnet-5-5';

    /**
     * The current Haiku, behind the Fast card.
     */
    private const FAST_MODEL = 'claude-haiku-5-5';

    /**
     * The current Opus, behind the Most capable card. Fable 5.1 is stronger still but runs much
     * longer turns, so it stays a deliberate pick from the full list rather than the default for
     * "Most capable".
     */
    private const MOST_CAPABLE_MODEL = 'claude-opus-5-5';

    /**
     * @param FieldDescriptorInterfaceFactory $fieldFactory
     * @param JsonFetcherInterface $modelListFetcher
     */
    public function __construct(
        FieldDescriptorInterfaceFactory $fieldFactory,
        private readonly JsonFetcherInterface $modelListFetcher,
    ) {
        parent::__construct($fieldFactory);
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'anthropic';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Anthropic';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [
            'claude-fable-5-1'          => 'Claude Fable 5.1',
            'claude-opus-5-5'           => 'Claude Opus 5.5',
            'claude-sonnet-5-5'         => 'Claude Sonnet 5.5',
            'claude-haiku-5-5'          => 'Claude Haiku 5.5',
            'claude-opus-5'             => 'Claude Opus 5',
            'claude-sonnet-5'           => 'Claude Sonnet 5',
            'claude-opus-4-7'           => 'Claude Opus 4.7',
            'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField(),
            $this->modelField($this->getSupportedModels()),
        ];
    }

    /**
     * @inheritdoc
     */
    public function fetchModels(#[\SensitiveParameter] array $configuration): array
    {
        $response = $this->modelListFetcher->getJson(self::MODELS_URL, [
            'x-api-key' => $this->resolveApiKey($configuration),
            'anthropic-version' => self::API_VERSION,
        ]);

        return $this->parseDataModelList($response, 'display_name');
    }

    /**
     * @inheritdoc
     */
    public function getModelGuidance(string $model): ?ModelGuidanceInterface
    {
        $tier = $this->getFamilyTier($model);

        return $tier === null ? null : new ModelGuidance($tier, $model === self::RECOMMENDED_MODEL);
    }

    /**
     * @inheritdoc
     */
    public function getTierModel(ModelTier $tier): ?string
    {
        return match ($tier) {
            ModelTier::Fast => self::FAST_MODEL,
            ModelTier::Balanced => self::RECOMMENDED_MODEL,
            ModelTier::MostCapable => self::MOST_CAPABLE_MODEL,
        };
    }

    /**
     * The tier of the first family whose name the model id contains, or null for an unknown family.
     *
     * @param string $model
     * @return ModelTier|null
     */
    private function getFamilyTier(string $model): ?ModelTier
    {
        $matches = array_filter(
            self::FAMILY_TIERS,
            static fn (string $family): bool => str_contains($model, $family),
            ARRAY_FILTER_USE_KEY,
        );

        return $matches === [] ? null : reset($matches);
    }
}
