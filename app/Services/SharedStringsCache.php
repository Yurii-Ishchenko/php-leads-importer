<?php

namespace App\Services;

use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyInterface;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\FileBasedStrategy;

/** A bounded hot-string cache in front of small disk-backed pages. */
final class SharedStringsCache implements CachingStrategyInterface
{
    private array $hot = [];

    private FileBasedStrategy $disk;

    public function __construct(string $tempFolder)
    {
        // Small pages avoid reparsing 10,000 strings when indexes alternate.
        $this->disk = new FileBasedStrategy($tempFolder, 256);
    }

    public function addStringForIndex(string $sharedString, int $sharedStringIndex): void
    {
        $this->disk->addStringForIndex($sharedString, $sharedStringIndex);
    }

    public function closeCache(): void
    {
        $this->disk->closeCache();
    }

    public function getStringAtIndex(int $sharedStringIndex): string
    {
        if (isset($this->hot[$sharedStringIndex])) {
            return $this->hot[$sharedStringIndex];
        }
        $value = $this->disk->getStringAtIndex($sharedStringIndex);
        $this->hot[$sharedStringIndex] = $value;
        if (count($this->hot) > 4096) {
            unset($this->hot[array_key_first($this->hot)]);
        }

        return $value;
    }

    public function clearCache(): void
    {
        $this->hot = [];
        $this->disk->clearCache();
    }
}
