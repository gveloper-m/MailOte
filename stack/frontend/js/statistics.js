// Statistics Functions
async function loadStatistics() {
    try {
        showSpinner(true);
        const refresh = document.getElementById('refresh-stats').checked;
        const response = await API.getStatistics(refresh);

        const stats = response.statistics || {};
        renderStatistics(stats, response.user, response.emails_analyzed, response.data_source);

        showToast('Statistics loaded successfully', 'success');
    } catch (error) {
        showToast(`Failed to load statistics: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
    }
}

function renderStatistics(stats, user, emailsAnalyzed, dataSource) {
    const container = document.getElementById('statistics-container');
    
    if (!stats || Object.keys(stats).length === 0) {
        container.innerHTML = '<p class="no-data">No statistics available</p>';
        return;
    }

    let html = `
        <div class="stats-header">
            <p><strong>User:</strong> ${escapeHtml(user)}</p>
            <p><strong>Emails Analyzed:</strong> ${emailsAnalyzed}</p>
            <p><strong>Data Source:</strong> ${dataSource}</p>
        </div>
        <div class="stats-grid">
    `;

    // Basic stats
    html += `
        <div class="stat-card">
            <h3>Total Emails</h3>
            <p class="stat-value">${stats.total_emails || 0}</p>
        </div>
        <div class="stat-card">
            <h3>Unique Senders</h3>
            <p class="stat-value">${stats.unique_senders || 0}</p>
        </div>
    `;

    // Email age breakdown
    if (stats.email_age_breakdown) {
        html += `
            <div class="stat-card full-width">
                <h3>Email Age Breakdown</h3>
                <ul class="stat-list">
                    <li>Past 24 hours: ${stats.email_age_breakdown.past_24_hours || 0}</li>
                    <li>Past 7 days: ${stats.email_age_breakdown.past_7_days || 0}</li>
                    <li>Past 30 days: ${stats.email_age_breakdown.past_30_days || 0}</li>
                    <li>Past year: ${stats.email_age_breakdown.past_year || 0}</li>
                    <li>Older: ${stats.email_age_breakdown.older || 0}</li>
                </ul>
            </div>
        `;
    }

    // Top senders
    if (stats.top_5_senders && stats.top_5_senders.length > 0) {
        html += `
            <div class="stat-card full-width">
                <h3>Top 5 Senders</h3>
                <ol class="stat-list">
                    ${stats.top_5_senders.map(sender => `
                        <li>${escapeHtml(sender.from)} - ${sender.count} emails</li>
                    `).join('')}
                </ol>
            </div>
        `;
    }

    // Keywords
    if (stats.most_common_keywords && Object.keys(stats.most_common_keywords).length > 0) {
        html += `
            <div class="stat-card full-width">
                <h3>Most Common Keywords</h3>
                <ul class="stat-list">
                    ${Object.entries(stats.most_common_keywords).slice(0, 10).map(([word, count]) => `
                        <li>${escapeHtml(word)}: ${count}</li>
                    `).join('')}
                </ul>
            </div>
        `;
    }

    // Newsletter detection
    if (stats.newsletter_count !== undefined) {
        html += `
            <div class="stat-card">
                <h3>Newsletters</h3>
                <p class="stat-value">${stats.newsletter_count}</p>
            </div>
        `;
    }

    if (stats.promotion_count !== undefined) {
        html += `
            <div class="stat-card">
                <h3>Promotions</h3>
                <p class="stat-value">${stats.promotion_count}</p>
            </div>
        `;
    }

    // Thread analysis
    if (stats.average_thread_length !== undefined) {
        html += `
            <div class="stat-card">
                <h3>Avg Thread Length</h3>
                <p class="stat-value">${(stats.average_thread_length || 0).toFixed(1)}</p>
            </div>
        `;
    }

    if (stats.longest_thread) {
        html += `
            <div class="stat-card">
                <h3>Longest Thread</h3>
                <p>${stats.longest_thread.thread_id}</p>
                <p class="stat-value">${stats.longest_thread.message_count} messages</p>
            </div>
        `;
    }

    html += '</div>';
    container.innerHTML = html;
}

async function exportStatisticsPdf() {
    try {
        showSpinner(true);
        const response = await fetch(`${CONFIG.API_BASE_URL}/statistics?export=1`, {
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });

        if (!response.ok) throw new Error('Export failed');

        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `statistics_${new Date().toISOString().split('T')[0]}.html`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        a.remove();

        showToast('Statistics exported successfully', 'success');
    } catch (error) {
        showToast(`Failed to export: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
    }
}

async function exportStatisticsCsv() {
    try {
        showSpinner(true);
        const response = await fetch(`${CONFIG.API_BASE_URL}/statistics?export=1`, {
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });

        if (!response.ok) throw new Error('Export failed');

        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `statistics_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        a.remove();

        showToast('Statistics exported successfully', 'success');
    } catch (error) {
        showToast(`Failed to export: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
    const loadBtn = document.getElementById('load-stats-btn');
    if (loadBtn) {
        loadBtn.addEventListener('click', loadStatistics);
    }

    const exportPdfBtn = document.getElementById('export-stats-pdf-btn');
    if (exportPdfBtn) {
        exportPdfBtn.addEventListener('click', exportStatisticsPdf);
    }

    const exportCsvBtn = document.getElementById('export-stats-csv-btn');
    if (exportCsvBtn) {
        exportCsvBtn.addEventListener('click', exportStatisticsCsv);
    }
});
