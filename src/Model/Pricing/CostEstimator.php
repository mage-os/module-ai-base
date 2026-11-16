<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Pricing;

use MageOS\AiBase\Api\Data\TokenUsageInterface;

/**
 * Prices one recorded call: looks the model up and runs the formula.
 *
 * Never throws. It runs inside the usage recorder, after the AI call already finished, and a pricing
 * problem must cost a figure, not the row or the caller's response.
 */
class CostEstimator
{
    /**
     * @param PriceCatalog $catalog
     * @param CostCalculator $calculator
     */
    public function __construct(
        private readonly PriceCatalog $catalog,
        private readonly CostCalculator $calculator,
    ) {
    }

    /**
     * Prices one call, or returns null when its cost cannot be stated.
     *
     * @param string $serviceCode
     * @param string $model
     * @param TokenUsageInterface|null $usage Null when the provider reported nothing, which is unknown, not free
     * @return CostEstimate|null Null when the cost cannot be stated
     */
    public function estimate(string $serviceCode, string $model, ?TokenUsageInterface $usage): ?CostEstimate
    {
        if ($usage === null) {
            return null;
        }

        try {
            $quote = $this->catalog->find($serviceCode, $model);
            if ($quote === null) {
                return null;
            }
            $cost = $this->calculator->calculate(
                $quote->price,
                $usage->getPromptTokens(),
                $usage->getCompletionTokens(),
                $usage->getCacheReadTokens(),
                $usage->getCacheWriteTokens(),
                $usage->getReasoningTokens(),
            );
        } catch (\Throwable) {
            return null;
        }

        return $cost === null ? null : new CostEstimate($cost, $quote->source);
    }
}
