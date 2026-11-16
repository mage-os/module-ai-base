<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Pricing;

/**
 * Answers "what does this model cost per token?" from the manual overrides first and the stored
 * LiteLLM snapshot second.
 *
 * Returns null for anything it cannot price (a self-hosted backend, a model the table lacks, no
 * snapshot downloaded yet). Null propagates to a null cost, never to zero.
 */
class PriceCatalog
{
    /**
     * Decoded snapshot, loaded at most once per instance; false until first read.
     *
     * @var array<array-key,mixed>|null|false
     */
    private $snapshot = false;

    /**
     * @param PriceSnapshotStorage $storage
     * @param PriceTableParser $parser
     * @param ModelKeyMapper $keyMapper
     * @param PriceOverrides $overrides
     */
    public function __construct(
        private readonly PriceSnapshotStorage $storage,
        private readonly PriceTableParser $parser,
        private readonly ModelKeyMapper $keyMapper,
        private readonly PriceOverrides $overrides,
    ) {
    }

    /**
     * The price for a model, or null when unknown.
     *
     * @param string $serviceCode
     * @param string $model
     * @return PriceQuote|null
     */
    public function find(string $serviceCode, string $model): ?PriceQuote
    {
        $override = $this->overrides->find($serviceCode, $model);
        if ($override !== null) {
            return new PriceQuote($override, PriceQuote::SOURCE_OVERRIDE);
        }

        $snapshot = $this->snapshot();
        $models = is_array($snapshot) && is_array($snapshot['models'] ?? null) ? $snapshot['models'] : [];
        $version = is_array($snapshot) && is_string($snapshot['version'] ?? null) ? $snapshot['version'] : '';
        if ($models === [] || $version === '') {
            return null;
        }

        foreach ($this->keyMapper->candidates($serviceCode, $model) as $key) {
            $row = $models[$key] ?? null;
            $price = is_array($row) ? $this->parser->toPrice($row) : null;
            if ($price !== null) {
                return new PriceQuote($price, $version);
            }
        }

        return null;
    }

    /**
     * Version label of the stored snapshot, or null when none has been downloaded.
     *
     * @return string|null
     */
    public function getSnapshotVersion(): ?string
    {
        $snapshot = $this->snapshot();

        return is_array($snapshot) && is_string($snapshot['version'] ?? null) ? $snapshot['version'] : null;
    }

    /**
     * The stored snapshot, read from disk at most once per instance.
     *
     * @return array<array-key,mixed>|null
     */
    private function snapshot(): ?array
    {
        if ($this->snapshot === false) {
            $this->snapshot = $this->storage->read();
        }

        return $this->snapshot;
    }
}
