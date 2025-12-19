// Export Functions
async function exportAllEmailsPdf() {
    if (!confirm('This will export ALL your emails. This may take a while. Continue?')) {
        return;
    }

    try {
        showSpinner(true);
        showProgress(true);

        const response = await API.exportAllEmailsPdf();

        if (!response.ok) throw new Error('Export failed');

        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `gmail_export_${new Date().toISOString().split('T')[0]}.html`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        a.remove();

        showToast('All emails exported as HTML successfully', 'success');
    } catch (error) {
        showToast(`Failed to export: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
        showProgress(false);
    }
}

async function exportAllEmailsCsv() {
    if (!confirm('This will export ALL your emails. This may take a while. Continue?')) {
        return;
    }

    try {
        showSpinner(true);
        showProgress(true);

        const response = await API.exportAllEmailsCsv();

        if (!response.ok) throw new Error('Export failed');

        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `gmail_export_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        a.remove();

        showToast('All emails exported as CSV successfully', 'success');
    } catch (error) {
        showToast(`Failed to export: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
        showProgress(false);
    }
}

function showProgress(show = true) {
    const progress = document.getElementById('export-progress');
    if (show) {
        progress.classList.remove('hidden');
    } else {
        progress.classList.add('hidden');
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
    const exportPdfBtn = document.getElementById('export-pdf-btn');
    if (exportPdfBtn) {
        exportPdfBtn.addEventListener('click', exportAllEmailsPdf);
    }

    const exportCsvBtn = document.getElementById('export-csv-btn');
    if (exportCsvBtn) {
        exportCsvBtn.addEventListener('click', exportAllEmailsCsv);
    }
});
