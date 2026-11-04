<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Pricing;

/**
 * An estimated cost and the price source it was computed from.
 */
class CostEstimate
{
    /**
     * Only currency the list prices are quoted in.
     */
    public const CURRENCY = 'USD';

    /**
     * @param float $cost
     * @param string $snapshot Stored beside the cost as `price_snapshot`
     * @param string $currency
     */
    public function __construct(
        public readonly float $cost,
        public readonly string $snapshot,
        public readonly string $currency = self::CURRENCY,
    ) {
    }
}
