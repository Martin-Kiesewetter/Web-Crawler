<?php

/**
 * Web Crawler - Crawler Class
 *
 * @copyright Copyright (c) 2025 Martin Kiesewetter
 * @author    Martin Kiesewetter <mki@kies-media.de>
 * @link      https://kies-media.de
 */

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class Crawler
{
    private \PDO $db;
    private Client $client;
    private int $concurrency = 10; // Parallel requests
    /** @var array<string, bool> */
    private array $visited = [];
    private int $crawlJobId;
    private string $baseDomain;

    public function __construct(int $crawlJobId)
    {
        $this->db = Database::getInstance();
        $this->crawlJobId = $crawlJobId;
        $this->client = new Client([
            'timeout' => 30,
            'verify' => false,
            'allow_redirects' => [
                'max' => 10,
                'track_redirects' => true
            ],
            'headers' => [
                'User-Agent' => 'WebCrawler/1.0'
            ]
        ]);
    }

    public function start(string $startUrl): void
    {
        $host = parse_url($startUrl, PHP_URL_HOST);
        $this->baseDomain = strtolower($host ?: '');

        // Update job status
        $stmt = $this->db->prepare("UPDATE crawl_jobs SET status = 'running', started_at = NOW() WHERE id = ?");
        $stmt->execute([$this->crawlJobId]);

        // Normalize and add start URL to queue
        $normalizedStartUrl = $this->normalizeUrl($startUrl);
        $this->addToQueue($normalizedStartUrl, 0);

        // Process queue
        $this->processQueue();

        // Update job status
        $this->updateJobStats();
        $stmt = $this->db->prepare("UPDATE crawl_jobs SET status = 'completed', completed_at = NOW() WHERE id = ?");
        $stmt->execute([$this->crawlJobId]);
    }

    private function addToQueue(string $url, int $depth): void
    {
        if (isset($this->visited[$url])) {
            return;
        }

        try {
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO crawl_queue (crawl_job_id, url, depth) VALUES (?, ?, ?)"
            );
            $stmt->execute([$this->crawlJobId, $url, $depth]);
        } catch (\Exception $e) {
            // URL already in queue
        }
    }

    private function processQueue(): void
    {
        while (true) {
            // Get pending URLs
            $stmt = $this->db->prepare(
                "SELECT id, url, depth FROM crawl_queue
                WHERE crawl_job_id = ? AND status = 'pending'
                LIMIT ?"
            );
            $stmt->execute([$this->crawlJobId, $this->concurrency]);
            $urls = $stmt->fetchAll();

            if (empty($urls)) {
                break;
            }

            $this->crawlBatch($urls);
        }
    }

    /**
     * @param array<int, array{id: int, url: string, depth: int}> $urls
     */
    private function crawlBatch(array $urls): void
    {
        $requests = function () use ($urls) {
            foreach ($urls as $item) {
                // Mark as processing
                $stmt = $this->db->prepare("UPDATE crawl_queue SET status = 'processing' WHERE id = ?");
                $stmt->execute([$item['id']]);

                yield function () use ($item) {
                    return $this->client->getAsync($item['url']);
                };
            }
        };

        $pool = new Pool($this->client, $requests(), [
            'concurrency' => $this->concurrency,
            'fulfilled' => function ($response, $index) use ($urls) {
                $item = $urls[$index];
                $this->handleResponse($item, $response);
            },
            'rejected' => function ($reason, $index) use ($urls) {
                $item = $urls[$index];
                $this->handleError($item, $reason);
            },
        ]);

        $pool->promise()->wait();
    }

    /**
     * @param array{id: int, url: string, depth: int} $queueItem
     * @param \Psr\Http\Message\ResponseInterface $response
     */
    private function handleResponse(array $queueItem, $response): void
    {
        $url = $queueItem['url'];
        $depth = $queueItem['depth'];

        $this->visited[$url] = true;

        $statusCode = $response->getStatusCode();
        $contentType = $response->getHeaderLine('Content-Type');
        $body = $response->getBody()->getContents();

        // Track redirects
        $redirectUrl = null;
        $redirectCount = 0;
        if ($response->hasHeader('X-Guzzle-Redirect-History')) {
            $redirectHistory = $response->getHeader('X-Guzzle-Redirect-History');
            $redirectCount = count($redirectHistory);
            if ($redirectCount > 0) {
                $redirectUrl = end($redirectHistory);
            }
        }

        // Save page
        $domCrawler = new DomCrawler($body, $url);
        $title = $domCrawler->filter('title')->count() > 0
            ? $domCrawler->filter('title')->text()
            : '';

        $metaDescription = $domCrawler->filter('meta[name="description"]')->count() > 0
            ? $domCrawler->filter('meta[name="description"]')->attr('content')
            : '';

        $stmt = $this->db->prepare(
            "INSERT INTO pages (crawl_job_id, url, title, meta_description, status_code, " .
            "content_type, redirect_url, redirect_count) " .
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?) " .
            "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), status_code = VALUES(status_code), " .
            "meta_description = VALUES(meta_description), redirect_url = VALUES(redirect_url), " .
            "redirect_count = VALUES(redirect_count)"
        );

        $stmt->execute([
            $this->crawlJobId,
            $url,
            $title,
            $metaDescription,
            $statusCode,
            $contentType,
            $redirectUrl,
            $redirectCount
        ]);
        $pageId = $this->db->lastInsertId();

        // If pageId is 0, fetch it manually
        if ($pageId == 0 || $pageId === '0') {
            $stmt = $this->db->prepare("SELECT id FROM pages WHERE crawl_job_id = ? AND url = ?");
            $stmt->execute([$this->crawlJobId, $url]);
            $fetchedId = $stmt->fetchColumn();
            $pageId = is_numeric($fetchedId) ? (int)$fetchedId : 0;
        }

        // Ensure pageId is an integer
        $pageId = is_numeric($pageId) ? (int)$pageId : 0;

        // Extract and save links
        if (str_contains($contentType, 'text/html') && $pageId > 0) {
            echo "Extracting links from: $url (pageId: $pageId)\n";
            $this->extractLinks($domCrawler, $url, $pageId, $depth);
        } else {
            echo "Skipping link extraction - content type: $contentType\n";
        }

        // Mark as completed
        $stmt = $this->db->prepare("UPDATE crawl_queue SET status = 'completed', processed_at = NOW() WHERE id = ?");
        $stmt->execute([$queueItem['id']]);
    }

    private function extractLinks(DomCrawler $crawler, string $sourceUrl, int $pageId, int $depth): void
    {
        $linkCount = 0;
        $crawler->filter('a')->each(function (DomCrawler $node) use ($sourceUrl, $pageId, $depth, &$linkCount) {
            try {
                $linkCount++;
                $href = $node->attr('href');
                if (!$href || $href === '#') {
                    return;
                }

                // Convert relative URLs to absolute
                $targetUrl = $this->makeAbsoluteUrl($href, $sourceUrl);

                // Get link text
                $linkText = trim($node->text());

                // Check nofollow
                $rel = $node->attr('rel') ?? '';
                $isNofollow = str_contains($rel, 'nofollow');

                // Check if internal (same domain, no subdomains)
                $targetHost = parse_url($targetUrl, PHP_URL_HOST);
                $targetDomain = strtolower($targetHost ?: '');
                $isInternal = ($targetDomain === $this->baseDomain);

                // Save link
                $stmt = $this->db->prepare(
                    "INSERT INTO links (page_id, crawl_job_id, source_url, target_url, " .
                    "link_text, is_nofollow, is_internal) VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $pageId,
                    $this->crawlJobId,
                    $sourceUrl,
                    $targetUrl,
                    $linkText,
                    $isNofollow ? 1 : 0,
                    $isInternal ? 1 : 0
                ]);

                // Add to queue if internal and not nofollow
                if ($isInternal && !$isNofollow && $depth < 50) {
                    // Normalize URL (remove fragment, trailing slash)
                    $normalizedUrl = $this->normalizeUrl($targetUrl);
                    $this->addToQueue($normalizedUrl, $depth + 1);
                }
            } catch (\Exception $e) {
                echo "Error processing link: " . $e->getMessage() . "\n";
            }
        });
        echo "Processed $linkCount links from $sourceUrl\n";
    }

    private function makeAbsoluteUrl(string $url, string $base): string
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';

        if ($url[0] === '/') {
            return "$scheme://$host$url";
        }

        $basePath = substr($path, 0, strrpos($path, '/') + 1);
        return "$scheme://$host$basePath$url";
    }

    /**
     * @param array{id: int, url: string, depth: int} $queueItem
     * @param \GuzzleHttp\Exception\RequestException $reason
     */
    private function handleError(array $queueItem, $reason): void
    {
        $stmt = $this->db->prepare(
            "UPDATE crawl_queue SET status = 'failed', processed_at = NOW(), retry_count = retry_count + 1 WHERE id = ?"
        );
        $stmt->execute([$queueItem['id']]);
    }

    private function updateJobStats(): void
    {
        $stmt = $this->db->prepare(
            "UPDATE crawl_jobs SET
            total_pages = (SELECT COUNT(*) FROM pages WHERE crawl_job_id = ?),
            total_links = (SELECT COUNT(*) FROM links WHERE crawl_job_id = ?)
            WHERE id = ?"
        );
        $stmt->execute([$this->crawlJobId, $this->crawlJobId, $this->crawlJobId]);
    }

    private function normalizeUrl(string $url): string
    {
        // Parse URL
        $parts = parse_url($url);

        if (!$parts) {
            return $url;
        }

        // Remove fragment
        unset($parts['fragment']);

        // Normalize domain (add www if base domain has it, or remove if base doesn't)
        if (isset($parts['host'])) {
            // Always convert to lowercase
            $parts['host'] = strtolower($parts['host']);

            // Match www pattern with base domain
            $baseHasWww = str_starts_with($this->baseDomain, 'www.');
            $urlHasWww = str_starts_with($parts['host'], 'www.');

            if ($baseHasWww && !$urlHasWww) {
                $parts['host'] = 'www.' . $parts['host'];
            } elseif (!$baseHasWww && $urlHasWww) {
                $parts['host'] = substr($parts['host'], 4);
            }
        }

        // Normalize path - remove trailing slash except for root
        if (isset($parts['path']) && $parts['path'] !== '/') {
            $parts['path'] = rtrim($parts['path'], '/');
        }

        // Rebuild URL
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . $host . $port . $path . $query;
    }
}
