<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Crawler;
use App\Database;

class CrawlerTest extends TestCase
{
    private int $testJobId;

    protected function setUp(): void
    {
        $db = Database::getInstance();

        // Create a test job
        $stmt = $db->prepare("INSERT INTO crawl_jobs (domain, status) VALUES (?, 'pending')");
        $stmt->execute(['https://example.com']);
        $lastId = $db->lastInsertId();
        $this->testJobId = is_numeric($lastId) ? (int)$lastId : 0;
    }

    protected function tearDown(): void
    {
        $db = Database::getInstance();

        // Clean up test data
        $stmt = $db->prepare("DELETE FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$this->testJobId]);
    }

    public function testCrawlerCanBeInstantiated(): void
    {
        $crawler = new Crawler($this->testJobId);
        $this->assertInstanceOf(Crawler::class, $crawler);
    }

    public function testCrawlerCreatesJobWithCorrectStatus(): void
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT status FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$this->testJobId]);
        $job = $stmt->fetch();

        $this->assertEquals('pending', $job['status']);
    }
}
