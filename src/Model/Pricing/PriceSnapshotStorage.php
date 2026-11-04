<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Pricing;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Keeps the trimmed price snapshot as a file under `var/`.
 *
 * Not `core_config_data`: the trimmed table is hundreds of kilobytes, which would be loaded into
 * every request through the config cache. A file is read only when a call is actually priced.
 * Writes go to a temporary name and are renamed into place, so a reader never sees half a file and
 * a failed write leaves the previous snapshot untouched.
 */
class PriceSnapshotStorage
{
    /**
     * Path of the snapshot relative to `var/`.
     */
    private const FILE = 'mageos_ai/price_snapshot.json';

    /**
     * @param Filesystem $filesystem
     * @param Json $json
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Json $json,
    ) {
    }

    /**
     * The stored snapshot, or null when none exists or it cannot be read.
     *
     * A corrupt file is treated as absent rather than thrown: pricing is best effort, and an
     * unreadable snapshot must degrade to "cost unknown", never break the call being recorded.
     *
     * @return array<array-key,mixed>|null
     */
    public function read(): ?array
    {
        try {
            $dir = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
            if (!$dir->isFile(self::FILE)) {
                return null;
            }
            $decoded = $this->json->unserialize($dir->readFile(self::FILE));
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Replaces the stored snapshot.
     *
     * @param array<array-key,mixed> $snapshot
     * @return void
     */
    public function write(array $snapshot): void
    {
        $dir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $temporary = self::FILE . '.tmp';
        $dir->writeFile($temporary, (string) $this->json->serialize($snapshot));
        $dir->renameFile($temporary, self::FILE);
    }
}
