<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use MageOS\AiBase\Api\Data\ModelGuidanceInterface;
use MageOS\AiBase\Api\Data\ModelTier;

/**
 * Immutable guidance for one model.
 */
class ModelGuidance implements ModelGuidanceInterface
{
    /**
     * @param ModelTier $tier
     * @param bool $isRecommended
     */
    public function __construct(
        private readonly ModelTier $tier,
        private readonly bool $isRecommended = false,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getTier(): ModelTier
    {
        return $this->tier;
    }

    /**
     * @inheritdoc
     */
    public function isRecommended(): bool
    {
        return $this->isRecommended;
    }
}
