// Senders Functions
let allSenders = [];

async function loadSenders() {
    try {
        showSpinner(true);
        const response = await API.getSenders();

        allSenders = response.senders || [];
        renderSenders(allSenders);

        showToast(`Loaded ${allSenders.length} senders`, 'success');
    } catch (error) {
        showToast(`Failed to load senders: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
    }
}

function renderSenders(senders) {
    const container = document.getElementById('senders-container');
    
    if (senders.length === 0) {
        container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">👥</div><p class="empty-state-text">No senders found</p></div>';
        return;
    }

    container.innerHTML = senders.map(sender => `
        <div class="sender-card">
            <div class="sender-avatar">${sender.email.charAt(0).toUpperCase()}</div>
            <div class="sender-name">${escapeHtml(sender.email.split('@')[0])}</div>
            <div class="sender-email">${escapeHtml(sender.email)}</div>
            <div class="sender-stats">
                <div class="sender-stat">
                    <div class="sender-stat-number">${sender.total_emails}</div>
                    <div class="sender-stat-label">Emails</div>
                </div>
                <div class="sender-stat">
                    <div class="sender-stat-number">${new Date(sender.last_email_date).toLocaleDateString().split('/')[0]}</div>
                    <div class="sender-stat-label">Last</div>
                </div>
            </div>
            <div class="sender-actions">
                <button class="btn sender-action-btn" onclick="viewSenderEmails('${escapeHtml(sender.email)}')">View Emails</button>
                <button class="btn sender-action-btn delete" onclick="deleteSender('${escapeHtml(sender.email)}')">Delete All</button>
            </div>
        </div>
    `).join('');
}

async function viewSenderEmails(senderEmail) {
    try {
        showSpinner(true);
        const response = await API.showSenderEmails([senderEmail]);

        const emailsList = response.emails || [];
        const modal = createModal(
            `Emails from ${escapeHtml(senderEmail)} (${emailsList.length})`,
            `
                <div class="sender-emails-list">
                    ${emailsList.map(email => `
                        <div class="email-item">
                            <div class="email-subject">${escapeHtml(email.subject)}</div>
                            <div class="email-date">${new Date(email.date).toLocaleDateString()}</div>
                        </div>
                    `).join('')}
                </div>
            `
        );

        document.body.appendChild(modal);
    } catch (error) {
        showToast(`Failed to load sender emails: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
    }
}

async function deleteSender(senderEmail) {
    if (!confirm(`Delete all emails from ${senderEmail}?`)) {
        return;
    }

    try {
        showSpinner(true);
        await API.deleteSenderEmails([senderEmail]);
        
        allSenders = allSenders.filter(s => s.email !== senderEmail);
        renderSenders(allSenders);

        showToast(`Deleted all emails from ${senderEmail}`, 'success');
    } catch (error) {
        showToast(`Failed to delete: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
    const loadBtn = document.getElementById('load-senders-btn');
    if (loadBtn) {
        loadBtn.addEventListener('click', loadSenders);
    }
});
