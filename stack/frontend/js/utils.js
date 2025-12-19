/* ================================================================
   UTILITY FUNCTIONS
   ================================================================ */

// Loading messages for spinner
const LOADING_MESSAGES = [
    'Loading... this might take several minutes',
    '💡 Tip: Organize emails by sender to find important contacts quickly',
    '📎 Tip: Use attachments view to bulk download files from multiple emails',
    '🔍 Tip: Search for unsubscribe links to clean up your inbox',
    '⚡ Tip: Create rules to auto-organize incoming emails',
    '🗂️ Tip: Mark important emails as favorites for quick access',
    '📧 Tip: Use templates for frequent email responses',
    '🔔 Tip: Set up notifications for emails from VIP contacts',
    '🗑️ Tip: Periodically review deleted emails before permanent removal',
    '🔐 Tip: Enable two-factor authentication for account security',
    '📊 Tip: Review email statistics to understand your email patterns',
    '⏰ Tip: Schedule emails to send at optimal times',
];

let spinnerMessageInterval = null;
let currentMessageIndex = 0;

/**
 * Show or hide the loading spinner with rotating messages
 * @param {boolean} show - Whether to show or hide the spinner
 */
function showSpinner(show = true) {
    const spinner = document.getElementById('loading-spinner');
    const overlay = document.getElementById('spinner-overlay');
    console.log('showSpinner called with show=', show, 'spinner=', spinner, 'overlay=', overlay);
    
    if (!spinner || !overlay) {
        console.log('Spinner or overlay not found!');
        return;
    }
    
    if (show) {
        console.log('Showing spinner and overlay');
        spinner.classList.remove('hidden');
        overlay.classList.remove('hidden');
        // Start rotating messages
        startSpinnerMessages();
    } else {
        console.log('Hiding spinner and overlay');
        spinner.classList.add('hidden');
        overlay.classList.add('hidden');
        // Stop rotating messages
        stopSpinnerMessages();
    }
}

/**
 * Start rotating loading messages
 */
function startSpinnerMessages() {
    console.log('startSpinnerMessages called');
    if (spinnerMessageInterval) clearInterval(spinnerMessageInterval);
    currentMessageIndex = 0;
    console.log('Starting with message index 0:', LOADING_MESSAGES[0]);
    updateSpinnerMessage();
    spinnerMessageInterval = setInterval(() => {
        console.log('Interval tick - updating message');
        updateSpinnerMessage();
    }, 3000); // Change message every 3 seconds
    console.log('Interval set:', spinnerMessageInterval);
}

/**
 * Stop rotating loading messages
 */
function stopSpinnerMessages() {
    if (spinnerMessageInterval) {
        clearInterval(spinnerMessageInterval);
        spinnerMessageInterval = null;
    }
}

/**
 * Update the spinner message
 */
function updateSpinnerMessage() {
    const messageEl = document.getElementById('spinner-message');
    if (!messageEl) {
        console.log('Message element not found');
        return;
    }
    
    const newMessage = LOADING_MESSAGES[currentMessageIndex];
    console.log('Current index:', currentMessageIndex, 'Message:', newMessage);
    messageEl.textContent = newMessage;
    currentMessageIndex = (currentMessageIndex + 1) % LOADING_MESSAGES.length;
}

/**
 * Hide the loading spinner
 */
function hideSpinner() {
    showSpinner(false);
}

/**
 * Show a toast notification
 * @param {string} message - The message to display
 * @param {string} type - The type of notification (success, error, warning, info)
 * @param {number} duration - How long to show the notification in milliseconds
 */
function showToast(message, type = 'info', duration = 2000) {
    const container = document.getElementById('toast-container');
    if (!container) {
        console.warn('Toast container not found');
        return;
    }

    // Prevent duplicate toasts with same message
    const existingToasts = Array.from(container.querySelectorAll('.toast'));
    const isDuplicate = existingToasts.some(toast => 
        toast.textContent.includes(message) && toast.className.includes(type)
    );
    
    if (isDuplicate) {
        console.log('Duplicate toast prevented:', message);
        return;
    }

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    const iconMap = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ'
    };
    
    toast.innerHTML = `
        <span class="toast-icon">${iconMap[type] || 'ℹ'}</span>
        <span class="toast-message">${message}</span>
        <button class="toast-close" aria-label="Close">&times;</button>
    `;
    
    container.appendChild(toast);
    
    toast.querySelector('.toast-close').addEventListener('click', () => {
        toast.remove();
    });
    
    // Auto-remove after duration
    const timeoutId = setTimeout(() => {
        if (toast.parentElement) {
            toast.style.animation = 'slideOutRight 0.3s ease forwards';
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 300);
        }
    }, duration);
    
    // Clear timeout if manually closed
    toast.addEventListener('remove', () => {
        clearTimeout(timeoutId);
    });
}

/**
 * Close all open modals
 */
function closeAllModals() {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.remove();
    });
}

/**
 * Close the most recently opened modal
 */
function closeModal() {
    const modals = document.querySelectorAll('.modal');
    if (modals.length > 0) {
        modals[modals.length - 1].remove();
    }
}

/**
 * Escape HTML special characters
 * @param {string} text - Text to escape
 * @returns {string} Escaped HTML
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Format bytes to human readable format
 * @param {number} bytes - Number of bytes
 * @returns {string} Formatted size
 */
function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
}

/**
 * Format date to readable format
 * @param {string|Date} date - Date to format
 * @returns {string} Formatted date
 */
function formatDate(date) {
    if (!date) return '';
    const d = new Date(date);
    return d.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Get the authentication token from localStorage
 * @returns {string|null} The authentication token
 */
function getToken() {
    return localStorage.getItem('gmail_token');
}

/**
 * Set the authentication token in localStorage
 * @param {string} token - The token to set
 */
function setToken(token) {
    localStorage.setItem('gmail_token', token);
}

/**
 * Check if user is authenticated
 * @returns {boolean} Whether user has a valid token
 */
function isAuthenticated() {
    return !!getToken();
}

/**
 * Clear authentication and redirect to login
 */
function logout() {
    localStorage.removeItem('gmail_token');
    localStorage.removeItem('theme');
    window.location.reload();
}

/**
 * Debounce function to prevent excessive function calls
 * @param {function} func - Function to debounce
 * @param {number} wait - Wait time in milliseconds
 * @returns {function} Debounced function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Throttle function to limit function calls
 * @param {function} func - Function to throttle
 * @param {number} limit - Time limit in milliseconds
 * @returns {function} Throttled function
 */
function throttle(func, limit) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

/**
 * Deep clone an object
 * @param {object} obj - Object to clone
 * @returns {object} Cloned object
 */
function deepClone(obj) {
    return JSON.parse(JSON.stringify(obj));
}

/**
 * Check if object is empty
 * @param {object} obj - Object to check
 * @returns {boolean} Whether object is empty
 */
function isEmpty(obj) {
    return Object.keys(obj).length === 0;
}

/**
 * Copy text to clipboard
 * @param {string} text - Text to copy
 */
async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        showToast('Copied to clipboard!', 'success', 2000);
    } catch (err) {
        showToast('Failed to copy to clipboard', 'error');
    }
}

/**
 * Download a file
 * @param {Blob} blob - File blob
 * @param {string} filename - Filename to download as
 */
function downloadFile(blob, filename) {
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename || 'download';
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
    a.remove();
}

/**
 * Parse URL parameters
 * @returns {object} URL parameters
 */
function getUrlParams() {
    const params = new URLSearchParams(window.location.search);
    const result = {};
    params.forEach((value, key) => {
        result[key] = value;
    });
    return result;
}

/**
 * Generate a random ID
 * @returns {string} Random ID
 */
function generateId() {
    return Math.random().toString(36).substr(2, 9);
}

/**
 * Wait for a certain amount of time
 * @param {number} ms - Milliseconds to wait
 * @returns {Promise} Promise that resolves after the specified time
 */
function wait(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Retry a function with exponential backoff
 * @param {function} func - Async function to retry
 * @param {number} maxRetries - Maximum number of retries
 * @param {number} delay - Initial delay in milliseconds
 * @returns {Promise} Result of the function
 */
async function retryWithBackoff(func, maxRetries = 3, delay = 1000) {
    for (let i = 0; i < maxRetries; i++) {
        try {
            return await func();
        } catch (error) {
            if (i === maxRetries - 1) throw error;
            await wait(delay * Math.pow(2, i));
        }
    }
}
