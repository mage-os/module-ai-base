<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use MageOS\AiBase\Api\Data\ModelGuidanceInterface;
use MageOS\AiBase\Api\Data\ModelTier;

/**
 * Opt-in capability for AI backends that can tell an administrator which model to pick.
 *
 * Implement this alongside {@see \MageOS\AiBase\Api\Data\AiServiceConfigurationInterface} to let
 * an administrator pick by tier instead of by model name: the admin form shows one card per tier
 * (Fast, Balanced, Most capable), each set to the model {@see getTierModel()} names, and keeps the
 * full model list behind a "Choose a specific model" link. {@see getModelGuidance()} classifies one
 * model id at a time rather than returning a fixed map, so a model that only arrives through Refresh
 * Models (one released after this module) still selects the right card, for example by its family
 * name.
 *
 * An optional capability a provider opts into next to {@see Data\AiServiceConfigurationInterface}.
 * Its methods do not change within a major version; a new capability gets a new interface.
 *
 * @api
 */
interface ModelGuideProviderInterface
{
    /**
     * Guidance for one model of this provider.
     *
     * @param string $model Model id as stored in the row's `model` field (e.g. `claude-sonnet-5-5`)
     * @return ModelGuidanceInterface|null Null when the provider does not know the model; the form
     *         then shows it without a label, rather than with a guessed one
     */
    public function getModelGuidance(string $model): ?ModelGuidanceInterface;

    /**
     * The model a tier card stands for: the one an administrator gets by picking that tier.
     *
     * The recommended one among these is what a new row starts on.
     *
     * @param ModelTier $tier
     * @return string|null Model id, or null when this provider offers nothing for the tier; the form
     *         then shows no card for it
     */
    public function getTierModel(ModelTier $tier): ?string;
}
