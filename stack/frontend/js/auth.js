// Authentication Functions
async function handleGoogleCallback() {
    const params = new URLSearchParams(window.location.search);
    const token = params.get('token');
    const error = params.get('error');
    const user = params.get('user');

    if (error) {
        showToast(`Login failed: ${error}`, 'error');
        // Clear URL
        window.history.replaceState({}, document.title, '/');
        return;
    }

    if (token) {
        try {
            setToken(token);
            showToast(`Successfully logged in as ${user}!`, 'success');
            // Clear URL and redirect
            window.history.replaceState({}, document.title, '/');
            showDashboard();
        } catch (error) {
            showToast(`Login failed: ${error.message}`, 'error');
        }
    }
}

async function loginWithGoogle() {
    try {
        console.log('Starting Google login...');
        showSpinner(true);
        const response = await API.getAuthUrl();
        console.log('Auth URL response:', response);
        
        if (response.auth_url) {
            console.log('Redirecting to:', response.auth_url);
            window.location.href = response.auth_url;
        } else {
            throw new Error('No auth_url in response');
        }
    } catch (error) {
        console.error('Login error:', error);
        showToast(`Failed to get auth URL: ${error.message}`, 'error');
        showSpinner(false);
    }
}

function logout() {
    if (confirm('Are you sure you want to logout?')) {
        // Clear the token from localStorage
        localStorage.removeItem('token');
        localStorage.removeItem('user');
        // Show the auth section
        showAuthSection();
    }
}

// UI State Management
function showAuthSection() {
    document.getElementById('auth-section').classList.remove('hidden');
    document.getElementById('dashboard-section').classList.add('hidden');
}

function showDashboard() {
    const authSection = document.getElementById('auth-section');
    const dashboardSection = document.getElementById('dashboard-section');
    
    if (authSection) authSection.classList.add('hidden');
    if (dashboardSection) {
        dashboardSection.classList.remove('hidden');
        console.log('Dashboard shown');
        
        // Initialize navigation after dashboard is shown
        setTimeout(() => {
            if (window.NavigationManager) {
                // Navigation already initialized by theme.js
                console.log('Navigation already initialized');
            }
        }, 100);
    }
}

function switchSection(sectionName) {
    // This function moved to app.js - just call it there
    // For now, do nothing as app.js handles it
}

// Utility Functions
function showSpinner(show = true) {
    const spinner = document.getElementById('loading-spinner');
    if (show) {
        spinner.classList.remove('hidden');
    } else {
        spinner.classList.add('hidden');
    }
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    
    container.appendChild(toast);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        toast.remove();
    }, 5000);
}

// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
    console.log('Auth module initialized');
    
    // Check for OAuth callback first
    handleGoogleCallback();

    // Login button - attach click handler
    const loginBtn = document.getElementById('login-btn');
    if (loginBtn) {
        console.log('Login button found, attaching click handler');
        loginBtn.addEventListener('click', (e) => {
            e.preventDefault();
            console.log('Login button clicked');
            loginWithGoogle();
        });
    } else {
        console.warn('Login button not found!');
    }

    // Check authentication status and show appropriate section
    if (isAuthenticated()) {
        console.log('User is authenticated, showing dashboard');
        showDashboard();
    } else {
        console.log('User is not authenticated, showing auth section');
        showAuthSection();
    }
});
