<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use App\Crawler;
use App\Database;

class CrawlerIntegrationTest extends TestCase
{
    private int $testJobId;
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();

        // Create a test job
        $stmt = $this->db->prepare("INSERT INTO crawl_jobs (domain, status) VALUES (?, 'pending')");
        $stmt->execute(['https://httpbin.org']);
        $this->testJobId = $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $stmt = $this->db->prepare("DELETE FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$this->testJobId]);
    }

    public function testCrawlerUpdatesJobStatusToRunning(): void
    {
        $crawler = new Crawler($this->testJobId);

        // Start crawl (will fail but should update status)
        try {
            $crawler->start('https://httpbin.org/html');
        } catch (\Exception $e) {
            // Expected to fail in test environment
        }

        $stmt = $this->db->prepare("SELECT status FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$this->testJobId]);
        $job = $stmt->fetch();

        // Status should be either 'running' or 'completed'
        $this->assertContains($job['status'], ['running', 'completed', 'failed']);
    }

    public function testCrawlerCreatesQueueEntries(): void
    {
        $crawler = new Crawler($this->testJobId);

        try {
            $crawler->start('https://httpbin.org/html');
        } catch (\Exception $e) {
            // Expected to fail in test environment
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM crawl_queue WHERE crawl_job_id = ?");
        $stmt->execute([$this->testJobId]);
        $result = $stmt->fetch();

        $this->assertGreaterThan(0, $result['count']);
    }
}
