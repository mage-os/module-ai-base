<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Pricing;

/**
 * A {@see ModelPrice} together with where it came from.
 *
 * The source is what gets stored beside a cost as `price_snapshot`, so a recorded figure can always
 * be traced to the exact table (or manual override) that produced it.
 */
class PriceQuote
{
    /**
     * Snapshot label stored for a price an administrator entered by hand.
     */
    public const SOURCE_OVERRIDE = 'override';

    /**
     * @param ModelPrice $price
     * @param string $source Snapshot version, or {@see SOURCE_OVERRIDE}
     */
    public function __construct(
        public readonly ModelPrice $price,
        public readonly string $source,
    ) {
    }
}
