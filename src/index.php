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

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #ffe4e9;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        input[type="text"] {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 16px;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #3498db;
        }

        button {
            padding: 12px 24px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }

        button:hover {
            background: #2980b9;
        }

        button:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
        }

        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status.pending { background: #f39c12; color: white; }
        .status.running { background: #3498db; color: white; }
        .status.completed { background: #27ae60; color: white; }
        .status.failed { background: #e74c3c; color: white; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #ecf0f1;
        }

        .tab {
            padding: 12px 20px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            color: #7f8c8d;
            font-weight: 500;
        }

        .tab.active {
            color: #3498db;
            border-bottom-color: #3498db;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .stat-box {
            background: #ecf0f1;
            padding: 15px;
            border-radius: 6px;
        }

        .stat-label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-sublabel {
            font-size: 11px;
            color: #95a5a6;
            margin-top: 3px;
        }

        .nofollow {
            color: #e74c3c;
            font-weight: 600;
        }

        .external {
            color: #3498db;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }

        .action-btn {
            padding: 6px 12px;
            font-size: 14px;
            margin-right: 5px;
        }

        .url-cell {
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* DataTables Styling */
        .dataTables_wrapper {
            padding: 20px 0;
        }

        .dataTables_filter input {
            padding: 8px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            margin-left: 10px;
        }

        .dataTables_length select {
            padding: 6px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            margin: 0 10px;
        }

        .dataTables_info {
            padding-top: 10px;
            color: #7f8c8d;
        }

        .dataTables_paginate {
            padding-top: 10px;
        }

        .dataTables_paginate .paginate_button {
            padding: 6px 12px;
            margin: 0 2px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            background: white;
            cursor: pointer;
        }

        .dataTables_paginate .paginate_button.current {
            background: #3498db;
            color: white !important;
            border-color: #3498db;
        }

        .dataTables_paginate .paginate_button:hover {
            background: #ecf0f1;
        }

        .dataTables_paginate .paginate_button.disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🕷️ Web Crawler</h1>

        <div class="card">
            <h2>Neue Domain crawlen</h2>
            <div class="input-group">
                <input type="text" id="domainInput" placeholder="example.com oder https://example.com" />
                <button onclick="startCrawl()">Crawl starten</button>
            </div>
        </div>

        <div class="card">
            <h2>Crawl Jobs</h2>
            <table id="jobsTable" class="display">
                <thead>
                    <tr>
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
                    <tr><td colspan="7" class="loading">Lade...</td></tr>
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
                    <table id="pagesTable" class="display">
                        <thead>
                            <tr>
                                <th>URL</th>
                                <th>Titel</th>
                                <th>Status</th>
                                <th>Gecrawlt</th>
                            </tr>
                        </thead>
                        <tbody id="pagesBody">
                            <tr><td colspan="4" class="loading">Keine Seiten gefunden</td></tr>
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

                    <h3 style="margin-top: 30px;">Duplicate Content</h3>
                    <div id="seoDuplicatesBody"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentJobId = null;
        let refreshInterval = null;

        async function startCrawl() {
            const domain = document.getElementById('domainInput').value.trim();
            if (!domain) {
                alert('Bitte Domain eingeben');
                return;
            }

            const formData = new FormData();
            formData.append('domain', domain);

            try {
                const response = await fetch('/api.php?action=start', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    document.getElementById('domainInput').value = '';
                    loadJobs();
                    alert('Crawl gestartet! Job ID: ' + data.job_id);
                } else {
                    alert('Fehler: ' + data.error);
                }
            } catch (e) {
                alert('Fehler beim Starten: ' + e.message);
            }
        }

        let jobsDataTable = null;

        async function loadJobs() {
            try {
                const response = await fetch('/api.php?action=jobs');
                const data = await response.json();

                if (data.success) {
                    // Destroy existing DataTable if it exists
                    if (jobsDataTable) {
                        jobsDataTable.destroy();
                    }

                    const tbody = document.getElementById('jobsBody');
                    tbody.innerHTML = data.jobs.map(job => `
                        <tr>
                            <td>${job.id}</td>
                            <td>${job.domain}</td>
                            <td><span class="status ${job.status}">${job.status}</span></td>
                            <td>${job.total_pages}</td>
                            <td>${job.total_links}</td>
                            <td>${job.started_at || '-'}</td>
                            <td>
                                <button class="action-btn" onclick="viewJob(${job.id})">Ansehen</button>
                                <button class="action-btn" onclick="recrawlJob(${job.id}, '${job.domain}')">Recrawl</button>
                                <button class="action-btn" onclick="deleteJob(${job.id})">Löschen</button>
                            </td>
                        </tr>
                    `).join('');

                    // Initialize DataTable
                    jobsDataTable = $('#jobsTable').DataTable({
                        pageLength: 25,
                        order: [[0, 'desc']],
                        language: {
                            search: 'Suchen:',
                            lengthMenu: 'Zeige _MENU_ Einträge',
                            info: 'Zeige _START_ bis _END_ von _TOTAL_ Einträgen',
                            infoEmpty: 'Keine Einträge verfügbar',
                            infoFiltered: '(gefiltert von _MAX_ Einträgen)',
                            paginate: {
                                first: 'Erste',
                                last: 'Letzte',
                                next: 'Nächste',
                                previous: 'Vorherige'
                            }
                        }
                    });
                }
            } catch (e) {
                console.error('Fehler beim Laden der Jobs:', e);
            }
        }

        async function viewJob(jobId) {
            currentJobId = jobId;
            document.getElementById('jobDetails').style.display = 'block';

            // Start auto-refresh every 1 second
            if (refreshInterval) clearInterval(refreshInterval);
            loadJobDetails();
            refreshInterval = setInterval(loadJobDetails, 1000);
        }

        async function loadJobDetails() {
            if (!currentJobId) return;

            try {
                // Load job status
                const statusResponse = await fetch(`/api.php?action=status&job_id=${currentJobId}`);
                const statusData = await statusResponse.json();

                if (statusData.success) {
                    const job = statusData.job;
                    const queue = statusData.queue;
                    document.getElementById('jobDomain').textContent = job.domain;

                    const queueInfo = queue ? `
                        <div class="stat-box">
                            <div class="stat-label">Warteschlange</div>
                            <div class="stat-value">${queue.pending || 0}</div>
                            <div class="stat-sublabel">noch zu crawlen</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Verarbeitet</div>
                            <div class="stat-value">${queue.completed || 0}</div>
                            <div class="stat-sublabel">abgeschlossen</div>
                        </div>
                    ` : '';

                    document.getElementById('jobStats').innerHTML = `
                        <div class="stat-box">
                            <div class="stat-label">Status</div>
                            <div class="stat-value"><span class="status ${job.status}">${job.status}</span></div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Seiten</div>
                            <div class="stat-value">${job.total_pages}</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Links</div>
                            <div class="stat-value">${job.total_links}</div>
                        </div>
                        ${queueInfo}
                    `;

                    // Stop refresh if completed or failed
                    if (job.status === 'completed' || job.status === 'failed') {
                        if (refreshInterval) {
                            clearInterval(refreshInterval);
                            refreshInterval = null;
                        }
                    }
                }

                // Load pages
                const pagesResponse = await fetch(`/api.php?action=pages&job_id=${currentJobId}`);
                const pagesData = await pagesResponse.json();

                if ($.fn.DataTable.isDataTable('#pagesTable')) {
                    $('#pagesTable').DataTable().destroy();
                }

                if (pagesData.success && pagesData.pages.length > 0) {
                    document.getElementById('pagesBody').innerHTML = pagesData.pages.map(page => `
                        <tr>
                            <td class="url-cell" title="${page.url}">${page.url}</td>
                            <td>${page.title || '-'}</td>
                            <td>${page.status_code}</td>
                            <td>${page.crawled_at}</td>
                        </tr>
                    `).join('');

                    $('#pagesTable').DataTable({
                        pageLength: 50,
                        language: {
                            search: 'Suchen:',
                            lengthMenu: 'Zeige _MENU_ Einträge',
                            info: 'Zeige _START_ bis _END_ von _TOTAL_ Einträgen',
                            infoEmpty: 'Keine Einträge verfügbar',
                            infoFiltered: '(gefiltert von _MAX_ Einträgen)',
                            paginate: {
                                first: 'Erste',
                                last: 'Letzte',
                                next: 'Nächste',
                                previous: 'Vorherige'
                            }
                        }
                    });
                }

                // Load links
                const linksResponse = await fetch(`/api.php?action=links&job_id=${currentJobId}`);
                const linksData = await linksResponse.json();

                if ($.fn.DataTable.isDataTable('#linksTable')) {
                    $('#linksTable').DataTable().destroy();
                }

                if (linksData.success && linksData.links.length > 0) {
                    document.getElementById('linksBody').innerHTML = linksData.links.map(link => `
                        <tr>
                            <td class="url-cell" title="${link.source_url}">${link.source_url}</td>
                            <td class="url-cell" title="${link.target_url}">${link.target_url}</td>
                            <td>${link.link_text || '-'}</td>
                            <td>${link.is_nofollow ? '<span class="nofollow">Ja</span>' : 'Nein'}</td>
                            <td>${link.is_internal ? 'Intern' : '<span class="external">Extern</span>'}</td>
                        </tr>
                    `).join('');

                    $('#linksTable').DataTable({
                        pageLength: 50,
                        language: {
                            search: 'Suchen:',
                            lengthMenu: 'Zeige _MENU_ Einträge',
                            info: 'Zeige _START_ bis _END_ von _TOTAL_ Einträgen',
                            infoEmpty: 'Keine Einträge verfügbar',
                            infoFiltered: '(gefiltert von _MAX_ Einträgen)',
                            paginate: {
                                first: 'Erste',
                                last: 'Letzte',
                                next: 'Nächste',
                                previous: 'Vorherige'
                            }
                        }
                    });
                }

                // Load broken links
                const brokenResponse = await fetch(`/api.php?action=broken-links&job_id=${currentJobId}`);
                const brokenData = await brokenResponse.json();

                if ($.fn.DataTable.isDataTable('#brokenTable')) {
                    $('#brokenTable').DataTable().destroy();
                }

                if (brokenData.success && brokenData.broken_links.length > 0) {
                    document.getElementById('brokenBody').innerHTML = brokenData.broken_links.map(page => `
                        <tr>
                            <td class="url-cell" title="${page.url}">${page.url}</td>
                            <td><span class="status failed">${page.status_code || 'Error'}</span></td>
                            <td>${page.title || '-'}</td>
                            <td>${page.crawled_at}</td>
                        </tr>
                    `).join('');

                    $('#brokenTable').DataTable({
                        pageLength: 25,
                        language: {
                            search: 'Suchen:',
                            lengthMenu: 'Zeige _MENU_ Einträge',
                            info: 'Zeige _START_ bis _END_ von _TOTAL_ Einträgen',
                            infoEmpty: 'Keine Einträge verfügbar',
                            infoFiltered: '(gefiltert von _MAX_ Einträgen)',
                            paginate: {
                                first: 'Erste',
                                last: 'Letzte',
                                next: 'Nächste',
                                previous: 'Vorherige'
                            }
                        }
                    });
                } else {
                    document.getElementById('brokenBody').innerHTML = '<tr><td colspan="4" class="loading">Keine defekten Links gefunden</td></tr>';
                }

                // Load SEO analysis
                const seoResponse = await fetch(`/api.php?action=seo-analysis&job_id=${currentJobId}`);
                const seoData = await seoResponse.json();

                if (seoData.success) {
                    // SEO Stats
                    document.getElementById('seoStats').innerHTML = `
                        <div class="stat-box">
                            <div class="stat-label">Total Pages</div>
                            <div class="stat-value">${seoData.total_pages}</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Pages with Issues</div>
                            <div class="stat-value">${seoData.issues.length}</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Duplicates Found</div>
                            <div class="stat-value">${seoData.duplicates.length}</div>
                        </div>
                    `;

                    // SEO Issues
                    if ($.fn.DataTable.isDataTable('#seoTable')) {
                        $('#seoTable').DataTable().destroy();
                    }

                    if (seoData.issues.length > 0) {
                        document.getElementById('seoIssuesBody').innerHTML = seoData.issues.map(item => `
                            <tr>
                                <td class="url-cell" title="${item.url}">${item.url}</td>
                                <td>${item.title || '-'} (${item.title_length})</td>
                                <td>${item.meta_description ? item.meta_description.substring(0, 50) + '...' : '-'} (${item.meta_length})</td>
                                <td><span class="nofollow">${item.issues.join(', ')}</span></td>
                            </tr>
                        `).join('');

                        $('#seoTable').DataTable({
                            pageLength: 25,
                            language: {
                                search: 'Suchen:',
                                lengthMenu: 'Zeige _MENU_ Einträge',
                                info: 'Zeige _START_ bis _END_ von _TOTAL_ Einträgen',
                                infoEmpty: 'Keine Einträge verfügbar',
                                infoFiltered: '(gefiltert von _MAX_ Einträgen)',
                                paginate: {
                                    first: 'Erste',
                                    last: 'Letzte',
                                    next: 'Nächste',
                                    previous: 'Vorherige'
                                }
                            }
                        });
                    } else {
                        document.getElementById('seoIssuesBody').innerHTML = '<tr><td colspan="4" class="loading">Keine SEO-Probleme gefunden</td></tr>';
                    }

                    // Duplicates
                    if (seoData.duplicates.length > 0) {
                        document.getElementById('seoDuplicatesBody').innerHTML = seoData.duplicates.map(dup => `
                            <div class="stat-box" style="margin-bottom: 15px;">
                                <div class="stat-label">Duplicate ${dup.type}</div>
                                <div style="font-size: 14px; margin: 10px 0;"><strong>${dup.content}</strong></div>
                                <div style="font-size: 12px;">Found on ${dup.urls.length} pages:</div>
                                <ul style="margin-top: 5px; font-size: 12px;">
                                    ${dup.urls.map(url => `<li>${url}</li>`).join('')}
                                </ul>
                            </div>
                        `).join('');
                    } else {
                        document.getElementById('seoDuplicatesBody').innerHTML = '<p>Keine doppelten Inhalte gefunden</p>';
                    }
                }

                // Load redirects
                const redirectsResponse = await fetch(`/api.php?action=redirects&job_id=${currentJobId}`);
                const redirectsData = await redirectsResponse.json();

                if (redirectsData.success) {
                    const stats = redirectsData.stats;

                    // Redirect Stats
                    document.getElementById('redirectStats').innerHTML = `
                        <div class="stat-box">
                            <div class="stat-label">Total Redirects</div>
                            <div class="stat-value">${stats.total}</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Permanent (301/308)</div>
                            <div class="stat-value">${stats.permanent}</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Temporary (302/303/307)</div>
                            <div class="stat-value">${stats.temporary}</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Excessive (>${stats.threshold})</div>
                            <div class="stat-value" style="color: ${stats.excessive > 0 ? '#e74c3c' : '#27ae60'}">${stats.excessive}</div>
                            <div class="stat-sublabel">threshold: ${stats.threshold}</div>
                        </div>
                    `;

                    // Redirect Table
                    if ($.fn.DataTable.isDataTable('#redirectsTable')) {
                        $('#redirectsTable').DataTable().destroy();
                    }

                    if (redirectsData.redirects.length > 0) {
                        document.getElementById('redirectsBody').innerHTML = redirectsData.redirects.map(redirect => {
                            const isExcessive = redirect.redirect_count > stats.threshold;
                            const isPermRedirect = redirect.status_code == 301 || redirect.status_code == 308;
                            const redirectType = isPermRedirect ? 'Permanent' : 'Temporary';

                            return `
                                <tr style="${isExcessive ? 'background-color: #fff3cd;' : ''}">
                                    <td class="url-cell" title="${redirect.url}">${redirect.url}</td>
                                    <td class="url-cell" title="${redirect.redirect_url || '-'}">${redirect.redirect_url || '-'}</td>
                                    <td><span class="status ${isPermRedirect ? 'completed' : 'running'}">${redirect.status_code}</span></td>
                                    <td><strong ${isExcessive ? 'style="color: #e74c3c;"' : ''}>${redirect.redirect_count}</strong></td>
                                    <td>${redirectType}</td>
                                </tr>
                            `;
                        }).join('');

                        $('#redirectsTable').DataTable({
                            pageLength: 25,
                            language: {
                                search: 'Suchen:',
                                lengthMenu: 'Zeige _MENU_ Einträge',
                                info: 'Zeige _START_ bis _END_ von _TOTAL_ Einträgen',
                                infoEmpty: 'Keine Einträge verfügbar',
                                infoFiltered: '(gefiltert von _MAX_ Einträgen)',
                                paginate: {
                                    first: 'Erste',
                                    last: 'Letzte',
                                    next: 'Nächste',
                                    previous: 'Vorherige'
                                }
                            }
                        });
                    } else {
                        document.getElementById('redirectsBody').innerHTML = '<tr><td colspan="5" class="loading">Keine Redirects gefunden</td></tr>';
                    }
                }

                // Update jobs table
                loadJobs();
            } catch (e) {
                console.error('Fehler beim Laden der Details:', e);
            }
        }

        async function deleteJob(jobId) {
            if (!confirm('Job wirklich löschen?')) return;

            const formData = new FormData();
            formData.append('job_id', jobId);

            try {
                const response = await fetch('/api.php?action=delete', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    loadJobs();
                    if (currentJobId === jobId) {
                        document.getElementById('jobDetails').style.display = 'none';
                        currentJobId = null;
                    }
                }
            } catch (e) {
                alert('Fehler beim Löschen: ' + e.message);
            }
        }

        async function recrawlJob(jobId, domain) {
            if (!confirm('Job-Ergebnisse löschen und neu crawlen?')) return;

            const formData = new FormData();
            formData.append('job_id', jobId);
            formData.append('domain', domain);

            try {
                const response = await fetch('/api.php?action=recrawl', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    loadJobs();
                    alert('Recrawl gestartet! Job ID: ' + data.job_id);
                } else {
                    alert('Fehler: ' + data.error);
                }
            } catch (e) {
                alert('Fehler beim Recrawl: ' + e.message);
            }
        }

        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            event.target.classList.add('active');
            document.getElementById(tab + '-tab').classList.add('active');
        }

        // Initial load
        loadJobs();
        setInterval(loadJobs, 5000);
    </script>
</body>
</html>
