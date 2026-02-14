/**
 * Web Crawler - Main JavaScript
 *
 * @copyright Copyright (c) 2025 Martin Kiesewetter
 * @author    Martin Kiesewetter <mki@kies-media.de>
 * @link      https://kies-media.de
 */

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
            const jobId = data.job_id;
            
            viewJob(jobId);
            
            const notification = document.createElement('div');
            notification.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #27ae60; color: white; padding: 15px 25px; border-radius: 6px; z-index: 1000; font-weight: bold;';
            notification.textContent = 'Crawl gestartet! Job ID: ' + jobId;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 3000);
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
            if (jobsDataTable) {
                jobsDataTable.destroy();
            }

            const tbody = document.getElementById('jobsBody');
            tbody.innerHTML = data.jobs.map(job => {
                let faviconHtml = '<div class="favicon-cell">-</div>';
                if (job.favicon_url) {
                    faviconHtml = `<div class="favicon-cell"><img src="${job.favicon_url}" alt="Favicon" class="favicon-img" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 16 16%22%3E%3Crect fill=%22%23ccc%22 width=%2216%22 height=%2216%22/%3E%3Ctext x=%228%22 y=%2212%22 text-anchor=%22middle%22 font-size=%2210%22 fill=%22%23999%22%3E?%3C/text%3E%3C/svg%3E'" /></div>`;
                }

                return `
                <tr>
                    <td>${faviconHtml}</td>
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
            `;
            }).join('');

            jobsDataTable = $('#jobsTable').DataTable({
                pageLength: 25,
                order: [[1, 'desc']],
                columns: [ {},{},{},{},{},{},{}, { orderable: false } ],
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

    if (refreshInterval) clearInterval(refreshInterval);
    loadJobDetails();
    refreshInterval = setInterval(loadJobDetails, 1000);
}

async function loadJobDetails() {
    if (!currentJobId) return;

    try {
        const statusResponse = await fetch(`/api.php?action=status&job_id=${currentJobId}`);
        const statusData = await statusResponse.json();

        if (statusData.success) {
            const job = statusData.job;
            const queue = statusData.queue;
            document.getElementById('jobDomain').textContent = job.domain;

            let total = (queue ? (queue.pending + queue.completed) : 0) || job.total_pages || 1;
            let completed = (queue ? queue.completed : 0) || 0;
            let percentage = total > 0 ? Math.round((completed / total) * 100) : 0;

            const progressBar = `
                <div style="margin-top: 20px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 14px; color: #7f8c8d;">Fortschritt</span>
                        <span style="font-size: 14px; font-weight: bold; color: #2c3e50;">${percentage}%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${percentage}%">
                            ${percentage > 10 ? percentage + '%' : ''}
                        </div>
                    </div>
                </div>
            `;

            const queueInfo = queue ? `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="stat-box">
                        <div class="stat-label">Ausstehend</div>
                        <div class="stat-value">${queue.pending || 0}</div>
                        <div style="font-size: 12px; color: #7f8c8d; margin-top: 5px;">noch zu crawlen</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Abgeschlossen</div>
                        <div class="stat-value">${queue.completed || 0}</div>
                        <div style="font-size: 12px; color: #7f8c8d; margin-top: 5px;">verarbeitet</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Verarbeitet</div>
                        <div class="stat-value">${queue.processing || 0}</div>
                        <div style="font-size: 12px; color: #7f8c8d; margin-top: 5px;">läuft gerade</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Fehler</div>
                        <div class="stat-value">${queue.failed || 0}</div>
                        <div style="font-size: 12px; color: #7f8c8d; margin-top: 5px;">fehlgeschlagen</div>
                    </div>
                </div>
            ` : '';

            document.getElementById('jobStats').innerHTML = `
                <div class="stat-box">
                    <div class="stat-label">Status</div>
                    <div class="stat-value"><span class="status ${job.status}">${job.status}</span></div>
                </div>
                ${progressBar}
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div class="stat-box">
                        <div class="stat-label">Seiten</div>
                        <div class="stat-value">${job.total_pages}</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Links</div>
                        <div class="stat-value">${job.total_links}</div>
                    </div>
                </div>
                ${queueInfo}
            `;

            if (job.status === 'completed' || job.status === 'failed') {
                if (refreshInterval) {
                    clearInterval(refreshInterval);
                    refreshInterval = null;
                }
            }
        }

        if (currentJobId) {
            const selectedFilter = document.getElementById('assetTypeFilter')?.value || 'all';
            loadAssetsTable(selectedFilter);
        }

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

        const seoResponse = await fetch(`/api.php?action=seo-analysis&job_id=${currentJobId}`);
        const seoData = await seoResponse.json();

        if (seoData.success) {
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
            
            loadNofollowLinks('all');
        }

        const redirectsResponse = await fetch(`/api.php?action=redirects&job_id=${currentJobId}`);
        const redirectsData = await redirectsResponse.json();

        if (redirectsData.success) {
            const stats = redirectsData.stats;

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
    
    if (tab === 'pages') {
        const selectedFilter = document.getElementById('assetTypeFilter')?.value || 'all';
        loadAssetsTable(selectedFilter);
    }
}

async function loadNofollowLinks(filter = 'all') {
    if (!currentJobId) return;

    try {
        const response = await fetch(`/api.php?action=nofollow-links&job_id=${currentJobId}&filter=${filter}`);
        const data = await response.json();

        if ($.fn.DataTable.isDataTable('#nofollowTable')) {
            $('#nofollowTable').DataTable().destroy();
        }

        if (data.success && data.nofollow_links.length > 0) {
            document.getElementById('nofollowBody').innerHTML = data.nofollow_links.map(link => `
                <tr>
                    <td class="url-cell" title="${link.source_url}">${link.source_url}</td>
                    <td class="url-cell" title="${link.target_url}">${link.target_url}</td>
                    <td>${link.link_text || '-'}</td>
                    <td>${link.is_internal ? '<span style="color: #3498db;">Intern</span>' : '<span class="external">Extern</span>'}</td>
                </tr>
            `).join('');

            $('#nofollowTable').DataTable({
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
            document.getElementById('nofollowBody').innerHTML = '<tr><td colspan="4" class="loading">Keine Nofollow-Links gefunden</td></tr>';
        }
    } catch (error) {
        console.error('Error loading nofollow links:', error);
        document.getElementById('nofollowBody').innerHTML = '<tr><td colspan="4" class="loading">Fehler beim Laden</td></tr>';
    }
}

async function loadAssetsTable(type = 'all') {
    if (!currentJobId) return;

    try {
        const assetsResponse = await fetch(`/api.php?action=assets&job_id=${currentJobId}&type=${type}`);
        const assetsData = await assetsResponse.json();

        if ($.fn.DataTable.isDataTable('#assetsTable')) {
            $('#assetsTable').DataTable().destroy();
        }

        if (assetsData.success && assetsData.assets.length > 0) {
            document.getElementById('assetsBody').innerHTML = assetsData.assets.map(asset => {
                let typeLabel = asset.asset_type;
                let typeColor = '#7f8c8d';
                
                if (typeLabel === 'page') {
                    typeLabel = 'Seite';
                    typeColor = '#3498db';
                } else if (typeLabel === 'image') {
                    typeLabel = 'Bild';
                    typeColor = '#2ecc71';
                } else if (typeLabel === 'script') {
                    typeLabel = 'Script';
                    typeColor = '#e74c3c';
                }
                
                return `
                    <tr>
                        <td><span style="background: ${typeColor}; color: white; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: bold;">${typeLabel}</span></td>
                        <td class="url-cell" title="${asset.url}">${asset.url}</td>
                        <td>${asset.title || '-'}</td>
                        <td><span class="status ${asset.status_code >= 400 ? 'failed' : 'completed'}">${asset.status_code || 'N/A'}</span></td>
                        <td>${asset.crawled_at}</td>
                    </tr>
                `;
            }).join('');

            $('#assetsTable').DataTable({
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
        } else {
            document.getElementById('assetsBody').innerHTML = '<tr><td colspan="5" class="loading">Keine Assets gefunden</td></tr>';
        }
    } catch (error) {
        console.error('Error loading assets:', error);
        document.getElementById('assetsBody').innerHTML = '<tr><td colspan="5" class="loading">Fehler beim Laden</td></tr>';
    }
}

// Initial load
loadJobs();
setInterval(loadJobs, 5000);