<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Pricing;

use MageOS\AiBase\Api\Data\UsageTotalsInterface;

/**
 * Renders an estimated cost for people, in one place so the dashboard and the CLI never disagree.
 *
 * Unknown is `n/a`, never `$0.00`: a zero would read as "free" for a model nobody could price. A
 * sum that leaves out some calls is suffixed so it is not mistaken for a complete figure. Small
 * amounts keep more decimals, because a single cheap call costs a fraction of a cent.
 */
class CostFormatter
{
    /**
     * Text shown for a cost that is unknown.
     */
    public const UNKNOWN = 'n/a';

    /**
     * Formats one cost, `n/a` when it is unknown.
     *
     * @param float|null $cost
     * @return string
     */
    public function format(?float $cost): string
    {
        if ($cost === null) {
            return self::UNKNOWN;
        }

        return '$' . number_format($cost, $cost > 0.0 && $cost < 0.01 ? 6 : 2, '.', ',');
    }

    /**
     * A totals row's cost, flagged when it only covers some of its calls.
     *
     * @param UsageTotalsInterface $totals
     * @return string
     */
    public function formatTotals(UsageTotalsInterface $totals): string
    {
        $formatted = $this->format($totals->getCost());
        if (!$totals->isCostPartial()) {
            return $formatted;
        }

        return sprintf('%s (%d of %d calls priced)', $formatted, $totals->getPricedCalls(), $totals->getCalls());
    }
}
