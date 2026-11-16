<?php

declare(strict_types=1);

namespace MageOS\AiBase\Cron;

use MageOS\AiBase\Model\Pricing\PriceRefresher;
use MageOS\AiBase\Model\Usage\UsageConfig;

/**
 * Nightly entry point for {@see PriceRefresher}, scheduled by `etc/crontab.xml`.
 *
 * Does nothing while usage tracking is off: nothing would read the snapshot, and a disabled feature
 * should not make outbound requests. {@see PriceRefresher} logs its own failures and keeps the old
 * snapshot, so there is nothing to rethrow here.
 */
class RefreshPrices
{
    /**
     * @param PriceRefresher $refresher
     * @param UsageConfig $usageConfig
     */
    public function __construct(
        private readonly PriceRefresher $refresher,
        private readonly UsageConfig $usageConfig,
    ) {
    }

    /**
     * Invoked by Magento_Cron on the configured schedule.
     *
     * @return void
     */
    public function execute(): void
    {
        if ($this->usageConfig->isEnabled()) {
            $this->refresher->refresh();
        }
    }
}
