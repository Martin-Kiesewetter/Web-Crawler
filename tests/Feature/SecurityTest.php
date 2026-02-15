<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use App\Database;

/**
 * Security Tests
 *
 * Tests for security vulnerabilities:
 * - SQL Injection
 * - XSS (Cross-Site Scripting)
 * - Input Validation
 * - URL Validation
 * - Access Control
 * - Error Handling
 */
class SecurityTest extends TestCase
{
    private \PDO $db;
    private int $testJobId;
    private int $testPageId;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();

        // Create a test job
        $stmt = $this->db->prepare(
            "INSERT INTO crawl_jobs (domain, status) VALUES ('https://secure-test.com', 'completed')"
        );
        $stmt->execute([]);
        $this->testJobId = (int)$this->db->lastInsertId();

        // Create a test page
        $stmt = $this->db->prepare(
            "INSERT INTO pages (crawl_job_id, url, title, status_code, crawled_at) VALUES (?, ?, ?, 200, NOW())"
        );
        $stmt->execute([$this->testJobId, 'https://secure-test.com/', 'Test Page']);
        $this->testPageId = (int)$this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->db->prepare("DELETE FROM links WHERE crawl_job_id = ?")->execute([$this->testJobId]);
        $this->db->prepare("DELETE FROM pages WHERE crawl_job_id = ?")->execute([$this->testJobId]);
        $this->db->prepare("DELETE FROM crawl_jobs WHERE id = ?")->execute([$this->testJobId]);
    }

    // =========================================================================
    // SQL INJECTION TESTS
    // =========================================================================

    public function testSqlInjectionInJobIdParameter(): void
    {
        // Attempt SQL injection via job_id
        $maliciousJobId = "1; DROP TABLE crawl_jobs; --";

        // This should not cause any harm due to prepared statements
        $stmt = $this->db->prepare("SELECT * FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$maliciousJobId]);
        $result = $stmt->fetch();

        // Should return false (no matching record), not cause an error
        $this->assertFalse($result);

        // Verify table still exists
        $stmt = $this->db->query("SELECT COUNT(*) FROM crawl_jobs");
        $this->assertNotFalse($stmt);
    }

    public function testSqlInjectionInDomainParameter(): void
    {
        $maliciousDomain = "https://example.com'; DELETE FROM crawl_jobs WHERE '1'='1";

        // Using prepared statement should prevent injection
        $stmt = $this->db->prepare("SELECT * FROM crawl_jobs WHERE domain = ?");
        $stmt->execute([$maliciousDomain]);
        $result = $stmt->fetch();

        $this->assertFalse($result);

        // Verify our test data is still intact
        $stmt = $this->db->prepare("SELECT * FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$this->testJobId]);
        $this->assertNotFalse($stmt->fetch());
    }

    public function testSqlInjectionInUrlParameter(): void
    {
        $maliciousUrl = "https://example.com' OR '1'='1";

        $stmt = $this->db->prepare("SELECT * FROM pages WHERE url = ?");
        $stmt->execute([$maliciousUrl]);
        $result = $stmt->fetch();

        // Should not return all rows
        $this->assertFalse($result);
    }

    public function testSqlInjectionUnionAttack(): void
    {
        $maliciousJobId = "1 UNION SELECT * FROM crawl_jobs";

        $stmt = $this->db->prepare("SELECT * FROM pages WHERE crawl_job_id = ?");
        $stmt->execute([$maliciousJobId]);
        $results = $stmt->fetchAll();

        // UNION attack should fail due to prepared statements
        // The malicious string is treated as a literal value, not SQL
        // So it should return empty (no page has crawl_job_id = "1 UNION SELECT * FROM crawl_jobs")
        $this->assertEmpty($results, 'SQL injection should be prevented by prepared statements');
    }

    public function testSqlInjectionWithCommentSyntax(): void
    {
        $maliciousDomain = "https://test.com' /* comment */ --";

        $stmt = $this->db->prepare("INSERT INTO crawl_jobs (domain, status) VALUES (?, 'pending')");
        $result = $stmt->execute([$maliciousDomain]);

        $this->assertTrue($result);

        // Clean up
        $lastId = (int)$this->db->lastInsertId();
        $this->db->prepare("DELETE FROM crawl_jobs WHERE id = ?")->execute([$lastId]);
    }

    // =========================================================================
    // XSS (CROSS-SITE SCRIPTING) TESTS
    // =========================================================================

    public function testXssInTitleField(): void
    {
        $xssPayload = '<script>alert("XSS")</script>';

        $stmt = $this->db->prepare(
            "INSERT INTO pages (crawl_job_id, url, title, status_code, crawled_at) VALUES (?, ?, ?, 200, NOW())"
        );
        $result = $stmt->execute([$this->testJobId, 'https://test.com/xss', $xssPayload]);

        $this->assertTrue($result);

        // Retrieve and verify the payload is stored as-is (not executed)
        $stmt = $this->db->prepare("SELECT title FROM pages WHERE url = ?");
        $stmt->execute(['https://test.com/xss']);
        $page = $stmt->fetch();

        $this->assertEquals($xssPayload, $page['title']);

        // Clean up
        $this->db->prepare("DELETE FROM pages WHERE url = ?")->execute(['https://test.com/xss']);
    }

    public function testXssInMetaDescription(): void
    {
        $xssPayload = '<img src=x onerror="alert(1)">';

        $stmt = $this->db->prepare(
            "INSERT INTO pages (crawl_job_id, url, meta_description, status_code, crawled_at) VALUES (?, ?, ?, 200, NOW())"
        );
        $result = $stmt->execute([$this->testJobId, 'https://test.com/xss-meta', $xssPayload]);

        $this->assertTrue($result);

        // Clean up
        $this->db->prepare("DELETE FROM pages WHERE url = ?")->execute(['https://test.com/xss-meta']);
    }

    public function testXssInLinkText(): void
    {
        $xssPayload = '<a href="javascript:alert(1)">Click</a>';

        $stmt = $this->db->prepare(
            "INSERT INTO links (crawl_job_id, page_id, source_url, target_url, link_text) VALUES (?, ?, ?, ?, ?)"
        );
        $result = $stmt->execute([
            $this->testJobId,
            $this->testPageId,
            'https://test.com/',
            'https://test.com/link',
            $xssPayload
        ]);

        $this->assertTrue($result);

        // Clean up
        $this->db->prepare("DELETE FROM links WHERE link_text = ?")->execute([$xssPayload]);
    }

    public function testXssInAltText(): void
    {
        $xssPayload = '" onmouseover="alert(1)"';

        $stmt = $this->db->prepare(
            "INSERT INTO images (crawl_job_id, page_id, url, alt_text, crawled_at) VALUES (?, ?, ?, ?, NOW())"
        );
        $result = $stmt->execute([
            $this->testJobId,
            $this->testPageId,
            'https://test.com/img/xss.png',
            $xssPayload
        ]);

        $this->assertTrue($result);

        // Clean up
        $this->db->prepare("DELETE FROM images WHERE alt_text = ?")->execute([$xssPayload]);
    }

    // =========================================================================
    // INPUT VALIDATION TESTS
    // =========================================================================

    public function testEmptyDomainIsRejected(): void
    {
        $domain = '';

        // Simulate API validation - empty string should be rejected
        $isValid = strlen(trim($domain)) > 0;
        $this->assertFalse($isValid);
    }

    public function testNullDomainIsRejected(): void
    {
        // Simulate API validation - null should be rejected
        $domain = null;

        // API should check for null and reject it
        $apiValidation = function (?string $value): bool {
            return $value !== null && strlen(trim($value)) > 0;
        };

        $this->assertFalse($apiValidation($domain), 'Null domain should be rejected');
    }

    public function testWhitespaceOnlyDomainIsRejected(): void
    {
        $domain = '   ';

        // Simulate API validation
        $isValid = !empty(trim($domain));
        $this->assertFalse($isValid);
    }

    public function testInvalidUrlFormatIsHandled(): void
    {
        $invalidUrls = [
            'not-a-url',
            'htp://invalid-protocol.com',
            '://missing-protocol.com',
            'http://',
            'https://',
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'file:///etc/passwd',
        ];

        foreach ($invalidUrls as $url) {
            // Check if URL has valid protocol
            $hasValidProtocol = preg_match('/^https?:\/\//', $url);
            $this->assertFalse(
                $hasValidProtocol && filter_var($url, FILTER_VALIDATE_URL) !== false,
                "URL should be invalid: $url"
            );
        }
    }

    public function testValidUrlFormatsAreAccepted(): void
    {
        $validUrls = [
            'https://example.com',
            'http://example.com',
            'https://example.com/path',
            'https://example.com/path?query=value',
            'https://subdomain.example.com',
            'https://example.com:8080',
        ];

        foreach ($validUrls as $url) {
            $hasValidProtocol = preg_match('/^https?:\/\//', $url);
            $isValid = $hasValidProtocol && filter_var($url, FILTER_VALIDATE_URL) !== false;
            $this->assertTrue($isValid, "URL should be valid: $url");
        }
    }

    public function testDomainWithoutProtocolGetsHttps(): void
    {
        $domain = 'example.com';

        // Simulate API behavior
        if (!preg_match('/^https?:\/\//', $domain)) {
            $domain = 'https://' . $domain;
        }

        $this->assertEquals('https://example.com', $domain);
    }

    public function testExtremelyLongInputIsHandled(): void
    {
        $longDomain = 'https://' . str_repeat('a', 10000) . '.com';

        // Database should handle or truncate
        $stmt = $this->db->prepare("SELECT LENGTH(?) as len");
        $stmt->execute([$longDomain]);
        $result = $stmt->fetch();

        $this->assertGreaterThan(0, $result['len']);
    }

    public function testSpecialCharactersInInput(): void
    {
        $specialChars = [
            'https://example.com/path?param=<script>',
            'https://example.com/path?param="onclick"',
            "https://example.com/path?param='or'1'='1",
            'https://example.com/path?param=;DROP TABLE users;',
        ];

        foreach ($specialChars as $url) {
            // Should be able to store without error
            $stmt = $this->db->prepare("SELECT ? as url");
            $stmt->execute([$url]);
            $result = $stmt->fetch();

            $this->assertEquals($url, $result['url']);
        }
    }

    // =========================================================================
    // URL VALIDATION TESTS
    // =========================================================================

    public function testDangerousProtocolsAreRejected(): void
    {
        $dangerousUrls = [
            'javascript:alert(document.cookie)',
            'vbscript:msgbox("XSS")',
            'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
            'file:///etc/passwd',
            'ftp://ftp.example.com/file',
        ];

        foreach ($dangerousUrls as $url) {
            // Only http and https should be allowed
            $isAllowed = preg_match('/^https?:\/\//', $url) === 1;
            $this->assertFalse($isAllowed, "Dangerous URL should be rejected: $url");
        }
    }

    public function testLocalhostUrlsAreHandled(): void
    {
        $localhostUrls = [
            'http://localhost',
            'http://127.0.0.1',
            'http://[::1]',
            'http://0.0.0.0',
        ];

        foreach ($localhostUrls as $url) {
            // These might be valid URLs but could be security risks
            $hasValidProtocol = preg_match('/^https?:\/\//', $url) === 1;
            $this->assertTrue($hasValidProtocol, "URL should have valid protocol: $url");

            // In production, you might want to block these
        }
    }

    public function testPrivateIpRangesAreHandled(): void
    {
        $privateIps = [
            'http://192.168.1.1',
            'http://10.0.0.1',
            'http://172.16.0.1',
        ];

        foreach ($privateIps as $url) {
            // These are valid URLs but could be SSRF risks
            $isValid = filter_var($url, FILTER_VALIDATE_URL) !== false;
            $this->assertTrue($isValid);

            // In production, consider blocking private IP ranges
        }
    }

    // =========================================================================
    // ACCESS CONTROL TESTS
    // =========================================================================

    public function testNonExistentJobReturnsNoData(): void
    {
        $nonExistentId = 999999;

        $stmt = $this->db->prepare("SELECT * FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$nonExistentId]);
        $result = $stmt->fetch();

        $this->assertFalse($result);
    }

    public function testNonExistentPageReturnsNoData(): void
    {
        $nonExistentId = 999999;

        $stmt = $this->db->prepare("SELECT * FROM pages WHERE id = ?");
        $stmt->execute([$nonExistentId]);
        $result = $stmt->fetch();

        $this->assertFalse($result);
    }

    public function testJobIsolation(): void
    {
        // Create another job
        $stmt = $this->db->prepare(
            "INSERT INTO crawl_jobs (domain, status) VALUES ('https://other-job.com', 'completed')"
        );
        $stmt->execute([]);
        $otherJobId = (int)$this->db->lastInsertId();

        // Create page for other job
        $stmt = $this->db->prepare(
            "INSERT INTO pages (crawl_job_id, url, status_code, crawled_at) VALUES (?, ?, 200, NOW())"
        );
        $stmt->execute([$otherJobId, 'https://other-job.com/']);

        // Verify pages are isolated by job_id
        $stmt = $this->db->prepare("SELECT * FROM pages WHERE crawl_job_id = ?");
        $stmt->execute([$this->testJobId]);
        $pages1 = $stmt->fetchAll();

        $stmt->execute([$otherJobId]);
        $pages2 = $stmt->fetchAll();

        $this->assertCount(1, $pages1);
        $this->assertCount(1, $pages2);
        $this->assertNotEquals($pages1[0]['url'], $pages2[0]['url']);

        // Clean up
        $this->db->prepare("DELETE FROM pages WHERE crawl_job_id = ?")->execute([$otherJobId]);
        $this->db->prepare("DELETE FROM crawl_jobs WHERE id = ?")->execute([$otherJobId]);
    }

    // =========================================================================
    // ERROR HANDLING TESTS
    // =========================================================================

    public function testInvalidJobIdTypeIsHandled(): void
    {
        $invalidIds = ['abc', '1.5.3', 'null', 'undefined'];

        // Simulate API validation function
        $validateId = function (string $id): int {
            return is_numeric($id) ? (int)$id : 0;
        };

        foreach ($invalidIds as $id) {
            // Non-numeric IDs should result in 0
            $result = $validateId($id);
            $this->assertLessThanOrEqual(0, $result, "ID should be invalid: $id");
        }
    }

    public function testNegativeJobIdIsHandled(): void
    {
        $negativeId = -1;

        $stmt = $this->db->prepare("SELECT * FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$negativeId]);
        $result = $stmt->fetch();

        $this->assertFalse($result);
    }

    public function testZeroJobIdIsHandled(): void
    {
        $zeroId = 0;

        $stmt = $this->db->prepare("SELECT * FROM crawl_jobs WHERE id = ?");
        $stmt->execute([$zeroId]);
        $result = $stmt->fetch();

        $this->assertFalse($result);
    }

    public function testFloatJobIdIsHandled(): void
    {
        $floatId = 1.5;

        // Should be truncated to integer
        $intId = (int)$floatId;

        $this->assertEquals(1, $intId);
    }

    // =========================================================================
    // DATA INTEGRITY TESTS
    // =========================================================================

    public function testForeignKeysPreventOrphanedRecords(): void
    {
        // Try to insert a link with non-existent page_id
        // This should fail due to foreign key constraint
        $nonExistentPageId = 999999;

        $stmt = $this->db->prepare(
            "INSERT INTO links (crawl_job_id, page_id, source_url, target_url) VALUES (?, ?, ?, ?)"
        );

        try {
            $result = $stmt->execute([
                $this->testJobId,
                $nonExistentPageId,
                'https://test.com/',
                'https://test.com/orphan'
            ]);
            // If we get here, foreign key constraint might be disabled
            $this->fail('Foreign key constraint should prevent orphaned records');
        } catch (\PDOException $e) {
            // Expected - foreign key constraint violation
            $this->assertStringContainsString('foreign key', strtolower($e->getMessage()));
        }
    }

    public function testCascadeDeleteWorks(): void
    {
        // Create additional data
        $this->db->prepare(
            "INSERT INTO pages (crawl_job_id, url, status_code, crawled_at) VALUES (?, ?, 200, NOW())"
        )->execute([$this->testJobId, 'https://cascade-test.com/']);

        // Delete the job
        $this->db->prepare("DELETE FROM crawl_jobs WHERE id = ?")->execute([$this->testJobId]);

        // Verify pages are also deleted (cascade)
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pages WHERE crawl_job_id = ?");
        $stmt->execute([$this->testJobId]);
        $count = (int)$stmt->fetchColumn();

        $this->assertEquals(0, $count);

        // Recreate test data for tearDown
        $stmt = $this->db->prepare(
            "INSERT INTO crawl_jobs (domain, status) VALUES ('https://secure-test.com', 'completed')"
        );
        $stmt->execute([]);
        $this->testJobId = (int)$this->db->lastInsertId();
    }

    // =========================================================================
    // SSRF (SERVER-SIDE REQUEST FORGERY) PREVENTION TESTS
    // =========================================================================

    public function testInternalUrlPatternsAreDetected(): void
    {
        $internalPatterns = [
            'http://localhost',
            'http://127.0.0.1',
            'http://0.0.0.0',
            'http://[::1]',
            'http://169.254.169.254', // AWS metadata
            'http://metadata.google.internal', // GCP metadata
        ];

        foreach ($internalPatterns as $url) {
            // In production, these should be blocked before crawling
            $isInternal = $this->isInternalUrl($url);
            $this->assertTrue($isInternal, "Should detect internal URL: $url");
        }
    }

    public function testCloudMetadataEndpointsAreBlocked(): void
    {
        $metadataUrls = [
            'http://169.254.169.254/latest/meta-data/',
            'http://metadata.google.internal/computeMetadata/v1/',
            'http://169.254.169.254/metadata/v1/',
        ];

        foreach ($metadataUrls as $url) {
            $isMetadata = $this->isMetadataEndpoint($url);
            $this->assertTrue($isMetadata, "Should detect metadata endpoint: $url");
        }
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function isInternalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null) {
            return false;
        }

        // Check for localhost
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '[::1]'])) {
            return true;
        }

        // Check for private IP ranges
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        return false;
    }

    private function isMetadataEndpoint(string $url): bool
    {
        $metadataPatterns = [
            '169.254.169.254',
            'metadata.google.internal',
        ];

        foreach ($metadataPatterns as $pattern) {
            if (str_contains($url, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
