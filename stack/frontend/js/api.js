// API Helper Functions
class API {
    static async request(endpoint, options = {}) {
        const token = getToken();
        const url = `${CONFIG.API_BASE_URL}${endpoint}`;
        
        console.log('API Request:', url, options);
        
        const headers = {
            'Content-Type': 'application/json',
            ...options.headers,
        };

        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }

        const config = {
            ...options,
            headers,
        };

        // Add timeout to all requests (5 minutes for long operations like unsubscribe)
        const timeoutMs = options.timeout || 300000;
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

        try {
            const response = await fetch(url, {
                ...config,
                signal: controller.signal,
            });
            clearTimeout(timeoutId);
            const data = await response.json();

            console.log('API Response:', response.status, data);

            if (!response.ok) {
                const errorMsg = typeof data.error === 'string' 
                    ? data.error 
                    : (data.error?.message || 'API request failed');
                throw new Error(errorMsg);
            }

            return data;
        } catch (error) {
            clearTimeout(timeoutId);
            if (error.name === 'AbortError') {
                console.error('API Request Timeout:', url);
                throw new Error('Request timeout - the operation took too long. Please try again with fewer emails.');
            }
            console.error('API Error:', error);
            throw error;
        }
    }

    static async get(endpoint) {
        return this.request(endpoint, { method: 'GET' });
    }

    static async post(endpoint, body) {
        return this.request(endpoint, {
            method: 'POST',
            body: JSON.stringify(body),
        });
    }

    // Auth endpoints
    static async getAuthUrl() {
        return this.get('/auth-url');
    }

    static async login(code) {
        return this.post('/login', { code });
    }

    // Email endpoints
    static async getEmails(pageSize = 50, pageToken = null) {
        let endpoint = `/emails?page_size=${pageSize}`;
        if (pageToken) endpoint += `&page_token=${pageToken}`;
        return this.get(endpoint);
    }

    static async getEmail(emailId) {
        return this.get(`/email/${encodeURIComponent(emailId)}`);
    }

    static async getDeletedEmails(pageSize = 50, pageToken = null) {
        let endpoint = `/deleted?page_size=${pageSize}`;
        if (pageToken) endpoint += `&page_token=${pageToken}`;
        return this.get(endpoint);
    }

    // Attachments endpoints
    static async getEmailsWithAttachments(pageSize = 50, pageToken = null) {
        let endpoint = `/attachments?page_size=${pageSize}`;
        if (pageToken) endpoint += `&page_token=${pageToken}`;
        return this.get(endpoint);
    }

    static async downloadAttachment(gmailId, partId) {
        const token = getToken();
        const url = `${CONFIG.API_BASE_URL}/attachment/download/${gmailId}/${partId}`;
        const headers = { 'Authorization': `Bearer ${token}` };
        return fetch(url, { headers });
    }

    // Sender endpoints
    static async getSenders() {
        return this.get('/senders');
    }

    static async showSenderEmails(emails, pageSize = 50) {
        return this.post('/senders/show', { emails, page_size: pageSize });
    }

    static async deleteSenderEmails(emails) {
        return this.post('/senders/delete', { emails });
    }

    // Unsubscribe endpoints
    static async findUnsubscribeEmails() {
        return this.get('/emails/unsubscribe');
    }

    static async unsubscribeFromEmails(ids, deleteEmails = false) {
        return this.post('/unsubscribe/emails', { ids, delete: deleteEmails ? 1 : 0 });
    }

    // Statistics endpoints
    static async getStatistics(refresh = false, exportFormat = null) {
        let endpoint = '/statistics?';
        if (refresh) endpoint += 'refresh=1&';
        if (exportFormat === 'pdf') endpoint += 'export=1';
        if (exportFormat === 'csv') endpoint += 'export=1';
        return this.get(endpoint);
    }

    // Export endpoints
    static async exportAllEmailsPdf() {
        const token = getToken();
        const url = `${CONFIG.API_BASE_URL}/export/pdf`;
        const headers = { 'Authorization': `Bearer ${token}` };
        return fetch(url, { headers });
    }

    static async exportAllEmailsCsv() {
        const token = getToken();
        const url = `${CONFIG.API_BASE_URL}/export/csv`;
        const headers = { 'Authorization': `Bearer ${token}` };
        return fetch(url, { headers });
    }
}
