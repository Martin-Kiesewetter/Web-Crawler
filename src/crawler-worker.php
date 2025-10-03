#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\Crawler;

if ($argc < 2) {
    die("Usage: php crawler-worker.php <job_id>\n");
}

$jobId = (int)$argv[1];

try {
    $db = Database::getInstance();

    // Get job details
    $stmt = $db->prepare("SELECT domain FROM crawl_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        die("Job not found\n");
    }

    echo "Starting crawl for: {$job['domain']}\n";

    $crawler = new Crawler($jobId);
    $crawler->start($job['domain']);

    echo "Crawl completed\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";

    // Mark job as failed
    $db = Database::getInstance();
    $stmt = $db->prepare("UPDATE crawl_jobs SET status = 'failed' WHERE id = ?");
    $stmt->execute([$jobId]);
}
