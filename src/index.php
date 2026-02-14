<!DOCTYPE html>
<!--
/**
 * Web Crawler - Main Interface
 *
 * @copyright Copyright (c) 2025 Martin Kiesewetter
 * @author    Martin Kiesewetter <mki@kies-media.de>
 * @link      https://kies-media.de
 */
-->
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Crawler</title>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">

    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>

    <!-- Custom Styles -->
    <link rel="stylesheet" href="public/css/index.css">
</head>
<body>
    <div class="container">
        <h1>🕷️ Web Crawler</h1>

        <div class="card">
            <h2>Neue Domain crawlen</h2>
            <div class="input-group">
                <input type="text" id="domainInput" placeholder="example.com oder https://example.com" onkeypress="if(event.key==='Enter') startCrawl()" />
                <button onclick="startCrawl()">Crawl starten</button>
            </div>
        </div>

        <div class="card">
            <h2>Crawl Jobs</h2>
            <table id="jobsTable" class="display">
                <thead>
                    <tr>
                        <th></th>
                        <th>ID</th>
                        <th>Domain</th>
                        <th>Status</th>
                        <th>Seiten</th>
                        <th>Links</th>
                        <th>Gestartet</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody id="jobsBody">
                    <tr><td colspan="8" class="loading">Lade...</td></tr>
                </tbody>
            </table>
        </div>

        <div id="jobDetails" style="display: none;">
            <div class="card">
                <h2>Job Details: <span id="jobDomain"></span></h2>

                <div class="stats" id="jobStats"></div>

                <div class="tabs">
                    <button class="tab active" onclick="switchTab('pages')">Seiten</button>
                    <button class="tab" onclick="switchTab('links')">Links</button>
                    <button class="tab" onclick="switchTab('broken')">Broken Links</button>
                    <button class="tab" onclick="switchTab('redirects')">Redirects</button>
                    <button class="tab" onclick="switchTab('seo')">SEO Analysis</button>
                </div>

                <div class="tab-content active" id="pages-tab">
                    <div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center;">
                        <label for="assetTypeFilter" style="font-weight: bold; color: #2c3e50;">Inhaltstyp:</label>
                        <select id="assetTypeFilter" onchange="loadAssetsTable(this.value)" style="padding: 8px 12px; border: 1px solid #e0e0e0; border-radius: 4px; font-size: 14px; cursor: pointer;">
                            <option value="all">Alle Assets</option>
                            <option value="page">Seiten</option>
                            <option value="image">Bilder</option>
                            <option value="script">Scripts</option>
                        </select>
                    </div>
                    <table id="assetsTable" class="display">
                        <thead>
                            <tr>
                                <th>Typ</th>
                                <th>URL</th>
                                <th>Titel</th>
                                <th>Status</th>
                                <th>Gecrawlt</th>
                            </tr>
                        </thead>
                        <tbody id="assetsBody">
                            <tr><td colspan="5" class="loading">Keine Assets gefunden</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="tab-content" id="links-tab">
                    <table id="linksTable" class="display">
                        <thead>
                            <tr>
                                <th>Von</th>
                                <th>Nach</th>
                                <th>Link-Text</th>
                                <th>Nofollow</th>
                                <th>Typ</th>
                            </tr>
                        </thead>
                        <tbody id="linksBody">
                            <tr><td colspan="5" class="loading">Keine Links gefunden</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="tab-content" id="broken-tab">
                    <table id="brokenTable" class="display">
                        <thead>
                            <tr>
                                <th>URL</th>
                                <th>Status Code</th>
                                <th>Titel</th>
                                <th>Gecrawlt</th>
                            </tr>
                        </thead>
                        <tbody id="brokenBody">
                            <tr><td colspan="4" class="loading">Keine defekten Links gefunden</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="tab-content" id="redirects-tab">
                    <h3>Redirect Statistics</h3>
                    <div id="redirectStats" class="stats" style="margin-bottom: 20px;"></div>
                    <table id="redirectsTable" class="display">
                        <thead>
                            <tr>
                                <th>URL</th>
                                <th>Redirect To</th>
                                <th>Status Code</th>
                                <th>Redirect Count</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody id="redirectsBody">
                            <tr><td colspan="5" class="loading">Keine Redirects gefunden</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="tab-content" id="seo-tab">
                    <h3>SEO Issues</h3>
                    <div id="seoStats" style="margin-bottom: 20px;"></div>
                    <table id="seoTable" class="display">
                        <thead>
                            <tr>
                                <th>URL</th>
                                <th>Title (Länge)</th>
                                <th>Meta Description (Länge)</th>
                                <th>Issues</th>
                            </tr>
                        </thead>
                        <tbody id="seoIssuesBody">
                            <tr><td colspan="4" class="loading">Keine SEO-Probleme gefunden</td></tr>
                        </tbody>
                    </table>

                    <h3 style="margin-top: 30px;">Nofollow Links</h3>
                    <div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center;">
                        <label for="nofollowFilter" style="font-weight: bold; color: #2c3e50;">Filter:</label>
                        <select id="nofollowFilter" onchange="loadNofollowLinks(this.value)" style="padding: 8px 12px; border: 1px solid #e0e0e0; border-radius: 4px; font-size: 14px; cursor: pointer;">
                            <option value="all">Alle Nofollow-Links</option>
                            <option value="internal">Interne Nofollow</option>
                            <option value="external">Externe Nofollow</option>
                        </select>
                    </div>
                    <table id="nofollowTable" class="display">
                        <thead>
                            <tr>
                                <th>Von</th>
                                <th>Nach</th>
                                <th>Link-Text</th>
                                <th>Typ</th>
                            </tr>
                        </thead>
                        <tbody id="nofollowBody">
                            <tr><td colspan="4" class="loading">Keine Nofollow-Links gefunden</td></tr>
                        </tbody>
                    </table>

                    <h3 style="margin-top: 30px;">Duplicate Content</h3>
                    <div id="seoDuplicatesBody"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom JavaScript -->
    <script src="public/js/index.js"></script>
</body>
</html>
