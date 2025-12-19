// Configuration
const CONFIG = {
    // Update this to match your backend URL
    API_BASE_URL: window.location.origin + '/api/gmail',
    TOKEN_KEY: 'auth_token', // Match with utils.js
};

// Check if user is authenticated
function isAuthenticated() {
    return !!getToken();
}
