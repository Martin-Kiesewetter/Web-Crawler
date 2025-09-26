<?php


class webanalyse
{
    var $db;

    function __construct()
    {
        $this->db = mysqli_connect("localhost", "root", "", "screaming_frog");
    }


    function getWebsite($url)
    {
        // cURL-Session initialisieren
        $ch = curl_init();

        // cURL-Optionen setzen
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  // Antwort als String zurückgeben
        curl_setopt($ch, CURLOPT_HEADER, true);          // Header in der Antwort einschließen
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);  // Weiterleitungen folgen
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);           // Timeout nach 30 Sekunden
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'); // User Agent setzen
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // SSL-Zertifikat nicht prüfen (nur für Tests)

        // Anfrage ausführen
        $response = curl_exec($ch);

        // Fehler überprüfen
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => $error];
        }

        // Informationen abrufen
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        // cURL-Session schließen
        curl_close($ch);

        // Header und Body trennen
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Header in Array umwandeln
        $headerLines = explode("\r\n", trim($headers));
        $parsedHeaders = [];

        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $parsedHeaders[trim($key)] = trim($value);
            }
        }

        return [
            'url' => $effectiveUrl,
            'status_code' => $httpCode,
            // 'headers_raw' => $headers,
            'headers_parsed' => $parsedHeaders,
            'body' => $body,
            'response_time' => $totalTime,
            'body_size' => strlen($body)
        ];
    }

    // Multi-cURL Funktion für mehrere URLs
    function getMultipleWebsites($urls)
    {

        $results = [];
        $curlHandles = [];
        $multiHandle = curl_multi_init();

        // Einzelne cURL-Handles für jede URL erstellen
        foreach ($urls as $url) {
            $ch = curl_init();

            // cURL-Optionen setzen (gleich wie bei getWebsite)
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            // Handle zum Multi-Handle hinzufügen
            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[$url] = $ch;
        }

        // Alle Anfragen parallel ausführen
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);


        // Ergebnisse verarbeiten
        foreach ($urls as $url) {
            $ch = $curlHandles[$url];
            $response = curl_multi_getcontent($ch);

            // Fehler überprüfen
            if (curl_errno($ch)) {
                $error = curl_error($ch);
                $results[$url] = ['error' => $error];
            } else {
                // Informationen abrufen
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
                $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

                // Header und Body trennen
                $headers = substr($response, 0, $headerSize);
                $body = substr($response, $headerSize);

                // Header in Array umwandeln
                $headerLines = explode("\r\n", trim($headers));
                $parsedHeaders = [];

                foreach ($headerLines as $line) {
                    if (strpos($line, ':') !== false) {
                        list($key, $value) = explode(':', $line, 2);
                        $parsedHeaders[trim($key)] = trim($value);
                    }
                }

                $results[$url] = [
                    'url' => $effectiveUrl,
                    'status_code' => $httpCode,
                    'headers_parsed' => $parsedHeaders,
                    'body' => $body,
                    'response_time' => $totalTime,
                    'body_size' => strlen($body)
                ];
            }

            // Handle aus Multi-Handle entfernen und schließen
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        // Multi-Handle schließen
        curl_multi_close($multiHandle);

        return $results;
    }




    function processResults(int $crawlID, string $url, array $data)
    {
        if (!isset($data['error'])) {
            $status_code = $data['status_code'];
            $response_time = $data['response_time'];
            $body_size = $data['body_size'];
            $date = date('Y-m-d H:i:s');
            $body = $data['body'];

            $sql = "UPDATE urls SET 
            status_code = " . $status_code . ", 
            response_time = " . ($response_time * 1000) . ", 
            body_size = " . $body_size . ", 
            date = now(),
            body = '" . $this->db->real_escape_string($body) . "'

            WHERE url = '" . $this->db->real_escape_string($url) . "' AND crawl_id = " . $crawlID . " LIMIT 1";
            // echo $sql;

            $this->db->query($sql);
        } else {
            // Handle error case if needed
            echo "Fehler bei der Analyse von $url: " . $data['error'] . "\n";
        }

        $this->findNewUrls($crawlID, $body, $url);
    }


    function findNewUrls(int $crawlID, string $body, string $url) {




        $links = $this->extractLinks($body, $url);

        $temp = $this->db->query("select id from urls where url = '".$this->db->real_escape_string($url)."' and crawl_id = ".$crawlID." LIMIT 1")->fetch_all(MYSQLI_ASSOC);
        $vonUrlId = $temp[0]['id'];


        $this->db->query("delete from links where von = ".$vonUrlId);

        foreach($links as $l) {

            $u = $this->db->query("insert ignore into urls (url, crawl_id) values ('".$this->db->real_escape_string($l['absolute_url'])."',".$crawlID.")");
            $id = $this->db->insert_id;
            if ($id === 0) {
                $qwer = $this->db->query("select id from urls where url = '".$this->db->real_escape_string($l['absolute_url'])."' and crawl_id = ".$crawlID." LIMIT 1")->fetch_all(MYSQLI_ASSOC);
                $id = $qwer[0]['id'];
            }





            $sql_links = "insert ignore into links (von, nach, linktext, dofollow) values (
            ".$vonUrlId.",
            ".$id.",


            '".$this->db->real_escape_string(mb_convert_encoding($l['text'],"UTF-8"))."',
            ".(strstr($l['rel']??"", 'nofollow') === false ? 1 : 0)."


            )";

            echo $sql_links;

            $u = $this->db->query($sql_links);
        
            

        }



        print_r($links);


    }


    function doCrawl(int $crawlID)
    {

        $urls2toCrawl = $this->db->query("select * from urls where crawl_id = " . $crawlID . " and date is null LIMIT 2")->fetch_all(MYSQLI_ASSOC); // and date is not null


        $urls = [];
        foreach ($urls2toCrawl as $u) {
            $urls[] = $u['url'];
        }

        $multipleResults = $this->getMultipleWebsites($urls);

        // print_r($multipleResults);
        foreach ($multipleResults as $url => $data) {

            $this->processResults($crawlID, $url, $data);
        }
    }















    function extractLinks($html, $baseUrl = '')
    {
        $links = [];

        // DOMDocument erstellen und HTML laden
        $dom = new DOMDocument();

        // Fehlerbehandlung für ungültiges HTML
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Alle <a> Tags finden
        $aTags = $dom->getElementsByTagName('a');

        foreach ($aTags as $index => $aTag) {
            $href = $aTag->getAttribute('href');
            $text = trim($aTag->textContent);
            $rel = $aTag->getAttribute('rel');
            $title = $aTag->getAttribute('title');
            $target = $aTag->getAttribute('target');

            // Nur Links mit href-Attribut
            if (!empty($href)) {
                // Relative URLs zu absoluten URLs konvertieren
                $absoluteUrl = $href;
                if (!empty($baseUrl) && !preg_match('/^https?:\/\//', $href)) {
                    $absoluteUrl = rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
                }

                $links[] = [
                    'index' => $index + 1,
                    'href' => $href,
                    'absolute_url' => $absoluteUrl,
                    'text' => $text,
                    'rel' => $rel ?: null,
                    'title' => $title ?: null,
                    'target' => $target ?: null,
                    'is_external' => $this->isExternalLink($href, $baseUrl),
                    'link_type' => $this->getLinkType($href),
                    'is_internal' => $this->isInternalLink($href, $baseUrl)?1:0
                ];
            }
        }

        return $links;
    }

    /**
     * Prüft ob ein Link extern ist
     */
    private function isExternalLink($href, $baseUrl)
    {
        if (empty($baseUrl)) return null;

        // Relative Links sind intern
        if (!preg_match('/^https?:\/\//', $href)) {
            return false;
        }

        $baseDomain = parse_url($baseUrl, PHP_URL_HOST);
        $linkDomain = parse_url($href, PHP_URL_HOST);

        return $baseDomain !== $linkDomain;
    }

    private function isInternalLink($href, $baseUrl)
    {
        if (empty($baseUrl)) return null;

        // Relative Links sind intern
        if (!preg_match('/^https?:\/\//', $href)) {
            return true;
        }

        $baseDomain = parse_url($baseUrl, PHP_URL_HOST);
        $linkDomain = parse_url($href, PHP_URL_HOST);

        return $baseDomain === $linkDomain;
    }

    /**
     * Bestimmt den Typ des Links
     */
    private function getLinkType($href)
    {
        if (empty($href)) return 'empty';
        if (strpos($href, 'mailto:') === 0) return 'email';
        if (strpos($href, 'tel:') === 0) return 'phone';
        if (strpos($href, '#') === 0) return 'anchor';
        if (strpos($href, 'javascript:') === 0) return 'javascript';
        if (preg_match('/^https?:\/\//', $href)) return 'absolute';
        return 'relative';
    }


    /**
     * Funktion zum Gruppieren der Links nach Typ
     */
    function groupLinksByType($links)
    {
        $grouped = [];

        foreach ($links as $link) {
            $type = $link['link_type'];
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $link;
        }

        return $grouped;
    }
}
