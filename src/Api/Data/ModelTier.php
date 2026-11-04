<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * Where a model sits in its provider's line-up, in the terms an administrator picks by.
 *
 * Deliberately coarse: three steps every provider's catalogue maps onto, instead of benchmark
 * scores or context sizes, because the question this answers is "which one should I pick", not
 * "how does it compare on paper". Says nothing about price; see the "No cost estimation" decision
 * in docs/ARCHITECTURE.md.
 *
 * Cases may be added in a minor release; give a `match` over this enum a `default` arm.
 *
 * @api
 */
enum ModelTier: string
{
    /**
     * Quick responses, for simple tasks such as short texts and tagging.
     */
    case Fast = 'fast';

    /**
     * Good quality at a reasonable speed; the right choice for most work.
     */
    case Balanced = 'balanced';

    /**
     * The best results on hard tasks, at the cost of speed.
     */
    case MostCapable = 'most-capable';
}
