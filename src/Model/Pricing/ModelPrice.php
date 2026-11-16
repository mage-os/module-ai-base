<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Pricing;

/**
 * Per-token prices of one model, in USD, as one snapshot of the price table states them.
 *
 * Every rate is nullable on purpose: "this table has no cache-write price for the model" is not the
 * same fact as "cache writes are free", and {@see CostCalculator} turns a missing rate that a call
 * actually needed into an unknown cost instead of a cheaper-looking guess.
 *
 * Tiered pricing (a higher rate once the prompt passes a context-length threshold, as Anthropic and
 * Google charge for long contexts) is held as a map of threshold => a tier-only {@see ModelPrice}.
 * A tier replaces the whole rate card, it does not patch single fields, so a tier that omits a
 * rate leaves that rate unknown rather than silently inheriting the cheaper base one.
 */
class ModelPrice
{
    /**
     * @param float|null $input USD per ordinary (uncached) prompt token
     * @param float|null $output USD per completion token
     * @param float|null $cacheRead USD per prompt token served from cache
     * @param float|null $cacheWrite USD per prompt token written to cache
     * @param float|null $reasoning USD per reasoning token when the table prices them apart from
     *        output; null means reasoning tokens are billed at the output rate
     * @param array<int,ModelPrice> $tiers Threshold in prompt tokens => the rate card that applies
     *        to a call whose prompt is larger than that threshold
     */
    public function __construct(
        public readonly ?float $input,
        public readonly ?float $output,
        public readonly ?float $cacheRead = null,
        public readonly ?float $cacheWrite = null,
        public readonly ?float $reasoning = null,
        public readonly array $tiers = [],
    ) {
    }

    /**
     * The rate card that applies to a call with this many prompt tokens.
     *
     * The tier with the highest threshold the prompt exceeds wins; none exceeded means the base
     * card. Providers apply a long-context rate to the whole request, not only to the tokens past
     * the threshold, which is why this returns one card rather than splitting the count.
     *
     * @param int $promptTokens
     * @return ModelPrice
     */
    public function forPromptTokens(int $promptTokens): ModelPrice
    {
        $applicable = null;
        foreach ($this->tiers as $threshold => $tier) {
            if ($promptTokens > $threshold && ($applicable === null || $threshold > $applicable)) {
                $applicable = $threshold;
            }
        }

        return $applicable === null ? $this : $this->tiers[$applicable];
    }
}
