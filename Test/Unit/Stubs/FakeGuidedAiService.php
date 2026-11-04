<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\ModelGuidanceInterface;
use MageOS\AiBase\Api\Data\ModelTier;
use MageOS\AiBase\Api\ModelGuideProviderInterface;

/**
 * A provider definition that gives guidance for exactly the models it was handed, and offers the
 * tier models it was handed.
 */
final class FakeGuidedAiService implements AiServiceConfigurationInterface, ModelGuideProviderInterface
{
    /**
     * @param string $code
     * @param array<string, ModelGuidanceInterface> $guidance Guidance per model id
     * @param array<string, string> $tierModels Model id per tier value
     * @param array<string, string> $supportedModels Curated model list, value => label
     */
    public function __construct(
        private readonly string $code,
        private readonly array $guidance = [],
        private readonly array $tierModels = [],
        private readonly array $supportedModels = [],
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return ucfirst($this->code);
    }

    public function getConfigurationFields(): array
    {
        return [];
    }

    public function getSupportedModels(): array
    {
        return $this->supportedModels;
    }

    public function getModelGuidance(string $model): ?ModelGuidanceInterface
    {
        return $this->guidance[$model] ?? null;
    }

    public function getTierModel(ModelTier $tier): ?string
    {
        return $this->tierModels[$tier->value] ?? null;
    }
}
