<?php

namespace App\Services;

use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyFactoryInterface;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyInterface;

final class SharedStringsCacheFactory implements CachingStrategyFactoryInterface
{
    public function createBestCachingStrategy(?int $sharedStringsUniqueCount, string $tempFolder): CachingStrategyInterface
    {
        return new SharedStringsCache($tempFolder);
    }
}
