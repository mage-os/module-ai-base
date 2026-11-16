<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\PlatformAwareInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Model\Usage\UsageStoreResolver;
use MageOS\AiBase\Model\Pricing\CostEstimator;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\PlatformInterface;

/**
 * {@see RecordingAiClient} for a wrapped client that also implements {@see PlatformAwareInterface}.
 *
 * A single decorator that always implemented PlatformAwareInterface would lie to the documented
 * `if (!$client instanceof PlatformAwareInterface)` escape hatch on that interface's own docblock
 * whenever the wrapped client deliberately does not implement it: a third-party AiClientInterface
 * with no platform to reach would suddenly claim to have one. Task 009 picks this variant over the
 * plain RecordingAiClient by `instanceof` on the client it is about to wrap, which is what keeps
 * the wrapped shape preserved through the decorator either way.
 *
 * getPlatform() and normalizeOptions() both forward to the wrapped client untouched. Calls made
 * directly against the returned platform bypass this decorator entirely and are therefore
 * untrackable by design; that is the documented trade-off of the escape hatch itself, not something
 * this class can or should change.
 */
class RecordingPlatformAwareAiClient extends RecordingAiClient implements PlatformAwareInterface
{
    /**
     * @param AiClientInterface&PlatformAwareInterface $platformAwareDelegate The client every call,
     *        including getPlatform() and normalizeOptions(), is actually made through
     * @param UsageRecordRepositoryInterface $repository
     * @param UsageStoreResolver $storeResolver
     * @param LoggerInterface $logger
     * @param CostEstimator|null $costEstimator
     */
    // Not a useless override: the promoted parameter is typed as the narrower intersection
    // getPlatform() and normalizeOptions() below need, which is what keeps an intersection-typed
    // reference around for them instead of only the parent's plain AiClientInterface one.
    // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod
    public function __construct(
        private readonly AiClientInterface&PlatformAwareInterface $platformAwareDelegate,
        UsageRecordRepositoryInterface $repository,
        UsageStoreResolver $storeResolver,
        LoggerInterface $logger,
        ?CostEstimator $costEstimator = null,
    ) {
        parent::__construct($platformAwareDelegate, $repository, $storeResolver, $logger, $costEstimator);
    }

    /**
     * @inheritdoc
     */
    public function getPlatform(): PlatformInterface
    {
        return $this->platformAwareDelegate->getPlatform();
    }

    /**
     * @inheritdoc
     */
    public function normalizeOptions(array $options): array
    {
        return $this->platformAwareDelegate->normalizeOptions($options);
    }
}
