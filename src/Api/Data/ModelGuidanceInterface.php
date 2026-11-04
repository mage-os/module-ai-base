<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * What an administrator needs to know to pick one model over another.
 *
 * Returned by {@see \MageOS\AiBase\Api\ModelGuideProviderInterface}. Providers create instances
 * through the generated `ModelGuidanceInterfaceFactory` (or `\MageOS\AiBase\Model\ModelGuidance`
 * inside this module) and never implement this interface themselves, which is what lets a minor
 * release add a method here without breaking them.
 *
 * @api
 */
interface ModelGuidanceInterface
{
    /**
     * The model's place in its provider's line-up.
     *
     * @return ModelTier
     */
    public function getTier(): ModelTier;

    /**
     * Whether this is the model the provider suggests when an administrator has no preference.
     *
     * @return bool
     */
    public function isRecommended(): bool;
}
