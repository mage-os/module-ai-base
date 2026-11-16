<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Pricing;

use MageOS\AiBase\Model\Pricing\CostCalculator;
use MageOS\AiBase\Model\Pricing\ModelPrice;
use PHPUnit\Framework\TestCase;

final class CostCalculatorTest extends TestCase
{
    private CostCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CostCalculator();
    }

    public function test_it_prices_plain_input_and_output(): void
    {
        $price = new ModelPrice(0.000003, 0.000015);

        self::assertEqualsWithDelta(0.0105, $this->calculator->calculate($price, 1000, 500), 1e-12);
    }

    public function test_it_bills_cache_reads_and_writes_at_their_own_rates_without_double_counting(): void
    {
        $price = new ModelPrice(0.000003, 0.000015, 0.0000003, 0.00000375);

        // prompt 1000 includes 600 read + 100 written, so only 300 are ordinary input.
        $expected = 300 * 0.000003 + 600 * 0.0000003 + 100 * 0.00000375 + 0;
        self::assertEqualsWithDelta($expected, $this->calculator->calculate($price, 1000, 0, 600, 100), 1e-12);
    }

    public function test_reasoning_tokens_are_billed_as_output_and_not_added_on_top(): void
    {
        $price = new ModelPrice(0.000001, 0.00001);

        $withReasoning = $this->calculator->calculate($price, 0, 1000, null, null, 400);
        $without = $this->calculator->calculate($price, 0, 1000);

        self::assertEqualsWithDelta(0.01, $withReasoning, 1e-12);
        self::assertSame($without, $withReasoning);
    }

    public function test_a_dedicated_reasoning_rate_applies_to_the_reasoning_share_only(): void
    {
        $price = new ModelPrice(0.0, 0.00001, reasoning: 0.00002);

        $expected = 600 * 0.00001 + 400 * 0.00002;
        self::assertEqualsWithDelta($expected, $this->calculator->calculate($price, 0, 1000, null, null, 400), 1e-12);
    }

    public function test_the_long_context_tier_replaces_the_whole_rate_card(): void
    {
        $price = new ModelPrice(0.000001, 0.00001, tiers: [
            200000 => new ModelPrice(0.000002, 0.000015),
        ]);

        self::assertEqualsWithDelta(0.1 + 0.01, $this->calculator->calculate($price, 100000, 1000), 1e-9);
        self::assertEqualsWithDelta(
            200001 * 0.000002 + 1000 * 0.000015,
            $this->calculator->calculate($price, 200001, 1000),
            1e-9
        );
    }

    public function test_it_is_unknown_when_a_needed_token_count_is_missing(): void
    {
        $price = new ModelPrice(0.000001, 0.00001);

        self::assertNull($this->calculator->calculate($price, null, 10));
        self::assertNull($this->calculator->calculate($price, 10, null));
    }

    public function test_it_is_unknown_when_the_call_used_a_cache_rate_the_table_lacks(): void
    {
        $price = new ModelPrice(0.000001, 0.00001);

        self::assertNull($this->calculator->calculate($price, 100, 10, 50));
        self::assertNull($this->calculator->calculate($price, 100, 10, null, 50));
    }

    public function test_a_zero_cache_count_does_not_need_a_cache_rate(): void
    {
        $price = new ModelPrice(0.000001, 0.00001);

        self::assertNotNull($this->calculator->calculate($price, 100, 10, 0, 0));
    }

    public function test_it_is_unknown_when_the_table_has_no_input_or_output_rate(): void
    {
        self::assertNull($this->calculator->calculate(new ModelPrice(null, 0.1), 1, 1));
        self::assertNull($this->calculator->calculate(new ModelPrice(0.1, null), 1, 1));
    }

    public function test_it_is_unknown_when_cache_counts_exceed_the_prompt(): void
    {
        $price = new ModelPrice(0.000001, 0.00001, 0.0000001, 0.000001);

        self::assertNull($this->calculator->calculate($price, 100, 10, 90, 30));
    }

    public function test_a_genuinely_free_model_costs_zero_not_unknown(): void
    {
        self::assertSame(0.0, $this->calculator->calculate(new ModelPrice(0.0, 0.0), 1000, 1000));
    }
}
