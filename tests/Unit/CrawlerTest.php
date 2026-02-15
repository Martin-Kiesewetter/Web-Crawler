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
        
        // Initialize baseDomain for tests that need it
        $this->setPrivateProperty($this->crawler, 'baseDomain', 'example.com');
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
     * Test that image URLs are correctly identified
     */
    public function testIsImageUrl(): void
    {
        $imageUrls = [
            'https://example.com/image.jpg',
            'https://example.com/photo.jpeg',
            'https://example.com/graphic.png',
            'https://example.com/animation.gif',
            'https://example.com/modern.webp',
            'https://example.com/vector.svg',
            'https://example.com/icon.ico',
        ];

        foreach ($imageUrls as $url) {
            $result = $this->callPrivateMethod($this->crawler, 'isImageUrl', [$url]);
            $this->assertTrue($result, "Failed to identify $url as image");
        }

        // Test non-image URLs
        $nonImageUrls = [
            'https://example.com/page.html',
            'https://example.com/script.js',
            'https://example.com/style.css',
        ];

        foreach ($nonImageUrls as $url) {
            $result = $this->callPrivateMethod($this->crawler, 'isImageUrl', [$url]);
            $this->assertFalse($result, "Incorrectly identified $url as image");
        }
    }

    /**
     * Test that script URLs are correctly identified
     */
    public function testIsScriptUrl(): void
    {
        $scriptUrls = [
            'https://example.com/app.js',
            'https://example.com/module.mjs',
            'https://example.com/component.jsx',
        ];

        foreach ($scriptUrls as $url) {
            $result = $this->callPrivateMethod($this->crawler, 'isScriptUrl', [$url]);
            $this->assertTrue($result, "Failed to identify $url as script");
        }

        // Test non-script URLs
        $nonScriptUrls = [
            'https://example.com/page.html',
            'https://example.com/image.png',
            'https://example.com/style.css',
        ];

        foreach ($nonScriptUrls as $url) {
            $result = $this->callPrivateMethod($this->crawler, 'isScriptUrl', [$url]);
            $this->assertFalse($result, "Incorrectly identified $url as script");
        }
    }

    /**
     * Test that images in <a> tags are not extracted as links
     */
    public function testImageInAnchorTagNotExtractedAsLink(): void
    {
        $db = Database::getInstance();

        // Create a test page
        $stmt = $db->prepare(
            "INSERT INTO pages (crawl_job_id, url, title, status_code) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$this->testJobId, 'https://example.com/test', 'Test Page', 200]);
        $pageId = (int)$db->lastInsertId();

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <body>
            <a href="https://example.com/image.jpg">
                <img src="https://example.com/thumbnail.jpg" alt="Thumbnail">
            </a>
            <a href="https://example.com/page.html">Regular Link</a>
        </body>
        </html>
        HTML;

        $domCrawler = new DomCrawler($html, 'https://example.com/test');
        $this->callPrivateMethod($this->crawler, 'extractLinks', [$domCrawler, 'https://example.com/test', $pageId, 0]);

        // Check that only the regular link was extracted, not the image URL
        $stmt = $db->prepare("SELECT target_url FROM links WHERE crawl_job_id = ?");
        $stmt->execute([$this->testJobId]);
        $links = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertCount(1, $links, 'Should only extract one link');
        $this->assertContains('https://example.com/page.html', $links);
        $this->assertNotContains('https://example.com/image.jpg', $links, 'Image URL should not be extracted as link');

        // Cleanup
        $db->prepare("DELETE FROM pages WHERE id = ?")->execute([$pageId]);
    }

    /**
     * Test that duplicate images are not crawled twice
     */
    public function testDuplicateImagesNotCrawledTwice(): void
    {
        $db = Database::getInstance();

        // Create a test page
        $stmt = $db->prepare(
            "INSERT INTO pages (crawl_job_id, url, title, status_code) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$this->testJobId, 'https://example.com/page1', 'Page 1', 200]);
        $pageId1 = (int)$db->lastInsertId();

        // Insert an image that was already crawled
        $stmt = $db->prepare(
            "INSERT INTO images (crawl_job_id, page_id, url, status_code) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$this->testJobId, $pageId1, 'https://example.com/logo.png', 200]);

        // Now try to extract the same image from another page
        $stmt = $db->prepare(
            "INSERT INTO pages (crawl_job_id, url, title, status_code) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$this->testJobId, 'https://example.com/page2', 'Page 2', 200]);
        $pageId2 = (int)$db->lastInsertId();

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <body>
            <img src="https://example.com/logo.png" alt="Logo">
        </body>
        </html>
        HTML;

        $domCrawler = new DomCrawler($html, 'https://example.com/page2');
        
        // Capture output to check for "Skipping" message
        ob_start();
        $this->callPrivateMethod($this->crawler, 'extractImages', [$domCrawler, 'https://example.com/page2', $pageId2]);
        $output = ob_get_clean();

        // Verify that the image was skipped
        $this->assertStringContainsString('Skipping already crawled image', $output ?: '');

        // Verify that only one image entry exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM images WHERE crawl_job_id = ? AND url = ?");
        $stmt->execute([$this->testJobId, 'https://example.com/logo.png']);
        $count = (int)$stmt->fetchColumn();

        $this->assertEquals(1, $count, 'Image should only be crawled once');

        // Cleanup
        $db->prepare("DELETE FROM pages WHERE id IN (?, ?)")->execute([$pageId1, $pageId2]);
    }

    /**
     * Test that duplicate scripts are not crawled twice
     */
    public function testDuplicateScriptsNotCrawledTwice(): void
    {
        $db = Database::getInstance();

        // Create a test page
        $stmt = $db->prepare(
            "INSERT INTO pages (crawl_job_id, url, title, status_code) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$this->testJobId, 'https://example.com/page1', 'Page 1', 200]);
        $pageId1 = (int)$db->lastInsertId();

        // Insert a script that was already crawled
        $stmt = $db->prepare(
            "INSERT INTO scripts (crawl_job_id, page_id, url, type, status_code) VALUES (?, ?, ?, 'external', ?)"
        );
        $stmt->execute([$this->testJobId, $pageId1, 'https://example.com/app.js', 200]);

        // Now try to extract the same script from another page
        $stmt = $db->prepare(
            "INSERT INTO pages (crawl_job_id, url, title, status_code) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$this->testJobId, 'https://example.com/page2', 'Page 2', 200]);
        $pageId2 = (int)$db->lastInsertId();

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <script src="https://example.com/app.js"></script>
        </head>
        </html>
        HTML;

        $domCrawler = new DomCrawler($html, 'https://example.com/page2');
        
        // Capture output to check for "Skipping" message
        ob_start();
        $this->callPrivateMethod($this->crawler, 'extractScripts', [$domCrawler, 'https://example.com/page2', $pageId2]);
        $output = ob_get_clean();

        // Verify that the script was skipped
        $this->assertStringContainsString('Skipping already crawled script', $output ?: '');

        // Verify that only one script entry exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM scripts WHERE crawl_job_id = ? AND url = ?");
        $stmt->execute([$this->testJobId, 'https://example.com/app.js']);
        $count = (int)$stmt->fetchColumn();

        $this->assertEquals(1, $count, 'Script should only be crawled once');

        // Cleanup
        $db->prepare("DELETE FROM pages WHERE id IN (?, ?)")->execute([$pageId1, $pageId2]);
    }

    /**
     * Helper method to call private methods for testing
     *
     * @param object $object
     * @param string $methodName
     * @param array<int, mixed> $parameters
     * @return mixed
     */
    private function callPrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Helper method to set private properties for testing
     *
     * @param object $object
     * @param string $propertyName
     * @param mixed $value
     */
    private function setPrivateProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}

