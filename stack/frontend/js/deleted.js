// Deleted Emails Functions
let currentDeletedPageToken = null;

async function loadDeletedEmails() {
    try {
        showSpinner(true);
        const pageSize = document.getElementById('deleted-page-size').value;
        
        // Reset pagination when page size changes
        const oldPageSize = document.getElementById('deleted-page-size').dataset.lastPageSize;
        if (oldPageSize && oldPageSize !== pageSize) {
            currentDeletedPageToken = null;
        }
        document.getElementById('deleted-page-size').dataset.lastPageSize = pageSize;
        
        const response = await API.getDeletedEmails(pageSize, currentDeletedPageToken);

        currentDeletedPageToken = response.pagination?.next_page_token || null;

        renderDeletedEmails(response.deleted_emails || response.emails || []);
        
        if (response.pagination?.next_page_token) {
            document.getElementById('deleted-pagination').innerHTML = 
                `<button class="btn btn-primary" onclick="loadDeletedEmails()">Load More</button>`;
        } else {
            document.getElementById('deleted-pagination').innerHTML = '';
        }

        showToast(`Loaded ${(response.deleted_emails || response.emails || []).length} deleted emails`, 'success');
    } catch (error) {
        showToast(`Failed to load deleted emails: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
    }
}

function renderDeletedEmails(emails) {
    const container = document.getElementById('deleted-container');
    
    if (emails.length === 0) {
        container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">📭</div><p class="empty-state-text">No deleted emails found</p></div>';
        return;
    }

    container.innerHTML = emails.map(email => `
        <div class="deleted-card">
            <div class="deleted-header">
                <div class="deleted-from">
                    <div class="deleted-from-name">${escapeHtml(email.from.split('<')[0].trim())}</div>
                    <div class="deleted-from-address">${escapeHtml(email.from)}</div>
                </div>
                <div class="deleted-date">${new Date(email.date).toLocaleDateString()}</div>
            </div>
            <div class="deleted-subject">${escapeHtml(email.subject)}</div>
            <div class="deleted-preview">${escapeHtml(email.snippet)}</div>
            <div class="deleted-preview">${escapeHtml(email.body)}</div>

        </div>
    `).join('');
}

function viewDeletedEmailDetails(emailId) {
    const email = document.querySelector(`[data-email-id="${emailId}"]`);
    if (email) {
        showEmailModal({
            subject: email.subject,
            from: email.from,
            to: email.to,
            date: email.date,
            body: email.body || email.snippet
        });
    }
}

function restoreEmail(emailId) {
    showToast(`Restoring email ${emailId}...`, 'info');
    // Call API to restore
}

function permanentlyDeleteEmail(emailId) {
    if (confirm('Are you sure you want to permanently delete this email?')) {
        showToast(`Permanently deleting email ${emailId}...`, 'warning');
        // Call API to delete
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
    const loadBtn = document.getElementById('load-deleted-btn');
    if (loadBtn) {
        loadBtn.addEventListener('click', loadDeletedEmails);
    }
});
