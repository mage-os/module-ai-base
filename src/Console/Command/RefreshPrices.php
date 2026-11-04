<?php

declare(strict_types=1);

namespace MageOS\AiBase\Console\Command;

use MageOS\AiBase\Model\Pricing\PriceRefresher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento mageos:ai:prices:refresh` — downloads the model price table now instead of waiting
 * for the nightly cron, which is what a fresh install needs before its first costs can be computed.
 */
class RefreshPrices extends Command
{
    /**
     * @param PriceRefresher $refresher
     */
    public function __construct(
        private readonly PriceRefresher $refresher,
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('mageos:ai:prices:refresh')
            ->setDescription('Downloads the LiteLLM model price table used to estimate AI usage cost.');

        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version = $this->refresher->refresh();
        if ($version === null) {
            $output->writeln(
                '<error>The price table could not be refreshed; the previous one is kept. See the log.</error>'
            );

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Price table updated to snapshot %s.', $version));

        return Command::SUCCESS;
    }
}
