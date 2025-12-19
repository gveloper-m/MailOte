// Unsubscribe Functions
let unsubscribeEmails = [];
let selectedUnsubscribeEmails = [];

async function findUnsubscribeEmails() {
    try {
        showSpinner(true);
        const response = await API.findUnsubscribeEmails();

        unsubscribeEmails = response.emails_with_unsubscribe || response.emails || [];
        selectedUnsubscribeEmails = [];

        renderUnsubscribeEmails(unsubscribeEmails);
        updateUnsubscribeButton();

        showToast(`Found ${unsubscribeEmails.length} emails with unsubscribe links`, 'success');
    } catch (error) {
        showToast(`Failed to find unsubscribe emails: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
    }
}

function renderUnsubscribeEmails(emails) {
    const container = document.getElementById('unsubscribe-list');
    
    if (emails.length === 0) {
        container.innerHTML = '<p class="no-data">No emails with unsubscribe links found</p>';
        return;
    }

    container.innerHTML = emails.map((email, index) => `
        <div class="unsubscribe-item">
            <div class="checkbox-wrapper">
                <input type="checkbox" data-index="${index}" class="unsubscribe-checkbox" 
                       onchange="toggleUnsubscribeEmail(${index})">
            </div>
            <div class="email-info">
                <strong>${escapeHtml(email.from)}</strong>
                <div class="email-subject">${escapeHtml(email.subject)}</div>
                <div class="unsubscribe-link-container">
                    ${email.unsubscribe_link ? `
                        <a href="${email.unsubscribe_link}" target="_blank" rel="noopener noreferrer" class="btn btn-small btn-info">
                            Unsubscribe Link →
                        </a>
                    ` : `
                        <span class="no-link">No unsubscribe link available</span>
                    `}
                </div>
            </div>
        </div>
    `).join('');
}

function toggleUnsubscribeEmail(index) {
    const checkbox = document.querySelector(`[data-index="${index}"]`);
    if (checkbox.checked) {
        selectedUnsubscribeEmails.push(unsubscribeEmails[index].id);
    } else {
        selectedUnsubscribeEmails = selectedUnsubscribeEmails.filter(
            id => id !== unsubscribeEmails[index].id
        );
    }
    updateUnsubscribeButton();
}

function updateUnsubscribeButton() {
    const btn = document.getElementById('unsubscribe-selected-btn');
    if (selectedUnsubscribeEmails.length > 0) {
        btn.classList.remove('hidden');
    } else {
        btn.classList.add('hidden');
    }
}

function toggleSelectAllUnsubscribe(checked) {
    selectedUnsubscribeEmails = [];
    const checkboxes = document.querySelectorAll('.unsubscribe-checkbox');
    checkboxes.forEach((checkbox, index) => {
        checkbox.checked = checked;
        if (checked) {
            selectedUnsubscribeEmails.push(unsubscribeEmails[index].id);
        }
    });
    updateUnsubscribeButton();
}

async function unsubscribeSelected() {
    if (selectedUnsubscribeEmails.length === 0) {
        showToast('Please select at least one email', 'warning');
        return;
    }

    if (!confirm(`Unsubscribe from ${selectedUnsubscribeEmails.length} mailing lists?`)) {
        return;
    }

    // If more than 5 emails, warn user it may take a while
    if (selectedUnsubscribeEmails.length > 5) {
        showToast(`Processing ${selectedUnsubscribeEmails.length} emails - this may take a few minutes...`, 'info');
    }

    try {
        showSpinner(true);
        const deleteAfter = document.getElementById('delete-after-unsubscribe').checked;
        
        await API.unsubscribeFromEmails(selectedUnsubscribeEmails, deleteAfter);

        selectedUnsubscribeEmails = [];
        updateUnsubscribeButton();
        await findUnsubscribeEmails();

        showToast('Successfully unsubscribed', 'success');
    } catch (error) {
        showToast(`Failed to unsubscribe: ${error.message}`, 'error');
    } finally {
        showSpinner(false);
    }
}

// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
    const findBtn = document.getElementById('find-unsubscribe-btn');
    if (findBtn) {
        findBtn.addEventListener('click', findUnsubscribeEmails);
    }

    const unsubscribeBtn = document.getElementById('unsubscribe-selected-btn');
    if (unsubscribeBtn) {
        unsubscribeBtn.addEventListener('click', unsubscribeSelected);
    }

    const selectAllCheckbox = document.getElementById('select-all-unsubscribe');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', (e) => {
            toggleSelectAllUnsubscribe(e.target.checked);
        });
    }
});
