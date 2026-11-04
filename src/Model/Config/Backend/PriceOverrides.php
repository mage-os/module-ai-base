<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config\Backend;

use MageOS\AiBase\Model\Pricing\PriceOverrides as ParsedOverrides;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Rejects `mageos_ai/usage/price_overrides` text with lines that cannot be read.
 *
 * {@see ParsedOverrides} skips a bad line silently at read time, which is right for a call being
 * priced and wrong for an administrator who would never learn the price was ignored. Saving is the
 * moment to say so.
 */
class PriceOverrides extends Value
{
    /**
     * Rejects override text containing lines that cannot be read.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        $text = is_scalar($this->getValue()) ? (string) $this->getValue() : '';
        $invalid = ParsedOverrides::invalidLines($text);
        if ($invalid !== []) {
            throw new LocalizedException(__(
                'These price override lines are not valid: %1. Use "service_code:model input output'
                . ' [cache_read [cache_write]]" with USD prices per million tokens.',
                implode('; ', $invalid)
            ));
        }

        return parent::beforeSave();
    }
}
