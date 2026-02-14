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

        // Extract favicon
        $faviconUrl = $this->extractFavicon($domCrawler, $url);

        $stmt = $this->db->prepare(
            "INSERT INTO pages (crawl_job_id, url, title, meta_description, status_code, " .
            "content_type, redirect_url, redirect_count, favicon_url) " .
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) " .
            "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), status_code = VALUES(status_code), " .
            "meta_description = VALUES(meta_description), redirect_url = VALUES(redirect_url), " .
            "redirect_count = VALUES(redirect_count), favicon_url = VALUES(favicon_url)"
        );

        $stmt->execute([
            $this->crawlJobId,
            $url,
            $title,
            $metaDescription,
            $statusCode,
            $contentType,
            $redirectUrl,
            $redirectCount,
            $faviconUrl
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
            
            // Extract and save images
            echo "Extracting images from: $url (pageId: $pageId)\n";
            $this->extractImages($domCrawler, $url, $pageId);
            
            // Extract and save scripts
            echo "Extracting scripts from: $url (pageId: $pageId)\n";
            $this->extractScripts($domCrawler, $url, $pageId);
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

                // Skip if URL points to an image or script file
                if ($this->isImageUrl($targetUrl) || $this->isScriptUrl($targetUrl)) {
                    return;
                }

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

                // Add to queue if internal (including nofollow links for crawling)
                if ($isInternal && $depth < 50) {
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

    /**
     * Extract images from HTML page and save to database
     */
    private function extractImages(DomCrawler $crawler, string $pageUrl, int $pageId): void
    {
        $imageCount = 0;
        $crawler->filter('img')->each(function (DomCrawler $node) use ($pageUrl, $pageId, &$imageCount) {
            try {
                $imageCount++;
                $src = $node->attr('src');
                if (!$src) {
                    return;
                }

                // Convert relative URLs to absolute
                $imageUrl = $this->makeAbsoluteUrl($src, $pageUrl);

                // Check if this image URL was already crawled for this job
                $stmt = $this->db->prepare(
                    "SELECT id FROM images WHERE crawl_job_id = ? AND url = ? LIMIT 1"
                );
                $stmt->execute([$this->crawlJobId, $imageUrl]);
                $existingImage = $stmt->fetch();

                // If image already exists, skip fetching metadata
                if ($existingImage) {
                    echo "Skipping already crawled image: $imageUrl\n";
                    return;
                }

                // Get image attributes
                $alt = $node->attr('alt') ?? '';
                $title = $node->attr('title') ?? '';
                $srcset = $node->attr('srcset') ?? '';

                // Check if responsive (has srcset or sizes attribute)
                $isResponsive = !empty($srcset) || !empty($node->attr('sizes'));

                // Fetch image metadata (only for new images)
                $imageData = $this->getImageData($imageUrl);

                // Save image
                $stmt = $this->db->prepare(
                    "INSERT INTO images (crawl_job_id, page_id, url, alt_text, title, " .
                    "status_code, content_type, file_size, width, height, is_responsive, " .
                    "redirect_url, redirect_count) " .
                    "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) " .
                    "ON DUPLICATE KEY UPDATE status_code = VALUES(status_code), " .
                    "content_type = VALUES(content_type), file_size = VALUES(file_size), " .
                    "width = VALUES(width), height = VALUES(height)"
                );
                $stmt->execute([
                    $this->crawlJobId,
                    $pageId,
                    $imageUrl,
                    $alt,
                    $title,
                    $imageData['status_code'] ?? null,
                    $imageData['content_type'] ?? null,
                    $imageData['file_size'] ?? null,
                    $imageData['width'] ?? null,
                    $imageData['height'] ?? null,
                    $isResponsive ? 1 : 0,
                    $imageData['redirect_url'] ?? null,
                    $imageData['redirect_count'] ?? 0
                ]);
            } catch (\Exception $e) {
                echo "Error processing image: " . $e->getMessage() . "\n";
            }
        });
        echo "Processed $imageCount images from $pageUrl\n";
    }

    /**
     * Fetch image metadata (size, status code, etc.)
     */
    private function getImageData(string $imageUrl): array
    {
        $data = [
            'status_code' => null,
            'content_type' => null,
            'file_size' => null,
            'width' => null,
            'height' => null,
            'redirect_url' => null,
            'redirect_count' => 0
        ];

        try {
            $response = $this->client->head($imageUrl, ['allow_redirects' => true]);
            
            $data['status_code'] = $response->getStatusCode();
            $data['content_type'] = $response->getHeaderLine('Content-Type');
            
            // Get file size from Content-Length header
            $contentLength = $response->getHeaderLine('Content-Length');
            if ($contentLength) {
                $data['file_size'] = (int)$contentLength;
            }

            // Track redirects
            if ($response->hasHeader('X-Guzzle-Redirect-History')) {
                $redirectHistory = $response->getHeader('X-Guzzle-Redirect-History');
                $data['redirect_count'] = count($redirectHistory);
                if ($data['redirect_count'] > 0) {
                    $data['redirect_url'] = end($redirectHistory);
                }
            }

            // Try to get image dimensions for common formats
            if (str_contains($data['content_type'] ?? '', 'image/')) {
                $dimensions = $this->getImageDimensions($imageUrl);
                if ($dimensions) {
                    $data['width'] = $dimensions['width'];
                    $data['height'] = $dimensions['height'];
                }
            }
        } catch (\Exception $e) {
            // If we can't fetch image metadata, just continue with null values
            echo "Could not fetch image data for $imageUrl: " . $e->getMessage() . "\n";
        }

        return $data;
    }

    /**
     * Get image dimensions without downloading entire file
     */
    private function getImageDimensions(string $imageUrl): ?array
    {
        try {
            // Use getimagesizefromstring with a HEAD request and partial content
            $response = $this->client->get($imageUrl, [
                'headers' => ['Range' => 'bytes=0-32768'],  // Get first 32KB
                'allow_redirects' => true
            ]);
            
            $imageData = $response->getBody()->getContents();
            $dimensions = getimagesizefromstring($imageData);
            
            if ($dimensions !== false) {
                return [
                    'width' => $dimensions[0],
                    'height' => $dimensions[1]
                ];
            }
        } catch (\Exception $e) {
            // Can't determine dimensions
        }

        return null;
    }

    /**
     * Extract external and internal JavaScript files from HTML page and save to database
     */
    private function extractScripts(DomCrawler $crawler, string $pageUrl, int $pageId): void
    {
        $scriptCount = 0;
        
        // Extract external scripts (<script src="...">)
        $crawler->filter('script[src]')->each(function (DomCrawler $node) use ($pageUrl, $pageId, &$scriptCount) {
            try {
                $scriptCount++;
                $src = $node->attr('src');
                if (!$src) {
                    return;
                }

                // Convert relative URLs to absolute
                $scriptUrl = $this->makeAbsoluteUrl($src, $pageUrl);

                // Check if this script URL was already crawled for this job
                $stmt = $this->db->prepare(
                    "SELECT id FROM scripts WHERE crawl_job_id = ? AND url = ? LIMIT 1"
                );
                $stmt->execute([$this->crawlJobId, $scriptUrl]);
                $existingScript = $stmt->fetch();

                // If script already exists, skip fetching metadata
                if ($existingScript) {
                    echo "Skipping already crawled script: $scriptUrl\n";
                    return;
                }

                // Fetch script metadata (only for new scripts)
                $scriptData = $this->getScriptData($scriptUrl);

                // Save external script with full metadata
                $stmt = $this->db->prepare(
                    "INSERT INTO scripts (crawl_job_id, page_id, url, type, status_code, content_type, file_size, redirect_url, redirect_count, content_hash) " .
                    "VALUES (?, ?, ?, 'external', ?, ?, ?, ?, ?, ?) " .
                    "ON DUPLICATE KEY UPDATE status_code = VALUES(status_code), content_type = VALUES(content_type), file_size = VALUES(file_size), redirect_url = VALUES(redirect_url), redirect_count = VALUES(redirect_count), content_hash = VALUES(content_hash)"
                );
                $stmt->execute([
                    $this->crawlJobId,
                    $pageId,
                    $scriptUrl,
                    $scriptData['status_code'],
                    $scriptData['content_type'],
                    $scriptData['file_size'],
                    $scriptData['redirect_url'],
                    $scriptData['redirect_count'],
                    $scriptData['content_hash']
                ]);
            } catch (\Exception $e) {
                echo "Error processing script: " . $e->getMessage() . "\n";
            }
        });

        echo "Processed $scriptCount external JavaScript files from $pageUrl\n";
    }

    /**
     * Fetch script metadata (status code, content type, file size, redirects)
     */
    private function getScriptData(string $scriptUrl): array
    {
        $data = [
            'status_code' => null,
            'content_type' => null,
            'file_size' => null,
            'redirect_url' => null,
            'redirect_count' => 0,
            'content_hash' => null
        ];

        try {
            $response = $this->client->head($scriptUrl, ['allow_redirects' => true]);
            
            $data['status_code'] = $response->getStatusCode();
            $data['content_type'] = $response->getHeaderLine('Content-Type');
            
            // Get file size from Content-Length header
            $contentLength = $response->getHeaderLine('Content-Length');
            if ($contentLength) {
                $data['file_size'] = (int)$contentLength;
            }
            
            // Track redirects
            if ($response->hasHeader('X-Guzzle-Redirect-History')) {
                $redirectHistory = $response->getHeader('X-Guzzle-Redirect-History');
                $data['redirect_count'] = count($redirectHistory);
                if ($data['redirect_count'] > 0) {
                    $data['redirect_url'] = end($redirectHistory);
                }
            }
            
            // Try to get content hash by downloading the script (if small enough)
            if ($data['file_size'] !== null && $data['file_size'] < 500000) { // Only for scripts < 500KB
                try {
                    $getResponse = $this->client->get($scriptUrl, ['allow_redirects' => true]);
                    $scriptContent = $getResponse->getBody()->getContents();
                    $data['content_hash'] = hash('sha256', $scriptContent);
                } catch (\Exception $e) {
                    // Can't download content, that's ok
                }
            }
        } catch (\Exception $e) {
            // Script not accessible or error occurred
            $data['status_code'] = 0;
        }

        return $data;
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
            total_links = (SELECT COUNT(*) FROM links WHERE crawl_job_id = ?),
            total_images = (SELECT COUNT(*) FROM images WHERE crawl_job_id = ?),
            total_scripts = (SELECT COUNT(*) FROM scripts WHERE crawl_job_id = ?)
            WHERE id = ?"
        );
        $stmt->execute([$this->crawlJobId, $this->crawlJobId, $this->crawlJobId, $this->crawlJobId, $this->crawlJobId]);
    }

    /**
     * Check if URL points to an image file
     */
    private function isImageUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tiff', 'tif', 'avif'];
        
        return in_array($extension, $imageExtensions);
    }

    /**
     * Check if URL points to a JavaScript file
     */
    private function isScriptUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $scriptExtensions = ['js', 'mjs', 'jsx'];
        
        return in_array($extension, $scriptExtensions);
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

    /**
     * Extract favicon URL from HTML
     */
    private function extractFavicon(DomCrawler $domCrawler, string $pageUrl): ?string
    {
        // Try different favicon link methods in order of preference
        $faviconUrls = [];

        // 1. rel="icon" (modern)
        if ($domCrawler->filter('link[rel="icon"]')->count() > 0) {
            $href = $domCrawler->filter('link[rel="icon"]')->attr('href');
            if ($href) {
                $faviconUrls[] = $href;
            }
        }

        // 2. rel="shortcut icon" (legacy)
        if ($domCrawler->filter('link[rel="shortcut icon"]')->count() > 0) {
            $href = $domCrawler->filter('link[rel="shortcut icon"]')->attr('href');
            if ($href) {
                $faviconUrls[] = $href;
            }
        }

        // 3. rel="apple-touch-icon"
        if ($domCrawler->filter('link[rel="apple-touch-icon"]')->count() > 0) {
            $href = $domCrawler->filter('link[rel="apple-touch-icon"]')->attr('href');
            if ($href) {
                $faviconUrls[] = $href;
            }
        }

        // 4. Default favicon
        $faviconUrls[] = '/favicon.ico';

        // Convert relative URLs to absolute
        foreach ($faviconUrls as $faviconUrl) {
            if (empty($faviconUrl)) {
                continue;
            }

            // If it's an absolute URL, return it
            if (filter_var($faviconUrl, FILTER_VALIDATE_URL)) {
                return $faviconUrl;
            }

            // Convert relative to absolute
            $parts = parse_url($pageUrl);
            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'] ?? '';
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';

            if ($faviconUrl[0] === '/') {
                // Absolute path
                return $scheme . '://' . $host . $port . $faviconUrl;
            } else {
                // Relative path
                $path = $parts['path'] ?? '/';
                $pathDir = dirname($path);
                if ($pathDir !== '/') {
                    $pathDir .= '/';
                }
                return $scheme . '://' . $host . $port . $pathDir . $faviconUrl;
            }
        }

        return null;
    }
}
