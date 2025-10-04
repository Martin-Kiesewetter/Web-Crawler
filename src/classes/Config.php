<?php

/**
 * Web Crawler - Configuration Class
 *
 * @copyright Copyright (c) 2025 Martin Kiesewetter
 * @author    Martin Kiesewetter <mki@kies-media.de>
 * @link      https://kies-media.de
 */

namespace App;

class Config
{
    /**
     * Maximum number of redirects before warning
     */
    public const int MAX_REDIRECT_THRESHOLD = 3;

    /**
     * Maximum crawl depth
     */
    public const int MAX_CRAWL_DEPTH = 50;

    /**
     * Number of parallel requests
     */
    public const int CONCURRENCY = 10;
}
