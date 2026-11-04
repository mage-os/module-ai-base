<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Pricing;

use MageOS\AiBase\Model\ModelList\HttpFetcher;
use Psr\Log\LoggerInterface;

/**
 * Downloads LiteLLM's price table and replaces the stored snapshot with a trimmed copy.
 *
 * This is the only outbound call the pricing feature makes, to a public file with no credentials.
 * It runs on the nightly cron and on `mageos:ai:prices:refresh`, never while a request is being
 * served. A failed download, an unreadable body or a table with no usable models keeps the previous
 * snapshot and logs a warning: stale prices are better than none, and recorded costs are never
 * recomputed, so a bad refresh can only affect calls priced after it.
 */
class PriceRefresher
{
    /**
     * Where the table is published.
     */
    public const SOURCE_URL = 'https://raw.githubusercontent.com/BerriAI/litellm/main/'
        . 'model_prices_and_context_window.json';

    /**
     * A table with fewer usable models than this is treated as a broken download, not a real update.
     */
    private const MIN_MODELS = 50;

    /**
     * @param HttpFetcher $fetcher Applies the shared timeout and turns every transport problem
     *        into an exception
     * @param PriceTableParser $parser
     * @param PriceSnapshotStorage $storage
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly HttpFetcher $fetcher,
        private readonly PriceTableParser $parser,
        private readonly PriceSnapshotStorage $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetches and stores a new snapshot.
     *
     * @return string|null The new snapshot version, or null when the refresh failed and the old snapshot stays
     */
    public function refresh(): ?string
    {
        try {
            $models = $this->parser->trim($this->fetcher->getJson(self::SOURCE_URL));
            if (count($models) < self::MIN_MODELS) {
                throw new \UnexpectedValueException(
                    sprintf('only %d usable models in the downloaded price table', count($models))
                );
            }

            $version = gmdate('Ymd') . '-' . substr(sha1((string) json_encode($models)), 0, 8);
            $this->storage->write([
                'version' => $version,
                'fetched_at' => time(),
                'source' => self::SOURCE_URL,
                'models' => $models,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('AI price table refresh failed, keeping the previous snapshot: %s', $e->getMessage())
            );

            return null;
        }

        return $version;
    }
}
