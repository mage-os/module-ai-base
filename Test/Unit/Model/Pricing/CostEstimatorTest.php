<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Pricing;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Model\Chat\TokenUsage;
use MageOS\AiBase\Model\Pricing\CostCalculator;
use MageOS\AiBase\Model\Pricing\CostEstimator;
use MageOS\AiBase\Model\Pricing\CostFormatter;
use MageOS\AiBase\Model\Pricing\ModelKeyMapper;
use MageOS\AiBase\Model\Pricing\PriceCatalog;
use MageOS\AiBase\Model\Pricing\PriceOverrides;
use MageOS\AiBase\Model\Pricing\PriceQuote;
use MageOS\AiBase\Model\Pricing\PriceSnapshotStorage;
use MageOS\AiBase\Model\Pricing\PriceTableParser;
use MageOS\AiBase\Model\Usage\UsageTotals;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Pricing\CostEstimator
 * @covers \MageOS\AiBase\Model\Pricing\PriceCatalog
 * @covers \MageOS\AiBase\Model\Pricing\PriceOverrides
 * @covers \MageOS\AiBase\Model\Pricing\CostFormatter
 */
final class CostEstimatorTest extends TestCase
{
    public function test_it_prices_a_known_model_from_the_snapshot_and_names_the_snapshot(): void
    {
        $estimate = $this->estimator()->estimate('openai', 'gpt-4o', new TokenUsage(1000, 500, 1500));

        self::assertNotNull($estimate);
        self::assertEqualsWithDelta(1000 * 2.5e-6 + 500 * 1e-5, $estimate->cost, 1e-12);
        self::assertSame('20260101-test', $estimate->snapshot);
        self::assertSame('USD', $estimate->currency);
    }

    public function test_an_unknown_model_is_unknown_not_zero(): void
    {
        self::assertNull($this->estimator()->estimate('openai', 'no-such-model', new TokenUsage(1, 1)));
    }

    public function test_a_self_hosted_provider_is_unknown_without_an_override(): void
    {
        self::assertNull($this->estimator()->estimate('ollama', 'gpt-4o', new TokenUsage(1, 1)));
    }

    public function test_a_call_with_no_reported_usage_is_unknown(): void
    {
        self::assertNull($this->estimator()->estimate('openai', 'gpt-4o', null));
    }

    public function test_an_override_beats_the_snapshot_and_works_for_self_hosted_models(): void
    {
        $overrides = "ollama:llama3 1.00 2.00\nopenai:gpt-4o 10 20";
        $estimator = $this->estimator($overrides);

        $local = $estimator->estimate('ollama', 'llama3', new TokenUsage(1_000_000, 1_000_000));
        $beaten = $estimator->estimate('openai', 'gpt-4o', new TokenUsage(1_000_000, 0));

        self::assertNotNull($local);
        self::assertEqualsWithDelta(3.0, $local->cost, 1e-9);
        self::assertSame(PriceQuote::SOURCE_OVERRIDE, $local->snapshot);
        self::assertNotNull($beaten);
        self::assertEqualsWithDelta(10.0, $beaten->cost, 1e-9);
    }

    public function test_it_is_unknown_when_no_snapshot_has_been_downloaded_yet(): void
    {
        self::assertNull($this->estimator('', null)->estimate('openai', 'gpt-4o', new TokenUsage(1, 1)));
    }

    public function test_it_never_throws_when_the_snapshot_is_unreadable(): void
    {
        $storage = new class extends PriceSnapshotStorage {
            public function __construct()
            {
            }

            public function read(): ?array
            {
                throw new \RuntimeException('disk gone');
            }
        };
        $estimator = new CostEstimator($this->catalog($storage, ''), new CostCalculator());

        self::assertNull($estimator->estimate('openai', 'gpt-4o', new TokenUsage(1, 1)));
    }

    public function test_override_parsing_skips_bad_lines_and_reports_them(): void
    {
        $text = "openai:gpt-4o 1 2\nnonsense\nopenai:x abc 2\nopenai:y 1 -2";

        self::assertSame(['openai:gpt-4o'], array_keys(PriceOverrides::parse($text)));
        self::assertSame(['nonsense', 'openai:x abc 2', 'openai:y 1 -2'], PriceOverrides::invalidLines($text));
    }

    public function test_override_prices_are_per_million_tokens(): void
    {
        $price = PriceOverrides::parse('a:b 3 15 0.3 3.75')['a:b'];

        self::assertSame(3e-6, $price->input);
        self::assertSame(1.5e-5, $price->output);
        self::assertSame(3e-7, $price->cacheRead);
        self::assertSame(3.75e-6, $price->cacheWrite);
    }

    public function test_the_formatter_shows_na_for_unknown_and_never_a_zero_dollar_figure(): void
    {
        $formatter = new CostFormatter();

        self::assertSame('n/a', $formatter->format(null));
        self::assertSame('$0.00', $formatter->format(0.0));
        self::assertSame('$12.35', $formatter->format(12.345));
        self::assertSame('$0.000150', $formatter->format(0.00015));
        self::assertSame('n/a', $formatter->formatTotals(new UsageTotals(3, 0, 0, 0, null, null)));
    }

    public function test_the_formatter_flags_a_partial_sum(): void
    {
        $totals = new UsageTotals(4, 0, 0, 0, null, null, null, 0, 1.5, 3);

        self::assertSame('$1.50 (3 of 4 calls priced)', (new CostFormatter())->formatTotals($totals));
    }

    private function estimator(string $overrides = '', ?string $version = '20260101-test'): CostEstimator
    {
        $parser = new PriceTableParser();
        $table = json_decode((string) file_get_contents(__DIR__ . '/fixtures/litellm_prices.json'), true);
        $snapshot = $version === null ? null : ['version' => $version, 'models' => $parser->trim($table)];
        $storage = new class ($snapshot) extends PriceSnapshotStorage {
            /** @param array<array-key,mixed>|null $snapshot */
            public function __construct(private readonly ?array $snapshot)
            {
            }

            public function read(): ?array
            {
                return $this->snapshot;
            }
        };

        return new CostEstimator($this->catalog($storage, $overrides), new CostCalculator());
    }

    private function catalog(PriceSnapshotStorage $storage, string $overrides): PriceCatalog
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($overrides);

        return new PriceCatalog($storage, new PriceTableParser(), new ModelKeyMapper(), new PriceOverrides($config));
    }
}
