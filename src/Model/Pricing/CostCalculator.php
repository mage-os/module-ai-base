<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Pricing;

/**
 * The one place a token count and a {@see ModelPrice} become a cost in USD.
 *
 * Kept free of I/O and of Magento so the formula can be tested exhaustively and read in one go.
 *
 * Token semantics follow what the recorded columns already guarantee, see
 * {@see \MageOS\AiBase\Model\Client\UsageNormalizer}: the prompt count already contains the cache
 * read and cache write tokens (Anthropic's separate counts are folded in before a row is written),
 * so the ordinary-input portion is prompt minus cache read minus cache write. Reasoning tokens are
 * a portion of the completion count, not an addition to it, so they are billed as output and never
 * added on top. A bridge that behaved differently would have to be normalized, not special-cased
 * here.
 *
 * Unknown stays unknown: a missing prompt or completion count, a missing rate the call actually
 * needed, or counts that contradict each other give null, never a partial sum that would read as a
 * real figure. A null cache or reasoning count means the provider reported none, which is the same
 * as zero for pricing, because it also reported no cached tokens to bill at a different rate.
 */
class CostCalculator
{
    /**
     * Decimal places kept, matching the `cost` column's scale so a stored value equals a computed one.
     */
    public const SCALE = 10;

    /**
     * Cost of one call in USD, or null when it cannot be stated.
     *
     * @param ModelPrice $price
     * @param int|null $inputTokens Prompt tokens, cache included
     * @param int|null $outputTokens Completion tokens, reasoning included
     * @param int|null $cacheReadTokens
     * @param int|null $cacheWriteTokens
     * @param int|null $reasoningTokens
     * @return float|null
     */
    public function calculate(
        ModelPrice $price,
        ?int $inputTokens,
        ?int $outputTokens,
        ?int $cacheReadTokens = null,
        ?int $cacheWriteTokens = null,
        ?int $reasoningTokens = null,
    ): ?float {
        if ($inputTokens === null || $outputTokens === null) {
            return null;
        }

        $card = $price->forPromptTokens($inputTokens);
        $cacheRead = $cacheReadTokens ?? 0;
        $cacheWrite = $cacheWriteTokens ?? 0;
        $plainInput = $inputTokens - $cacheRead - $cacheWrite;

        if ($plainInput < 0 || $card->input === null || $card->output === null) {
            return null;
        }
        if (($cacheRead > 0 && $card->cacheRead === null) || ($cacheWrite > 0 && $card->cacheWrite === null)) {
            return null;
        }

        $reasoning = min($reasoningTokens ?? 0, $outputTokens);
        $reasoningRate = $card->reasoning ?? $card->output;

        $cost = $plainInput * $card->input
            + $cacheRead * ($card->cacheRead ?? 0.0)
            + $cacheWrite * ($card->cacheWrite ?? 0.0)
            + ($outputTokens - $reasoning) * $card->output
            + $reasoning * $reasoningRate;

        return round($cost, self::SCALE);
    }
}
