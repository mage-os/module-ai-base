<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Pricing;

/**
 * Trims LiteLLM's `model_prices_and_context_window.json` down to what cost estimation reads, and
 * turns a trimmed entry back into a {@see ModelPrice}.
 *
 * The upstream file is about 3 MB and carries image, audio, video and per-second prices this module
 * never uses; keeping only per-token chat rates makes the stored snapshot a fraction of that.
 *
 * Only the standard (on-demand) rates are read. LiteLLM also lists batch, flex and priority rates
 * under suffixed keys (`input_cost_per_token_batches`); those are deliberately ignored, because a
 * call made through this module's client is a normal synchronous one and a batch discount is not
 * reflected in the estimate (the docs say so).
 */
class PriceTableParser
{
    /**
     * Upstream key => short key stored in the snapshot.
     *
     * @var array<string,string>
     */
    private const FIELDS = [
        'input_cost_per_token' => 'i',
        'output_cost_per_token' => 'o',
        'cache_read_input_token_cost' => 'cr',
        'cache_creation_input_token_cost' => 'cw',
        'output_cost_per_reasoning_token' => 'r',
    ];

    /**
     * Upstream modes whose per-token prices describe a text call.
     *
     * @var string[]
     */
    private const MODES = ['chat', 'completion', 'responses'];

    /**
     * Trims the upstream table.
     *
     * @param array<array-key,mixed> $table Decoded upstream JSON
     * @return array<string,array<string,mixed>> Model key => short-keyed rates, plus `t` for tiers
     */
    public function trim(array $table): array
    {
        $models = [];
        foreach ($table as $key => $entry) {
            if (!is_string($key) || !is_array($entry) || $key === 'sample_spec') {
                continue;
            }
            if (!in_array($entry['mode'] ?? null, self::MODES, true)) {
                continue;
            }
            if (!is_numeric($entry['input_cost_per_token'] ?? null)) {
                continue;
            }

            $row = $this->pick($entry, '');
            $tiers = $this->tiers($entry);
            if ($tiers !== []) {
                $row['t'] = $tiers;
            }
            $models[$key] = $row;
        }

        return $models;
    }

    /**
     * Rebuilds a {@see ModelPrice} from one trimmed snapshot entry.
     *
     * @param array<array-key,mixed> $row
     * @return ModelPrice|null Null when the entry has no usable input and output rate
     */
    public function toPrice(array $row): ?ModelPrice
    {
        $input = $this->number($row['i'] ?? null);
        $output = $this->number($row['o'] ?? null);
        if ($input === null || $output === null) {
            return null;
        }

        $tiers = [];
        $rawTiers = $row['t'] ?? [];
        foreach (is_array($rawTiers) ? $rawTiers : [] as $threshold => $tierRow) {
            if (!is_array($tierRow) || !is_numeric($threshold)) {
                continue;
            }
            $tiers[(int) $threshold] = new ModelPrice(
                $this->number($tierRow['i'] ?? null),
                $this->number($tierRow['o'] ?? null),
                $this->number($tierRow['cr'] ?? null),
                $this->number($tierRow['cw'] ?? null),
                $this->number($tierRow['r'] ?? null),
            );
        }

        return new ModelPrice(
            $input,
            $output,
            $this->number($row['cr'] ?? null),
            $this->number($row['cw'] ?? null),
            $this->number($row['r'] ?? null),
            $tiers,
        );
    }

    /**
     * Reads the five rates under an optional upstream suffix such as `_above_200k_tokens`.
     *
     * @param array<array-key,mixed> $entry
     * @param string $suffix
     * @return array<string,float>
     */
    private function pick(array $entry, string $suffix): array
    {
        $row = [];
        foreach (self::FIELDS as $upstream => $short) {
            $value = $entry[$upstream . $suffix] ?? null;
            if (is_numeric($value)) {
                $row[$short] = (float) $value;
            }
        }

        return $row;
    }

    /**
     * Collects the `_above_<N>k_tokens` rate cards of an entry, keyed by threshold in tokens.
     *
     * @param array<array-key,mixed> $entry
     * @return array<int,array<string,float>>
     */
    private function tiers(array $entry): array
    {
        $tiers = [];
        foreach (array_keys($entry) as $key) {
            if (is_string($key) && preg_match('~_above_(\d+)k_tokens$~', $key, $matches) === 1) {
                $threshold = (int) $matches[1] * 1000;
                $tiers[$threshold] = $this->pick($entry, '_above_' . $matches[1] . 'k_tokens');
            }
        }
        ksort($tiers);

        return $tiers;
    }

    /**
     * A numeric value as a float, or null when it is absent or not numeric.
     *
     * @param mixed $value
     * @return float|null
     */
    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
