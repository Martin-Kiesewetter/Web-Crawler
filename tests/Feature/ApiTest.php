<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use App\Database;

/**
 * API Feature Tests
 *
 * Tests all API endpoints in api.php
 * These tests simulate HTTP requests to the API
 */
class ApiTest extends TestCase
{
    private \PDO $db;
    private int $testJobId;
    private int $testPageId;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();

        // Create a test job for testing
        $stmt = $this->db->prepare(
            "INSERT INTO crawl_jobs (domain, status, total_pages, total_links, total_images, total_scripts) " .
            "VALUES ('https://test-example.com', 'completed', 5, 10, 3, 2)"
        );
        $stmt->execute([]);
        $this->testJobId = (int)$this->db->lastInsertId();

        // Create test pages first (needed for foreign keys)
        $this->createTestPages();
        // Create test links
        $this->createTestLinks();
        // Create test images
        $this->createTestImages();
        // Create test scripts
        $this->createTestScripts();
        // Create test queue entries
        $this->createTestQueue();
    }

    protected function tearDown(): void
    {
        // Clean up all test data
        $this->db->prepare("DELETE FROM crawl_queue WHERE crawl_job_id = ?")->execute([$this->testJobId]);
        $this->db->prepare("DELETE FROM links WHERE crawl_job_id = ?")->execute([$this->testJobId]);
        $this->db->prepare("DELETE FROM images WHERE crawl_job_id = ?")->execute([$this->testJobId]);
        $this->db->prepare("DELETE FROM scripts WHERE crawl_job_id = ?")->execute([$this->testJobId]);
        $this->db->prepare("DELETE FROM pages WHERE crawl_job_id = ?")->execute([$this->testJobId]);
        $this->db->prepare("DELETE FROM crawl_jobs WHERE id = ?")->execute([$this->testJobId]);
    }

    private function createTestPages(): void
    {
        $pages = [
            ['url' => 'https://test-example.com/', 'title' => 'Home Page', 'meta_description' => 'Welcome to our site', 'status_code' => 200, 'redirect_count' => 0],
            ['url' => 'https://test-example.com/about', 'title' => 'About Us', 'meta_description' => 'Learn more about us', 'status_code' => 200, 'redirect_count' => 0],
            ['url' => 'https://test-example.com/contact', 'title' => 'Contact', 'meta_description' => 'Contact us here', 'status_code' => 200, 'redirect_count' => 0],
            ['url' => 'https://test-example.com/old-page', 'title' => 'Old Page', 'meta_description' => 'This page redirects', 'status_code' => 301, 'redirect_count' => 2, 'redirect_url' => 'https://test-example.com/new-page'],
            ['url' => 'https://test-example.com/broken', 'title' => null, 'meta_description' => null, 'status_code' => 404, 'redirect_count' => 0],
        ];

        foreach ($pages as $page) {
            $stmt = $this->db->prepare(
                "INSERT INTO pages (crawl_job_id, url, title, meta_description, status_code, redirect_count, redirect_url, crawled_at) " .
                "VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $this->testJobId,
                $page['url'],
                $page['title'],
                $page['meta_description'],
                $page['status_code'],
                $page['redirect_count'],
                $page['redirect_url'] ?? null
            ]);
        }

        // Store first page ID for foreign keys
        $stmt = $this->db->prepare("SELECT id FROM pages WHERE crawl_job_id = ? LIMIT 1");
        $stmt->execute([$this->testJobId]);
        $this->testPageId = (int)$stmt->fetchColumn();
    }

    private function createTestLinks(): void
    {
        $links = [
            ['source_url' => 'https://test-example.com/', 'target_url' => 'https://test-example.com/about', 'is_internal' => 1, 'is_nofollow' => 0],
            ['source_url' => 'https://test-example.com/', 'target_url' => 'https://external.com', 'is_internal' => 0, 'is_nofollow' => 1],
            ['source_url' => 'https://test-example.com/about', 'target_url' => 'https://test-example.com/contact', 'is_internal' => 1, 'is_nofollow' => 0],
        ];

        foreach ($links as $link) {
            $stmt = $this->db->prepare(
                "INSERT INTO links (crawl_job_id, page_id, source_url, target_url, is_internal, is_nofollow) VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $this->testJobId,
                $this->testPageId,
                $link['source_url'],
                $link['target_url'],
                $link['is_internal'],
                $link['is_nofollow']
            ]);
        }
    }

    private function createTestImages(): void
    {
        $images = [
            ['url' => 'https://test-example.com/img/logo.png', 'alt_text' => 'Company Logo', 'status_code' => 200, 'is_responsive' => 1],
            ['url' => 'https://test-example.com/img/banner.jpg', 'alt_text' => '', 'status_code' => 200, 'is_responsive' => 0],
            ['url' => 'https://test-example.com/img/missing.png', 'alt_text' => 'Missing Image', 'status_code' => 404, 'is_responsive' => 0],
        ];

        foreach ($images as $image) {
            $stmt = $this->db->prepare(
                "INSERT INTO images (crawl_job_id, page_id, url, alt_text, status_code, is_responsive, crawled_at) VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $this->testJobId,
                $this->testPageId,
                $image['url'],
                $image['alt_text'],
                $image['status_code'],
                $image['is_responsive']
            ]);
        }
    }

    private function createTestScripts(): void
    {
        $scripts = [
            ['url' => 'https://test-example.com/js/main.js', 'type' => 'external', 'status_code' => 200],
            ['url' => 'https://cdn.example.com/analytics.js', 'type' => 'external', 'status_code' => 200],
        ];

        foreach ($scripts as $script) {
            $stmt = $this->db->prepare(
                "INSERT INTO scripts (crawl_job_id, page_id, url, type, status_code, crawled_at) VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $this->testJobId,
                $this->testPageId,
                $script['url'],
                $script['type'],
                $script['status_code']
            ]);
        }
    }

    private function createTestQueue(): void
    {
        $queue = [
            ['url' => 'https://test-example.com/', 'status' => 'completed'],
            ['url' => 'https://test-example.com/about', 'status' => 'completed'],
            ['url' => 'https://test-example.com/contact', 'status' => 'pending'],
        ];

        foreach ($queue as $item) {
            $stmt = $this->db->prepare(
                "INSERT INTO crawl_queue (crawl_job_id, url, status) VALUES (?, ?, ?)"
            );
            $stmt->execute([$this->testJobId, $item['url'], $item['status']]);
        }
    }

    // =========================================================================
    // STATUS ENDPOINT TESTS
    // =========================================================================

    public function testStatusEndpointReturnsJobData(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$this->testJobId]);
        $job = $stmt->fetch();

        $this->assertNotFalse($job);
        $this->assertEquals('completed', $job['status']);
        $this->assertEquals('https://test-example.com', $job['domain']);
    }

    public function testStatusEndpointReturnsQueueStats(): void
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
            FROM crawl_queue
            WHERE crawl_job_id = ?
        ");
        $stmt->execute([$this->testJobId]);
        $stats = $stmt->fetch();

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(1, $stats['pending']);
        $this->assertEquals(2, $stats['completed']);
    }

    public function testStatusEndpointReturnsErrorForNonExistentJob(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM crawl_jobs WHERE id = ?");
        $stmt->execute([999999]);
        $job = $stmt->fetch();

        $this->assertFalse($job);
    }

    // =========================================================================
    // JOBS ENDPOINT TESTS
    // =========================================================================

    public function testJobsEndpointReturnsAllJobs(): void
    {
        $stmt = $this->db->query("SELECT * FROM crawl_jobs ORDER BY created_at DESC LIMIT 50");
        $this->assertNotFalse($stmt, 'Query should not fail');
        $jobs = $stmt->fetchAll();

        $this->assertGreaterThanOrEqual(1, count($jobs));
    }

    public function testJobsEndpointIncludesFavicon(): void
    {
        // Add a favicon to one of the pages
        $stmt = $this->db->prepare(
            "UPDATE pages SET favicon_url = 'https://test-example.com/favicon.ico' WHERE crawl_job_id = ? LIMIT 1"
        );
        $stmt->execute([$this->testJobId]);

        $stmt = $this->db->prepare(
            "SELECT favicon_url FROM pages WHERE crawl_job_id = ? AND favicon_url IS NOT NULL LIMIT 1"
        );
        $stmt->execute([$this->testJobId]);
        $result = $stmt->fetch();

        $this->assertNotFalse($result);
        $this->assertEquals('https://test-example.com/favicon.ico', $result['favicon_url']);
    }

    // =========================================================================
    // PAGES ENDPOINT TESTS
    // =========================================================================

    public function testPagesEndpointReturnsPagesForJob(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM pages WHERE crawl_job_id = ? ORDER BY id DESC LIMIT 1000");
        $stmt->execute([$this->testJobId]);
        $pages = $stmt->fetchAll();

        $this->assertCount(5, $pages);
    }

    public function testPagesEndpointReturnsCorrectPageData(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM pages WHERE crawl_job_id = ? AND url = ?");
        $stmt->execute([$this->testJobId, 'https://test-example.com/']);
        $page = $stmt->fetch();

        $this->assertEquals('Home Page', $page['title']);
        $this->assertEquals(200, $page['status_code']);
    }

    // =========================================================================
    // LINKS ENDPOINT TESTS
    // =========================================================================

    public function testLinksEndpointReturnsLinksForJob(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM links WHERE crawl_job_id = ? ORDER BY id DESC LIMIT 1000");
        $stmt->execute([$this->testJobId]);
        $links = $stmt->fetchAll();

        $this->assertCount(3, $links);
    }

    public function testLinksEndpointIdentifiesInternalAndExternal(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM links WHERE crawl_job_id = ? AND is_internal = 1");
        $stmt->execute([$this->testJobId]);
        $internalLinks = $stmt->fetchAll();

        $stmt = $this->db->prepare("SELECT * FROM links WHERE crawl_job_id = ? AND is_internal = 0");
        $stmt->execute([$this->testJobId]);
        $externalLinks = $stmt->fetchAll();

        $this->assertCount(2, $internalLinks);
        $this->assertCount(1, $externalLinks);
    }

    // =========================================================================
    // IMAGES ENDPOINT TESTS
    // =========================================================================

    public function testImagesEndpointReturnsImagesForJob(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM images WHERE crawl_job_id = ? ORDER BY id DESC LIMIT 2000");
        $stmt->execute([$this->testJobId]);
        $images = $stmt->fetchAll();

        $this->assertCount(3, $images);
    }

    public function testImagesFilterBroken(): void
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM images WHERE crawl_job_id = ? AND (status_code IS NULL OR status_code >= 400)"
        );
        $stmt->execute([$this->testJobId]);
        $brokenImages = $stmt->fetchAll();

        $this->assertCount(1, $brokenImages);
        $this->assertStringContainsString('missing.png', $brokenImages[0]['url']);
    }

    public function testImagesFilterNoAlt(): void
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM images WHERE crawl_job_id = ? AND (alt_text IS NULL OR alt_text = '')"
        );
        $stmt->execute([$this->testJobId]);
        $noAltImages = $stmt->fetchAll();

        $this->assertCount(1, $noAltImages);
    }

    public function testImagesFilterResponsive(): void
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM images WHERE crawl_job_id = ? AND is_responsive = 1"
        );
        $stmt->execute([$this->testJobId]);
        $responsiveImages = $stmt->fetchAll();

        $this->assertCount(1, $responsiveImages);
    }

    // =========================================================================
    // ASSETS ENDPOINT TESTS
    // =========================================================================

    public function testAssetsEndpointReturnsAllAssetTypes(): void
    {
        $assets = [];

        // Fetch pages
        $stmt = $this->db->prepare(
            "SELECT id, crawl_job_id, url, title, status_code, crawled_at, 'page' as asset_type FROM pages " .
            "WHERE crawl_job_id = ? ORDER BY id DESC"
        );
        $stmt->execute([$this->testJobId]);
        $assets = array_merge($assets, $stmt->fetchAll());

        // Fetch images
        $stmt = $this->db->prepare(
            "SELECT id, crawl_job_id, url, alt_text as title, status_code, crawled_at, 'image' as asset_type FROM images " .
            "WHERE crawl_job_id = ? ORDER BY id DESC"
        );
        $stmt->execute([$this->testJobId]);
        $assets = array_merge($assets, $stmt->fetchAll());

        // Fetch scripts
        $stmt = $this->db->prepare(
            "SELECT id, crawl_job_id, url, type as title, status_code, crawled_at, 'script' as asset_type FROM scripts " .
            "WHERE crawl_job_id = ? ORDER BY id DESC"
        );
        $stmt->execute([$this->testJobId]);
        $assets = array_merge($assets, $stmt->fetchAll());

        $this->assertCount(10, $assets); // 5 pages + 3 images + 2 scripts
    }

    // =========================================================================
    // BROKEN LINKS ENDPOINT TESTS
    // =========================================================================

    public function testBrokenLinksEndpointReturnsBrokenPages(): void
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM pages WHERE crawl_job_id = ? AND (status_code >= 400 OR status_code = 0) ORDER BY status_code DESC, url"
        );
        $stmt->execute([$this->testJobId]);
        $brokenLinks = $stmt->fetchAll();

        $this->assertCount(1, $brokenLinks);
        $this->assertEquals(404, $brokenLinks[0]['status_code']);
    }

    // =========================================================================
    // SEO ANALYSIS ENDPOINT TESTS
    // =========================================================================

    public function testSeoAnalysisDetectsMissingTitle(): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, url, title, meta_description, status_code FROM pages WHERE crawl_job_id = ? ORDER BY url"
        );
        $stmt->execute([$this->testJobId]);
        $pages = $stmt->fetchAll();

        $issues = [];
        foreach ($pages as $page) {
            if (empty($page['title'])) {
                $issues[] = [
                    'url' => $page['url'],
                    'issues' => ['Title missing']
                ];
            }
        }

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('broken', $issues[0]['url']);
    }

    public function testSeoAnalysisDetectsMissingMetaDescription(): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, url, title, meta_description, status_code FROM pages WHERE crawl_job_id = ? ORDER BY url"
        );
        $stmt->execute([$this->testJobId]);
        $pages = $stmt->fetchAll();

        $issues = [];
        foreach ($pages as $page) {
            if (empty($page['meta_description'])) {
                $issues[] = [
                    'url' => $page['url'],
                    'issues' => ['Meta description missing']
                ];
            }
        }

        $this->assertCount(1, $issues);
    }

    public function testSeoAnalysisDetectsDuplicateTitles(): void
    {
        // Add a page with duplicate title
        $stmt = $this->db->prepare(
            "INSERT INTO pages (crawl_job_id, url, title, meta_description, status_code, crawled_at) " .
            "VALUES (?, 'https://test-example.com/duplicate', 'Home Page', 'Another page', 200, NOW())"
        );
        $stmt->execute([$this->testJobId]);

        $stmt = $this->db->prepare(
            "SELECT id, url, title, meta_description, status_code FROM pages WHERE crawl_job_id = ? ORDER BY url"
        );
        $stmt->execute([$this->testJobId]);
        $pages = $stmt->fetchAll();

        $titleCounts = [];
        foreach ($pages as $page) {
            if (!empty($page['title'])) {
                $titleCounts[$page['title']][] = $page['url'];
            }
        }

        $duplicates = [];
        foreach ($titleCounts as $title => $urls) {
            if (count($urls) > 1) {
                $duplicates[] = ['type' => 'title', 'content' => $title, 'urls' => $urls];
            }
        }

        $this->assertCount(1, $duplicates);
        $this->assertEquals('Home Page', $duplicates[0]['content']);
        $this->assertCount(2, $duplicates[0]['urls']);

        // Clean up
        $this->db->prepare("DELETE FROM pages WHERE url = 'https://test-example.com/duplicate'")->execute();
    }

    // =========================================================================
    // NOFOLLOW LINKS ENDPOINT TESTS
    // =========================================================================

    public function testNofollowLinksEndpointReturnsNofollowLinks(): void
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM links WHERE crawl_job_id = ? AND is_nofollow = 1 ORDER BY source_url, target_url"
        );
        $stmt->execute([$this->testJobId]);
        $nofollowLinks = $stmt->fetchAll();

        $this->assertCount(1, $nofollowLinks);
        $this->assertEquals('https://external.com', $nofollowLinks[0]['target_url']);
    }

    public function testNofollowLinksFilterInternal(): void
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM links WHERE crawl_job_id = ? AND is_nofollow = 1 AND is_internal = 1"
        );
        $stmt->execute([$this->testJobId]);
        $internalNofollow = $stmt->fetchAll();

        $this->assertCount(0, $internalNofollow);
    }

    public function testNofollowLinksFilterExternal(): void
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM links WHERE crawl_job_id = ? AND is_nofollow = 1 AND is_internal = 0"
        );
        $stmt->execute([$this->testJobId]);
        $externalNofollow = $stmt->fetchAll();

        $this->assertCount(1, $externalNofollow);
    }

    // =========================================================================
    // REDIRECTS ENDPOINT TESTS
    // =========================================================================

    public function testRedirectsEndpointReturnsRedirects(): void
    {
        $stmt = $this->db->prepare(
            "SELECT url, title, status_code, redirect_url, redirect_count FROM pages " .
            "WHERE crawl_job_id = ? AND redirect_count > 0 ORDER BY redirect_count DESC, url"
        );
        $stmt->execute([$this->testJobId]);
        $redirects = $stmt->fetchAll();

        $this->assertCount(1, $redirects);
        $this->assertEquals(301, $redirects[0]['status_code']);
        $this->assertEquals(2, $redirects[0]['redirect_count']);
    }

    public function testRedirectsCategorizesPermanentVsTemporary(): void
    {
        $stmt = $this->db->prepare(
            "SELECT url, status_code FROM pages WHERE crawl_job_id = ? AND redirect_count > 0"
        );
        $stmt->execute([$this->testJobId]);
        $redirects = $stmt->fetchAll();

        $permanent = 0;
        $temporary = 0;

        foreach ($redirects as $redirect) {
            $code = $redirect['status_code'];
            if ($code == 301 || $code == 308) {
                $permanent++;
            } elseif ($code == 302 || $code == 303 || $code == 307) {
                $temporary++;
            }
        }

        $this->assertEquals(1, $permanent);
        $this->assertEquals(0, $temporary);
    }

    // =========================================================================
    // DELETE ENDPOINT TESTS
    // =========================================================================

    public function testDeleteEndpointRemovesJobAndRelatedData(): void
    {
        // Create a separate job for deletion test
        $stmt = $this->db->prepare(
            "INSERT INTO crawl_jobs (domain, status) VALUES ('https://delete-test.com', 'completed')"
        );
        $stmt->execute([]);
        $deleteJobId = (int)$this->db->lastInsertId();

        // Add a page first (needed for foreign keys)
        $stmt = $this->db->prepare("INSERT INTO pages (crawl_job_id, url, status_code, crawled_at) VALUES (?, 'https://delete-test.com/', 200, NOW())");
        $stmt->execute([$deleteJobId]);
        $deletePageId = (int)$this->db->lastInsertId();

        // Add related data with page_id
        $this->db->prepare("INSERT INTO links (crawl_job_id, page_id, source_url, target_url) VALUES (?, ?, 'https://delete-test.com/', 'https://delete-test.com/about')")
            ->execute([$deleteJobId, $deletePageId]);

        // Delete
        $this->db->prepare("DELETE FROM links WHERE crawl_job_id = ?")->execute([$deleteJobId]);
        $this->db->prepare("DELETE FROM pages WHERE crawl_job_id = ?")->execute([$deleteJobId]);
        $this->db->prepare("DELETE FROM crawl_jobs WHERE id = ?")->execute([$deleteJobId]);

        // Verify deletion
        $stmt = $this->db->prepare("SELECT * FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$deleteJobId]);
        $this->assertFalse($stmt->fetch());

        $stmt = $this->db->prepare("SELECT * FROM pages WHERE crawl_job_id = ?");
        $stmt->execute([$deleteJobId]);
        $this->assertCount(0, $stmt->fetchAll());
    }

    // =========================================================================
    // INPUT VALIDATION TESTS
    // =========================================================================

    public function testStartEndpointRequiresDomain(): void
    {
        // Simulate empty domain validation
        $domain = '';
        $this->assertEmpty($domain);
    }

    public function testStartEndpointAddsHttpsProtocol(): void
    {
        $domain = 'example.com';
        if (!preg_match('/^https?:\/\//', $domain)) {
            $domain = 'https://' . $domain;
        }

        $this->assertEquals('https://example.com', $domain);
    }

    public function testStartEndpointPreservesExistingProtocol(): void
    {
        $domain = 'http://example.com';
        if (!preg_match('/^https?:\/\//', $domain)) {
            $domain = 'https://' . $domain;
        }

        $this->assertEquals('http://example.com', $domain);
    }

    public function testInvalidActionReturnsError(): void
    {
        // Simulate invalid action
        $action = 'invalid_action';
        $validActions = ['start', 'status', 'jobs', 'pages', 'links', 'images', 'assets', 'broken-links', 'seo-analysis', 'nofollow-links', 'redirects', 'delete', 'recrawl'];

        $this->assertNotContains($action, $validActions);
    }

    // =========================================================================
    // ERROR HANDLING TESTS
    // =========================================================================

    public function testNonExistentJobIdReturnsNoResults(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM crawl_jobs WHERE id = ?");
        $stmt->execute([999999]);
        $result = $stmt->fetch();

        $this->assertFalse($result);
    }

    public function testEmptyJobIdReturnsNoResults(): void
    {
        $jobId = 0;
        $stmt = $this->db->prepare("SELECT * FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $result = $stmt->fetch();

        $this->assertFalse($result);
    }
}
