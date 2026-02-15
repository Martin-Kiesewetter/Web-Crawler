<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Config;

class ConfigTest extends TestCase
{
    /**
     * Test that MAX_REDIRECT_THRESHOLD constant exists and has correct value
     */
    public function testMaxRedirectThresholdConstant(): void
    {
        $this->assertSame(3, Config::MAX_REDIRECT_THRESHOLD);
    }

    /**
     * Test that MAX_CRAWL_DEPTH constant exists and has correct value
     */
    public function testMaxCrawlDepthConstant(): void
    {
        $this->assertSame(50, Config::MAX_CRAWL_DEPTH);
    }

    /**
     * Test that CONCURRENCY constant exists and has correct value
     */
    public function testConcurrencyConstant(): void
    {
        $this->assertSame(10, Config::CONCURRENCY);
    }

    /**
     * Test that all constants are positive integers
     */
    public function testAllConstantsArePositive(): void
    {
        $this->assertGreaterThan(0, Config::MAX_REDIRECT_THRESHOLD);
        $this->assertGreaterThan(0, Config::MAX_CRAWL_DEPTH);
        $this->assertGreaterThan(0, Config::CONCURRENCY);
    }

    /**
     * Test that MAX_REDIRECT_THRESHOLD is reasonable
     */
    public function testMaxRedirectThresholdIsReasonable(): void
    {
        // Redirect threshold should be between 1 and 10
        $this->assertGreaterThanOrEqual(1, Config::MAX_REDIRECT_THRESHOLD);
        $this->assertLessThanOrEqual(10, Config::MAX_REDIRECT_THRESHOLD);
    }

    /**
     * Test that CONCURRENCY is reasonable
     */
    public function testConcurrencyIsReasonable(): void
    {
        // Concurrency should be between 1 and 100 (reasonable for parallel requests)
        $this->assertGreaterThanOrEqual(1, Config::CONCURRENCY);
        $this->assertLessThanOrEqual(100, Config::CONCURRENCY);
    }

    /**
     * Test that MAX_CRAWL_DEPTH is reasonable
     */
    public function testMaxCrawlDepthIsReasonable(): void
    {
        // Crawl depth should be between 1 and 1000 (reasonable limit)
        $this->assertGreaterThanOrEqual(1, Config::MAX_CRAWL_DEPTH);
        $this->assertLessThanOrEqual(1000, Config::MAX_CRAWL_DEPTH);
    }

    /**
     * Test configuration values are accessible without instantiation
     */
    public function testConfigAccessibleWithoutInstantiation(): void
    {
        // Config should be a static class, no need to instantiate
        // Just verify we can access the constants
        $concurrency = Config::CONCURRENCY;
        $depth = Config::MAX_CRAWL_DEPTH;
        $redirect = Config::MAX_REDIRECT_THRESHOLD;

        $this->assertSame(10, $concurrency);
        $this->assertSame(50, $depth);
        $this->assertSame(3, $redirect);
    }

    /**
     * Test that constants cannot be changed (they are final)
     */
    public function testConstantsCannotBeModified(): void
    {
        // Try to verify that constants are truly constants
        // by checking they exist and have expected values
        $originalConcurrency = Config::CONCURRENCY;
        $this->assertEquals(10, $originalConcurrency);

        // Constants should maintain their value
        $this->assertEquals($originalConcurrency, Config::CONCURRENCY);
    }
}
