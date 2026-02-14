<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Crawler;
use App\Database;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class CrawlerTest extends TestCase
{
    private int $testJobId;
    private Crawler $crawler;

    protected function setUp(): void
    {
        $db = Database::getInstance();

        // Create a test job
        $stmt = $db->prepare("INSERT INTO crawl_jobs (domain, status) VALUES (?, 'pending')");
        $stmt->execute(['https://example.com']);
        $lastId = $db->lastInsertId();
        $this->testJobId = is_numeric($lastId) ? (int)$lastId : 0;

        $this->crawler = new Crawler($this->testJobId);
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
        $this->assertInstanceOf(Crawler::class, $this->crawler);
    }

    public function testCrawlerCreatesJobWithCorrectStatus(): void
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT status FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$this->testJobId]);
        $job = $stmt->fetch();

        $this->assertEquals('pending', $job['status']);
    }

    /**
     * Test favicon extraction from rel="icon"
     */
    public function testExtractFaviconFromRelIcon(): void
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <link rel="icon" href="/favicon.ico">
        </head>
        </html>
        HTML;

        $domCrawler = new DomCrawler($html, 'https://example.com');
        $favicon = $this->callPrivateMethod($this->crawler, 'extractFavicon', [$domCrawler, 'https://example.com']);

        $this->assertNotNull($favicon);
        $this->assertStringContainsString('favicon.ico', $favicon);
    }

    /**
     * Test favicon extraction from rel="shortcut icon"
     */
    public function testExtractFaviconFromShortcutIcon(): void
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <link rel="shortcut icon" href="/shortcut-icon.ico">
        </head>
        </html>
        HTML;

        $domCrawler = new DomCrawler($html, 'https://example.com');
        $favicon = $this->callPrivateMethod($this->crawler, 'extractFavicon', [$domCrawler, 'https://example.com']);

        $this->assertNotNull($favicon);
        $this->assertStringContainsString('shortcut-icon.ico', $favicon);
    }

    /**
     * Test favicon extraction from rel="apple-touch-icon"
     */
    public function testExtractFaviconFromAppleTouchIcon(): void
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <link rel="apple-touch-icon" href="/apple-icon.png">
        </head>
        </html>
        HTML;

        $domCrawler = new DomCrawler($html, 'https://example.com');
        $favicon = $this->callPrivateMethod($this->crawler, 'extractFavicon', [$domCrawler, 'https://example.com']);

        $this->assertNotNull($favicon);
        $this->assertStringContainsString('apple-icon.png', $favicon);
    }

    /**
     * Test absolute favicon URL is preserved
     */
    public function testExtractFaviconAbsoluteUrl(): void
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <link rel="icon" href="https://cdn.example.com/favicon.ico">
        </head>
        </html>
        HTML;

        $domCrawler = new DomCrawler($html, 'https://example.com');
        $favicon = $this->callPrivateMethod($this->crawler, 'extractFavicon', [$domCrawler, 'https://example.com']);

        $this->assertEquals('https://cdn.example.com/favicon.ico', $favicon);
    }

    /**
     * Test relative favicon URL is converted to absolute
     */
    public function testExtractFaviconRelativeUrl(): void
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <link rel="icon" href="/static/favicon.ico">
        </head>
        </html>
        HTML;

        $domCrawler = new DomCrawler($html, 'https://example.com/page/');
        $favicon = $this->callPrivateMethod($this->crawler, 'extractFavicon', [$domCrawler, 'https://example.com/page/']);

        $this->assertStringStartsWith('https://example.com', $favicon);
        $this->assertStringContainsString('static/favicon.ico', $favicon);
    }

    /**
     * Test default favicon fallback
     */
    public function testExtractFaviconDefaultFallback(): void
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
        </head>
        </html>
        HTML;

        $domCrawler = new DomCrawler($html, 'https://example.com');
        $favicon = $this->callPrivateMethod($this->crawler, 'extractFavicon', [$domCrawler, 'https://example.com']);

        $this->assertNotNull($favicon);
        $this->assertStringContainsString('/favicon.ico', $favicon);
    }

    /**
     * Helper method to call private methods for testing
     */
    private function callPrivateMethod($object, $methodName, $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}

