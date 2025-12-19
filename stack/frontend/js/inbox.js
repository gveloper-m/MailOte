// Inbox Functions
let currentPageToken = null;
let currentEmails = [];
let emailDetailsCache = {}; // Cache email details

async function loadEmails() {
    try {
        showSpinner(true);
        const pageSize = document.getElementById('page-size').value;
        
        // Reset pagination when page size changes
        const oldPageSize = document.getElementById('page-size').dataset.lastPageSize;
        if (oldPageSize && oldPageSize !== pageSize) {
            currentPageToken = null;
        }
        document.getElementById('page-size').dataset.lastPageSize = pageSize;
        
        const response = await API.getEmails(pageSize, currentPageToken);

        currentEmails = response.emails || [];
        // Extract the actual page token from the response, not just a boolean
        currentPageToken = response.pagination?.next_page_token || null;

        // Cache emails for viewing
        currentEmails.forEach(email => {
            emailDetailsCache[email.id] = email;
        });

        renderEmails(currentEmails);
        renderPagination(response, 'pagination', () => loadEmails());

        showToast(`Loaded ${currentEmails.length} emails`, 'success');
    } catch (error) {
        showToast(`Failed to load emails: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
    }
}

function renderEmails(emails) {
    const container = document.getElementById('emails-container');
    
    if (emails.length === 0) {
        container.innerHTML = '<p class="no-data">No emails found</p>';
        return;
    }

    container.innerHTML = emails.map((email, index) => `
        <div class="email-item">
            <div class="email-header">
                <strong>${escapeHtml(email.from)}</strong>
                <span class="email-date">${new Date(email.date).toLocaleDateString()}</span>
            </div>
            <div class="email-subject">${escapeHtml(email.subject)}</div>
            <div class="email-snippet">${escapeHtml(email.snippet)}</div>
            <button class="btn btn-small view-email-btn" data-email-id="${email.id}">View</button>
        </div>
    `).join('');
    
    // Add event delegation for View buttons
    container.addEventListener('click', (e) => {
        if (e.target.classList.contains('view-email-btn')) {
            const emailId = e.target.dataset.emailId;
            viewEmailDetails(emailId);
        }
    });
}

async function viewEmailDetails(emailId) {
    try {
        showSpinner(true);
        console.log('viewEmailDetails called with ID:', emailId);
        
        // Always fetch full email details from API (don't use cache, as cache doesn't have body)
        const response = await API.getEmail(emailId);
        const email = response.email;
        console.log('Email fetched:', email.subject);

        const modal = createModal(
            `${escapeHtml(email.subject)}`,
            `
                <div class="email-details">
                    <p><strong>From:</strong> ${escapeHtml(email.from)}</p>
                    <p><strong>To:</strong> ${escapeHtml(email.to)}</p>
                    <p><strong>Date:</strong> ${new Date(email.date).toLocaleString()}</p>
                    ${email.cc ? `<p><strong>CC:</strong> ${escapeHtml(email.cc)}</p>` : ''}
                    ${email.bcc ? `<p><strong>BCC:</strong> ${escapeHtml(email.bcc)}</p>` : ''}
                    <hr>
                    <div class="email-body">${email.body || '[No content]'}</div>
                </div>
            `
        );

        console.log('Modal created, appending to inbox section');
        const inboxSection = document.getElementById('inbox-section');
        inboxSection.appendChild(modal);
        console.log('Modal appended to inbox section');
    } catch (error) {
        console.error('Error in viewEmailDetails:', error);
        showToast(`Failed to load email: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
    }
}

function renderPagination(response, containerId, onNext) {
    const container = document.getElementById(containerId);
    
    const hasNextPage = response.pagination?.next_page_token;
    
    if (!hasNextPage) {
        container.innerHTML = '';
        return;
    }

    container.innerHTML = `<button class="btn btn-primary" onclick="loadEmails()">Load More</button>`;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function createModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <div style="flex: 1;">
                    <h2 style="margin: 0;">${title}</h2>
                </div>
                <button class="btn-close" onclick="this.closest('.modal').remove()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">${content}</div>
        </div>
    `;
    
    // Close modal when clicking outside
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.remove();
        }
    });
    
    return modal;
}

// Event Listeners - Removed to prevent double-firing (onclick already in HTML)
