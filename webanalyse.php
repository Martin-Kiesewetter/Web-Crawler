<?php

declare(strict_types=1);

/**
 * Koordiniert Webseiten-Crawls und persistiert Antwortdaten in der Screaming Frog Datenbank.
 */
class WebAnalyse
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
    private const CURL_TIMEOUT = 30;

    /**
     * @var mysqli Verbindung zur Screaming Frog Datenbank.
     */
    private mysqli $db;

    public function __construct(?mysqli $connection = null)
    {
        $connection ??= mysqli_connect('localhost', 'root', '', 'screaming_frog');

        if (!$connection instanceof mysqli) {
            throw new RuntimeException('Verbindung zur Datenbank konnte nicht hergestellt werden: ' . mysqli_connect_error());
        }

        $connection->set_charset('utf8mb4');
        $this->db = $connection;
    }

    /**
     * Holt eine einzelne URL und gibt Response-Metadaten zurueck.
     *
     * @param string $url Zieladresse fuer den Abruf.
     * @return array<string,mixed> Antwortdaten oder ein "error"-Schluessel.
     */
    public function getWebsite(string $url): array
    {
        $handle = $this->createCurlHandle($url);
        $response = curl_exec($handle);

        if ($response === false) {
            $error = curl_error($handle);
            curl_close($handle);
            return ['error' => $error];
        }

        $info = curl_getinfo($handle);
        curl_close($handle);

        return $this->buildResponsePayload($response, $info);
    }

    /**
     * Ruft mehrere URLs parallel via curl_multi ab.
     *
     * @param array<int,string> $urls Liste von Ziel-URLs.
     * @return array<string,array<string,mixed>> Antworten je URL.
     */
    public function getMultipleWebsites(array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $results = [];
        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($urls as $url) {
            $handle = $this->createCurlHandle($url);
            $handles[$url] = $handle;
            curl_multi_add_handle($multiHandle, $handle);
        }

        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while ($running && $status === CURLM_OK) {
            if (curl_multi_select($multiHandle, 1.0) === -1) {
                usleep(100000);
            }

            do {
                $status = curl_multi_exec($multiHandle, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
        }

        foreach ($handles as $url => $handle) {
            $response = curl_multi_getcontent($handle);

            if ($response === false) {
                $results[$url] = ['error' => curl_error($handle)];
            } else {
                $results[$url] = $this->buildResponsePayload($response, curl_getinfo($handle));
            }

            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    /**
     * Persistiert Response-Daten und stoesst die Analyse der gefundenen Links an.
     *
     * @param int $crawlID Identifier der Crawl-Session.
     * @param string $url Ursprung-URL, deren Antwort verarbeitet wird.
     * @param array<string,mixed> $data Ergebnis der HTTP-Abfrage.
     */
    public function processResults(int $crawlID, string $url, array $data): void
    {
        if (isset($data['error'])) {
            error_log(sprintf('Fehler bei der Analyse von %s: %s', $url, $data['error']));
            return;
        }

        $body = (string)($data['body'] ?? '');

        $update = $this->db->prepare(
            'UPDATE urls
             SET status_code = ?, response_time = ?, body_size = ?, date = NOW(), body = ?
             WHERE url = ? AND crawl_id = ?
             LIMIT 1'
        );

        if ($update === false) {
            throw new RuntimeException('Update-Statement konnte nicht vorbereitet werden: ' . $this->db->error);
        }

        $statusCode = (int)($data['status_code'] ?? 0);
        $responseTimeMs = (int)round(((float)($data['response_time'] ?? 0)) * 1000);
        $bodySize = (int)($data['body_size'] ?? strlen($body));

        $update->bind_param('iiissi', $statusCode, $responseTimeMs, $bodySize, $body, $url, $crawlID);
        $update->execute();
        $update->close();

        $this->findNewUrls($crawlID, $body, $url);
    }

    /**
     * Extrahiert Links aus einer Antwort und legt neue URL-Datensaetze an.
     *
     * @param int $crawlID Identifier der Crawl-Session.
     * @param string $body HTML-Koerper der Antwort.
     * @param string $url Bearbeitete URL, dient als Kontext fuer relative Links.
     */
    public function findNewUrls(int $crawlID, string $body, string $url): void
    {
        if ($body === '') {
            return;
        }

        $links = $this->extractLinks($body, $url);
        if ($links === []) {
            return;
        }

        $originId = $this->resolveUrlId($crawlID, $url);
        if ($originId === null) {
            return;
        }

        $deleteLinksStmt = $this->db->prepare('DELETE FROM links WHERE von = ?');
        if ($deleteLinksStmt !== false) {
            $deleteLinksStmt->bind_param('i', $originId);
            $deleteLinksStmt->execute();
            $deleteLinksStmt->close();
        }

        $insertUrlStmt = $this->db->prepare('INSERT IGNORE INTO urls (url, crawl_id) VALUES (?, ?)');
        $selectUrlStmt = $this->db->prepare('SELECT id FROM urls WHERE url = ? AND crawl_id = ? LIMIT 1');
        $insertLinkStmt = $this->db->prepare('INSERT IGNORE INTO links (von, nach, linktext, dofollow) VALUES (?, ?, ?, ?)');

        if (!$insertUrlStmt || !$selectUrlStmt || !$insertLinkStmt) {
            throw new RuntimeException('Vorbereitete Statements konnten nicht erstellt werden: ' . $this->db->error);
        }

        foreach ($links as $link) {
            $absoluteUrl = (string)$link['absolute_url'];

            $insertUrlStmt->bind_param('si', $absoluteUrl, $crawlID);
            $insertUrlStmt->execute();

            $targetId = $this->db->insert_id;
            if ($targetId === 0) {
                $selectUrlStmt->bind_param('si', $absoluteUrl, $crawlID);
                $selectUrlStmt->execute();
                $result = $selectUrlStmt->get_result();
                $targetId = $result ? (int)($result->fetch_assoc()['id'] ?? 0) : 0;
            }

            if ($targetId === 0) {
                continue;
            }

            $linkText = $this->normaliseText((string)($link['text'] ?? ''));
            $isFollow = (int)(strpos((string)($link['rel'] ?? ''), 'nofollow') !== false ? 0 : 1);

            $insertLinkStmt->bind_param('iisi', $originId, $targetId, $linkText, $isFollow);
            $insertLinkStmt->execute();
        }

        $insertUrlStmt->close();
        $selectUrlStmt->close();
        $insertLinkStmt->close();
    }

    /**
     * Startet einen Crawl-Durchlauf fuer unbehandelte URLs.
     *
     * @param int $crawlID Identifier der Crawl-Session.
     */
    public function doCrawl(int $crawlID): void
    {
        $statement = $this->db->prepare(
            'SELECT url FROM urls WHERE crawl_id = ? AND date IS NULL LIMIT 50'
        );

        if ($statement === false) {
            return;
        }

        $statement->bind_param('i', $crawlID);
        $statement->execute();
        $result = $statement->get_result();

        if (!$result instanceof mysqli_result) {
            $statement->close();
            return;
        }

        $urls = [];
        while ($row = $result->fetch_assoc()) {
            $urls[] = $row['url'];
        }

        $result->free();
        $statement->close();

        if ($urls === []) {
            return;
        }

        foreach ($this->getMultipleWebsites($urls) as $url => $data) {
            $this->processResults($crawlID, $url, $data);
        }
    }

    /**
     * Parst HTML-Inhalt und liefert eine strukturierte Liste gefundener Links.
     *
     * @param string $html Rohes HTML-Dokument.
     * @param string $baseUrl Basis-URL fuer die Aufloesung relativer Pfade.
     * @return array<int,array<string,mixed>> Gesammelte Linkdaten.
     */
    public function extractLinks(string $html, string $baseUrl = ''): array
    {
        $links = [];

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($dom->getElementsByTagName('a') as $index => $aTag) {
            $href = trim($aTag->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $absoluteUrl = $this->resolveUrl($href, $baseUrl);
            $text = $this->normaliseText(trim($aTag->textContent));
            $rel = $aTag->getAttribute('rel');
            $title = $aTag->getAttribute('title');
            $target = $aTag->getAttribute('target');

            $links[] = [
                'index' => $index + 1,
                'href' => $href,
                'absolute_url' => $absoluteUrl,
                'text' => $text,
                'rel' => $rel !== '' ? $rel : null,
                'title' => $title !== '' ? $title : null,
                'target' => $target !== '' ? $target : null,
                'is_external' => $this->isExternalLink($absoluteUrl, $baseUrl),
                'link_type' => $this->getLinkType($href),
                'is_internal' => $this->isInternalLink($absoluteUrl, $baseUrl) ? 1 : 0,
            ];
        }

        return $links;
    }

    /**
     * Prueft, ob ein Link aus Sicht der Basis-URL extern ist.
     *
     * @param string $href Ziel des Links.
     * @param string $baseUrl Ausgangsadresse zur Domainabgleichung.
     * @return bool|null True fuer extern, false fuer intern, null falls undefiniert.
     */
    private function isExternalLink(string $href, string $baseUrl): ?bool
    {
        if ($baseUrl === '') {
            return null;
        }

        $baseDomain = parse_url($baseUrl, PHP_URL_HOST);
        $linkDomain = parse_url($href, PHP_URL_HOST);

        if ($baseDomain === null || $linkDomain === null) {
            return null;
        }

        return !hash_equals($baseDomain, $linkDomain);
    }

    /**
     * Prueft, ob ein Link derselben Domain wie die Basis-URL entspricht.
     *
     * @param string $href Ziel des Links.
     * @param string $baseUrl Ausgangsadresse zur Domainabgleichung.
     * @return bool|null True fuer intern, false fuer extern, null falls undefiniert.
     */
    private function isInternalLink(string $href, string $baseUrl): ?bool
    {
        if ($baseUrl === '') {
            return null;
        }

        $baseDomain = parse_url($baseUrl, PHP_URL_HOST);
        $linkDomain = parse_url($href, PHP_URL_HOST);

        if ($baseDomain === null || $linkDomain === null) {
            return null;
        }

        return hash_equals($baseDomain, $linkDomain);
    }

    /**
     * Leitet den Link-Typ anhand gaengiger Protokolle und Muster ab.
     *
     * @param string $href Ziel des Links.
     * @return string Beschreibender Typ wie "absolute" oder "email".
     */
    private function getLinkType(string $href): string
    {
        if ($href === '') {
            return 'empty';
        }

        $lower = strtolower($href);
        if (strpos($lower, 'mailto:') === 0) {
            return 'email';
        }
        if (strpos($lower, 'tel:') === 0) {
            return 'phone';
        }
        if (strpos($lower, '#') === 0) {
            return 'anchor';
        }
        if (strpos($lower, 'javascript:') === 0) {
            return 'javascript';
        }
        if (filter_var($href, FILTER_VALIDATE_URL)) {
            return 'absolute';
        }

        return 'relative';
    }

    /**
     * Gruppiert Links anhand ihres vorab bestimmten Typs.
     *
     * @param array<int,array<string,mixed>> $links Liste der extrahierten Links.
     * @return array<string,array<int,array<string,mixed>>> Links nach Typ gruppiert.
     */
    public function groupLinksByType(array $links): array
    {
        $grouped = [];

        foreach ($links as $link) {
            $type = (string)($link['link_type'] ?? 'unknown');
            $grouped[$type][] = $link;
        }

        return $grouped;
    }

    /**
     * Erstellt ein konfiguriertes Curl-Handle fuer einen Request.
     *
     * @return CurlHandle
     */
    private function createCurlHandle(string $url)
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Konnte Curl-Handle nicht initialisieren: ' . $url);
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        return $handle;
    }

    /**
     * Splittet Header und Body und bereitet das Antwort-Array auf.
     *
     * @param string $response Vollstaendige Response inkl. Header.
     * @param array<string,mixed> $info curl_getinfo Ergebnis.
     * @return array<string,mixed>
     */
    private function buildResponsePayload(string $response, array $info): array
    {
        $headerSize = (int)($info['header_size'] ?? 0);
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        return [
            'url' => $info['url'] ?? ($info['redirect_url'] ?? ''),
            'status_code' => (int)($info['http_code'] ?? 0),
            'headers_parsed' => $this->parseHeaders($headers),
            'body' => $body,
            'response_time' => (float)($info['total_time'] ?? 0.0),
            'body_size' => strlen($body),
        ];
    }

    /**
     * Wandelt Header-String in ein assoziatives Array um.
     *
     * @param string $headers Roh-Header.
     * @return array<string,string>
     */
    private function parseHeaders(string $headers): array
    {
        $parsed = [];
        foreach (preg_split('/\r?\n/', trim($headers)) as $line) {
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $parsed[trim($key)] = trim($value);
        }

        return $parsed;
    }

    /**
     * Normalisiert relativen Pfad gegenueber einer Basis-URL zu einer absoluten Adresse.
     */
    private function resolveUrl(string $href, string $baseUrl): string
    {
        if ($href === '' || filter_var($href, FILTER_VALIDATE_URL)) {
            return $href;
        }

        if ($baseUrl === '') {
            return $href;
        }

        $baseParts = parse_url($baseUrl);
        if ($baseParts === false || !isset($baseParts['scheme'], $baseParts['host'])) {
            return $href;
        }

        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $basePath = $baseParts['path'] ?? '/';

        if (strpos($href, '/') === 0) {
            $path = $href;
        } else {
            if (substr($basePath, -1) !== '/') {
                $basePath = preg_replace('#/[^/]*$#', '/', $basePath) ?: '/';
            }
            $path = $basePath . $href;
        }

        return sprintf('%s://%s%s%s', $scheme, $host, $port, '/' . ltrim($path, '/'));
    }

    /**
     * Sorgt fuer sauberen UTF-8 Text ohne Steuerzeichen.
     */
    private function normaliseText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', $text) ?? '';
        $encoding = mb_detect_encoding($normalized, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8';

        return trim(mb_convert_encoding($normalized, 'UTF-8', $encoding));
    }

    /**
     * Ermittelt die ID einer URL innerhalb eines Crawl-Durchlaufs.
     */
    private function resolveUrlId(int $crawlID, string $url): ?int
    {
        $statement = $this->db->prepare('SELECT id FROM urls WHERE url = ? AND crawl_id = ? LIMIT 1');
        if ($statement === false) {
            return null;
        }

        $statement->bind_param('si', $url, $crawlID);
        $statement->execute();
        $result = $statement->get_result();
        $id = $result ? $result->fetch_assoc()['id'] ?? null : null;
        $statement->close();

        return $id !== null ? (int)$id : null;
    }
}
