<?php

/**
 * @file classes/managers/RateLimitManager.php
 *
 * Copyright (c) 2026 Ne Varietur
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief File-based rate limiter with atomic flock() for TOCTOU safety.
 *        Extracted from SuggestHandler::checkApiGating for testability.
 */

namespace APP\plugins\generic\nvMetadataCuration\classes\managers;

class RateLimitManager
{
    private int $dailyLimit;
    private string $tempDir;

    public function __construct(int $dailyLimit = 50, ?string $tempDir = null)
    {
        $this->dailyLimit = $dailyLimit;
        $this->tempDir = $tempDir ?? sys_get_temp_dir();
    }

    /**
     * Check whether the request is allowed under the rate limit.
     *
     * @param int $contextId  Journal context ID
     * @return array{allowed: bool, currentCount: int, limit: int}
     */
    public function check(int $contextId): array
    {
        $countFile = $this->getCountFilePath($contextId);
        $fp = @fopen($countFile, 'c+');

        if (!$fp) {
            // Fail open — cannot enforce limit
            return ['allowed' => true, 'currentCount' => 0, 'limit' => $this->dailyLimit];
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                return ['allowed' => true, 'currentCount' => 0, 'limit' => $this->dailyLimit];
            }

            $currentCount = (int) stream_get_contents($fp);

            if ($currentCount >= $this->dailyLimit) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return ['allowed' => false, 'currentCount' => $currentCount, 'limit' => $this->dailyLimit];
            }

            // Atomic increment
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, (string) ($currentCount + 1));
            fflush($fp);
            flock($fp, LOCK_UN);

            return ['allowed' => true, 'currentCount' => $currentCount + 1, 'limit' => $this->dailyLimit];
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /**
     * Get the current count without incrementing.
     */
    public function getCurrentCount(int $contextId): int
    {
        $countFile = $this->getCountFilePath($contextId);
        if (!file_exists($countFile)) {
            return 0;
        }
        return (int) file_get_contents($countFile);
    }

    /**
     * Build the rate limit file path for a given context and date.
     */
    public function getCountFilePath(int $contextId, ?string $date = null): string
    {
        $date = $date ?? date('Ymd');
        return $this->tempDir . '/nv_ratelimit_' . $contextId . '_' . $date;
    }
}
