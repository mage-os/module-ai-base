<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Pricing;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Manual per-model prices from `mageos_ai/usage/price_overrides`, which beat the downloaded table.
 *
 * One model per line, prices in USD per one million tokens (the unit providers publish):
 *
 *     service_code:model input output [cache_read [cache_write]]
 *
 * for example `openai_compatible:llama-3.3-70b 0.60 0.80`. A line that cannot be parsed is skipped
 * rather than thrown, so one typo cannot disable pricing for the rest; the config field's backend
 * model is what tells the administrator at save time ({@see self::parse()} is public for that).
 */
class PriceOverrides
{
    /**
     * Config path of the override text.
     */
    public const CONFIG_PATH = 'mageos_ai/usage/price_overrides';

    /**
     * Tokens per unit in which administrators enter prices.
     */
    private const PER_TOKENS = 1_000_000.0;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * The override for a model, or null when none is configured.
     *
     * @param string $serviceCode
     * @param string $model
     * @return ModelPrice|null
     */
    public function find(string $serviceCode, string $model): ?ModelPrice
    {
        $raw = $this->scopeConfig->getValue(self::CONFIG_PATH);

        return self::parse(is_string($raw) ? $raw : '')[$serviceCode . ':' . $model] ?? null;
    }

    /**
     * Parses the override text into prices per token, keyed `service_code:model`.
     *
     * @param string $text
     * @return array<string,ModelPrice>
     */
    public static function parse(string $text): array
    {
        $prices = [];
        foreach (preg_split('~\R~', $text) ?: [] as $line) {
            $parts = preg_split('~\s+~', trim($line)) ?: [];
            $count = count($parts);
            if ($count < 3 || $count > 5 || !str_contains($parts[0], ':')) {
                continue;
            }
            $numbers = array_slice($parts, 1);
            if (array_filter($numbers, static fn (string $n): bool => !is_numeric($n) || (float) $n < 0) !== []) {
                continue;
            }
            $prices[$parts[0]] = new ModelPrice(
                (float) $numbers[0] / self::PER_TOKENS,
                (float) $numbers[1] / self::PER_TOKENS,
                isset($numbers[2]) ? (float) $numbers[2] / self::PER_TOKENS : null,
                isset($numbers[3]) ? (float) $numbers[3] / self::PER_TOKENS : null,
            );
        }

        return $prices;
    }

    /**
     * Lines of $text that {@see parse()} would skip, for validation messages.
     *
     * @param string $text
     * @return string[]
     */
    public static function invalidLines(string $text): array
    {
        $invalid = [];
        foreach (preg_split('~\R~', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && self::parse($line) === []) {
                $invalid[] = $line;
            }
        }

        return $invalid;
    }
}
