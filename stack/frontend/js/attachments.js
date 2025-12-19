// Attachments Functions
let currentAttachmentPageToken = null;

async function loadAttachments() {
    try {
        showSpinner(true);
        const pageSize = document.getElementById('attachment-page-size').value;
        
        // Reset pagination when page size changes
        const oldPageSize = document.getElementById('attachment-page-size').dataset.lastPageSize;
        if (oldPageSize && oldPageSize !== pageSize) {
            currentAttachmentPageToken = null;
        }
        document.getElementById('attachment-page-size').dataset.lastPageSize = pageSize;
        
        const response = await API.getEmailsWithAttachments(pageSize, currentAttachmentPageToken);

        currentAttachmentPageToken = response.pagination?.next_page_token || null;

        renderAttachments(response.emails || []);
        
        if (response.pagination?.next_page_token) {
            document.getElementById('attachments-pagination').innerHTML = 
                `<button class="btn btn-primary" onclick="loadAttachments()">Load More</button>`;
        } else {
            document.getElementById('attachments-pagination').innerHTML = '';
        }

        showToast(`Loaded ${response.emails?.length || 0} emails with attachments`, 'success');
    } catch (error) {
        showToast(`Failed to load attachments: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
    }
}

function renderAttachments(emails) {
    const container = document.getElementById('attachments-container');
    
    if (emails.length === 0) {
        container.innerHTML = '<p class="no-data">No emails with attachments found</p>';
        return;
    }

    container.innerHTML = emails.map(email => {
        // Extract email ID from download URL if not available directly
        let emailId = email.id;
        if (!emailId && email.attachments && email.attachments.length > 0) {
            // Parse email ID from download URL (e.g., /attachment/download/19b2b8f790c541a3/1 -> 19b2b8f790c541a3)
            const match = email.attachments[0].download_url?.match(/\/download\/([^/]+)\//);
            emailId = match ? match[1] : 'unknown';
        }
        
        return `
        <div class="attachment-email">
            <div class="email-header">
                <strong>${escapeHtml(email.from)}</strong>
                <span class="email-date">${new Date(email.date).toLocaleDateString()}</span>
            </div>
            <div class="email-subject">${escapeHtml(email.subject)}</div>
            <button class="btn btn-small view-attachment-btn" data-email-id="${emailId}">View Full Email</button>
            <div class="attachments-list">
                <h4>Attachments (${email.attachment_count}):</h4>
                <ul>
                    ${email.attachments.map(att => `
                        <li>
                            <span>${escapeHtml(att.filename)} (${att.size_formatted})</span>
                            <button class="btn btn-small download-btn" data-download-url="${att.download_url}" data-email-id="${emailId}">
                                Download
                            </button>
                        </li>
                    `).join('')}
                </ul>
            </div>
        </div>
    `}).join('');
    
    // Event delegation for View Full Email buttons
    container.addEventListener('click', (e) => {
        if (e.target.classList.contains('view-attachment-btn')) {
            const emailId = e.target.dataset.emailId;
            viewEmailDetails(emailId);
        }
        if (e.target.classList.contains('download-btn')) {
            const downloadUrl = e.target.dataset.downloadUrl;
            const emailId = e.target.dataset.emailId;
            downloadAttachment(emailId, downloadUrl);
        }
    });
}

async function downloadAttachment(gmailId, downloadUrl) {
    try {
        const response = await fetch(downloadUrl, {
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });

        if (!response.ok) {
            throw new Error('Download failed');
        }

        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = downloadUrl.split('/').pop();
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        a.remove();

        showToast('Attachment downloaded successfully', 'success');
    } catch (error) {
        showToast(`Failed to download: ${error.message}`, 'error');
    }
}

// Event Listeners - Removed to prevent double-firing (onclick already in HTML)
