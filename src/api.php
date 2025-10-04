<?php

/**
 * Web Crawler - API Endpoint
 *
 * @copyright Copyright (c) 2025 Martin Kiesewetter
 * @author    Martin Kiesewetter <mki@kies-media.de>
 * @link      https://kies-media.de
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\Crawler;

header('Content-Type: application/json');

$db = Database::getInstance();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'start':
            $domain = $_POST['domain'] ?? '';
            if (empty($domain)) {
                throw new Exception('Domain is required');
            }

            // Validate and format URL
            if (!preg_match('/^https?:\/\//', $domain)) {
                $domain = 'https://' . $domain;
            }

            // Create crawl job
            $stmt = $db->prepare("INSERT INTO crawl_jobs (domain, status) VALUES (?, 'pending')");
            $stmt->execute([$domain]);
            $jobId = $db->lastInsertId();

            // Start crawling in background (using exec for async)
            $cmd = "php " . __DIR__ . "/crawler-worker.php $jobId > /dev/null 2>&1 &";
            exec($cmd);

            echo json_encode([
                'success' => true,
                'job_id' => $jobId,
                'message' => 'Crawl job started'
            ]);
            break;

        case 'status':
            $jobId = $_GET['job_id'] ?? 0;
            $stmt = $db->prepare("SELECT * FROM crawl_jobs WHERE id = ?");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();

            if (!$job) {
                throw new Exception('Job not found');
            }

            // Get queue statistics
            $stmt = $db->prepare("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM crawl_queue
                WHERE crawl_job_id = ?
            ");
            $stmt->execute([$jobId]);
            $queueStats = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'job' => $job,
                'queue' => $queueStats
            ]);
            break;

        case 'jobs':
            $stmt = $db->query("SELECT * FROM crawl_jobs ORDER BY created_at DESC LIMIT 50");
            if ($stmt === false) {
                throw new Exception('Failed to query jobs');
            }
            $jobs = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'jobs' => $jobs
            ]);
            break;

        case 'pages':
            $jobId = $_GET['job_id'] ?? 0;
            $stmt = $db->prepare("SELECT * FROM pages WHERE crawl_job_id = ? ORDER BY id DESC LIMIT 1000");
            $stmt->execute([$jobId]);
            $pages = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'pages' => $pages
            ]);
            break;

        case 'links':
            $jobId = $_GET['job_id'] ?? 0;
            $stmt = $db->prepare("SELECT * FROM links WHERE crawl_job_id = ? ORDER BY id DESC LIMIT 1000");
            $stmt->execute([$jobId]);
            $links = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'links' => $links
            ]);
            break;

        case 'broken-links':
            $jobId = $_GET['job_id'] ?? 0;
            $stmt = $db->prepare(
                "SELECT * FROM pages " .
                "WHERE crawl_job_id = ? AND (status_code >= 400 OR status_code = 0) " .
                "ORDER BY status_code DESC, url"
            );
            $stmt->execute([$jobId]);
            $brokenLinks = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'broken_links' => $brokenLinks
            ]);
            break;

        case 'seo-analysis':
            $jobId = $_GET['job_id'] ?? 0;
            $stmt = $db->prepare(
                "SELECT id, url, title, meta_description, status_code FROM pages " .
                "WHERE crawl_job_id = ? ORDER BY url"
            );
            $stmt->execute([$jobId]);
            $pages = $stmt->fetchAll();

            $issues = [];
            foreach ($pages as $page) {
                $pageIssues = [];
                $titleLen = mb_strlen($page['title'] ?? '');
                $descLen = mb_strlen($page['meta_description'] ?? '');

                // Title issues (Google: 50-60 chars optimal)
                if (empty($page['title'])) {
                    $pageIssues[] = 'Title missing';
                } elseif ($titleLen < 30) {
                    $pageIssues[] = "Title too short ({$titleLen} chars)";
                } elseif ($titleLen > 60) {
                    $pageIssues[] = "Title too long ({$titleLen} chars)";
                }

                // Meta description issues (Google: 120-160 chars optimal)
                if (empty($page['meta_description'])) {
                    $pageIssues[] = 'Meta description missing';
                } elseif ($descLen < 70) {
                    $pageIssues[] = "Meta description too short ({$descLen} chars)";
                } elseif ($descLen > 160) {
                    $pageIssues[] = "Meta description too long ({$descLen} chars)";
                }

                if (!empty($pageIssues)) {
                    $issues[] = [
                        'url' => $page['url'],
                        'title' => $page['title'],
                        'title_length' => $titleLen,
                        'meta_description' => $page['meta_description'],
                        'meta_length' => $descLen,
                        'issues' => $pageIssues
                    ];
                }
            }

            // Find duplicates
            $titleCounts = [];
            $descCounts = [];
            foreach ($pages as $page) {
                if (!empty($page['title'])) {
                    $titleCounts[$page['title']][] = $page['url'];
                }
                if (!empty($page['meta_description'])) {
                    $descCounts[$page['meta_description']][] = $page['url'];
                }
            }

            $duplicates = [];
            foreach ($titleCounts as $title => $urls) {
                if (count($urls) > 1) {
                    $duplicates[] = [
                        'type' => 'title',
                        'content' => $title,
                        'urls' => $urls
                    ];
                }
            }
            foreach ($descCounts as $desc => $urls) {
                if (count($urls) > 1) {
                    $duplicates[] = [
                        'type' => 'meta_description',
                        'content' => $desc,
                        'urls' => $urls
                    ];
                }
            }

            echo json_encode([
                'success' => true,
                'issues' => $issues,
                'duplicates' => $duplicates,
                'total_pages' => count($pages)
            ]);
            break;

        case 'delete':
            $jobId = $_POST['job_id'] ?? 0;
            $stmt = $db->prepare("DELETE FROM crawl_jobs WHERE id = ?");
            $stmt->execute([$jobId]);

            echo json_encode([
                'success' => true,
                'message' => 'Job deleted'
            ]);
            break;

        case 'recrawl':
            $jobId = $_POST['job_id'] ?? 0;
            $domain = $_POST['domain'] ?? '';

            if (empty($domain)) {
                throw new Exception('Domain is required');
            }

            // Delete all related data for this job
            $stmt = $db->prepare("DELETE FROM crawl_queue WHERE crawl_job_id = ?");
            $stmt->execute([$jobId]);

            $stmt = $db->prepare("DELETE FROM links WHERE crawl_job_id = ?");
            $stmt->execute([$jobId]);

            $stmt = $db->prepare("DELETE FROM pages WHERE crawl_job_id = ?");
            $stmt->execute([$jobId]);

            // Reset job status
            $stmt = $db->prepare(
                "UPDATE crawl_jobs SET status = 'pending', total_pages = 0, total_links = 0, " .
                "started_at = NULL, completed_at = NULL WHERE id = ?"
            );
            $stmt->execute([$jobId]);

            // Start crawling in background
            $cmd = "php " . __DIR__ . "/crawler-worker.php $jobId > /dev/null 2>&1 &";
            exec($cmd);

            echo json_encode([
                'success' => true,
                'job_id' => $jobId,
                'message' => 'Recrawl started'
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
