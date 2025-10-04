/**
 * Web Crawler - Database Schema
 *
 * @copyright Copyright (c) 2025 Martin Kiesewetter
 * @author    Martin Kiesewetter <mki@kies-media.de>
 * @link      https://kies-media.de
 */

-- Database initialization script for Web Crawler

-- Crawl Jobs Table
CREATE TABLE IF NOT EXISTS crawl_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    total_pages INT DEFAULT 0,
    total_links INT DEFAULT 0,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_domain (domain),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pages Table
CREATE TABLE IF NOT EXISTS pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    crawl_job_id INT NOT NULL,
    url VARCHAR(2048) NOT NULL,
    title VARCHAR(500),
    meta_description TEXT,
    status_code INT,
    content_type VARCHAR(100),
    redirect_url VARCHAR(2048),
    redirect_count INT DEFAULT 0,
    crawled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (crawl_job_id) REFERENCES crawl_jobs(id) ON DELETE CASCADE,
    INDEX idx_crawl_job (crawl_job_id),
    INDEX idx_url (url(255)),
    INDEX idx_status_code (status_code),
    INDEX idx_redirect_count (redirect_count),
    UNIQUE KEY unique_job_url (crawl_job_id, url(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Links Table
CREATE TABLE IF NOT EXISTS links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    crawl_job_id INT NOT NULL,
    source_url VARCHAR(2048) NOT NULL,
    target_url VARCHAR(2048) NOT NULL,
    link_text VARCHAR(1000),
    is_nofollow BOOLEAN DEFAULT FALSE,
    is_internal BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY (crawl_job_id) REFERENCES crawl_jobs(id) ON DELETE CASCADE,
    INDEX idx_page (page_id),
    INDEX idx_crawl_job (crawl_job_id),
    INDEX idx_source_url (source_url(255)),
    INDEX idx_target_url (target_url(255)),
    INDEX idx_nofollow (is_nofollow)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Queue Table for parallel processing
CREATE TABLE IF NOT EXISTS crawl_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    crawl_job_id INT NOT NULL,
    url VARCHAR(2048) NOT NULL,
    depth INT DEFAULT 0,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    retry_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (crawl_job_id) REFERENCES crawl_jobs(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_crawl_job (crawl_job_id),
    UNIQUE KEY unique_job_url (crawl_job_id, url(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
