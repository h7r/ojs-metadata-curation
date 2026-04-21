<?php

/**
 * P0 — Rate limiter tests.
 *
 * Verifies atomic flock()-based rate limiting: daily counter, limit
 * enforcement, reset on new day, and fail-open behavior.
 */

namespace APP\plugins\generic\nvMetadataCuration\tests;

use APP\plugins\generic\nvMetadataCuration\classes\managers\RateLimitManager;
use PHPUnit\Framework\TestCase;

class RateLimitManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/nv_ratelimit_test_' . getmypid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up counter files
        $files = glob($this->tempDir . '/nv_ratelimit_*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tempDir);
    }

    // ── Basic counting ──────────────────────────────────────────────

    public function testFirstRequestIsAllowed(): void
    {
        $limiter = new RateLimitManager(50, $this->tempDir);
        $result = $limiter->check(1);

        $this->assertTrue($result['allowed']);
        $this->assertSame(1, $result['currentCount']);
        $this->assertSame(50, $result['limit']);
    }

    public function testCounterIncrements(): void
    {
        $limiter = new RateLimitManager(50, $this->tempDir);

        $limiter->check(1);
        $limiter->check(1);
        $result = $limiter->check(1);

        $this->assertTrue($result['allowed']);
        $this->assertSame(3, $result['currentCount']);
    }

    // ── Limit enforcement ───────────────────────────────────────────

    public function testRequestBlockedAtLimit(): void
    {
        $limiter = new RateLimitManager(3, $this->tempDir);

        $limiter->check(1); // 1
        $limiter->check(1); // 2
        $limiter->check(1); // 3 — at limit

        $result = $limiter->check(1); // 4 — should be blocked
        $this->assertFalse($result['allowed']);
        $this->assertSame(3, $result['currentCount']);
    }

    public function testExactLimitIsReached(): void
    {
        $limit = 5;
        $limiter = new RateLimitManager($limit, $this->tempDir);

        for ($i = 0; $i < $limit; $i++) {
            $result = $limiter->check(1);
            $this->assertTrue($result['allowed'], "Request {$i} should be allowed");
        }

        // One more should be blocked
        $result = $limiter->check(1);
        $this->assertFalse($result['allowed']);
    }

    // ── Context isolation ───────────────────────────────────────────

    public function testDifferentContextsHaveSeparateCounters(): void
    {
        $limiter = new RateLimitManager(2, $this->tempDir);

        $limiter->check(1);
        $limiter->check(1);

        // Context 1 is at limit
        $result1 = $limiter->check(1);
        $this->assertFalse($result1['allowed']);

        // Context 2 still has quota
        $result2 = $limiter->check(2);
        $this->assertTrue($result2['allowed']);
    }

    // ── Daily reset (different date → different file) ───────────────

    public function testDifferentDaysUseDifferentFiles(): void
    {
        $limiter = new RateLimitManager(50, $this->tempDir);

        $path1 = $limiter->getCountFilePath(1, '20260422');
        $path2 = $limiter->getCountFilePath(1, '20260423');

        $this->assertNotSame($path1, $path2);
        $this->assertStringContainsString('20260422', $path1);
        $this->assertStringContainsString('20260423', $path2);
    }

    // ── Fail-open: unwritable directory ─────────────────────────────

    public function testFailOpenOnUnwritableDirectory(): void
    {
        // Point to a non-existent directory so fopen fails
        $limiter = new RateLimitManager(50, '/nonexistent/path/that/does/not/exist');
        $result = $limiter->check(1);

        // Should fail open: allow the request
        $this->assertTrue($result['allowed']);
    }

    // ── getCurrentCount without incrementing ────────────────────────

    public function testGetCurrentCountReadsWithoutIncrementing(): void
    {
        $limiter = new RateLimitManager(50, $this->tempDir);

        $this->assertSame(0, $limiter->getCurrentCount(1));

        $limiter->check(1);
        $limiter->check(1);

        $this->assertSame(2, $limiter->getCurrentCount(1));

        // Reading should not increment
        $this->assertSame(2, $limiter->getCurrentCount(1));
    }

    // ── Concurrent access simulation ────────────────────────────────

    /**
     * Simulates rapid sequential access to verify counter integrity.
     * True concurrency would require forking, but sequential stress
     * validates the atomic read-increment-write logic.
     */
    public function testRapidSequentialAccessMaintainsCounterIntegrity(): void
    {
        $limit = 100;
        $limiter = new RateLimitManager($limit, $this->tempDir);

        $allowed = 0;
        $blocked = 0;

        for ($i = 0; $i < $limit + 20; $i++) {
            $result = $limiter->check(1);
            if ($result['allowed']) {
                $allowed++;
            } else {
                $blocked++;
            }
        }

        $this->assertSame($limit, $allowed);
        $this->assertSame(20, $blocked);
        $this->assertSame($limit, $limiter->getCurrentCount(1));
    }

    // ── File path predictability (security note) ────────────────────

    public function testCounterFilePathContainsContextId(): void
    {
        $limiter = new RateLimitManager(50, $this->tempDir);
        $path = $limiter->getCountFilePath(42);

        $this->assertStringContainsString('_42_', $path);
        $this->assertStringStartsWith($this->tempDir, $path);
    }
}
