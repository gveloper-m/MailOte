// Main Application File
console.log('Gmail Manager Frontend Loaded');

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    console.log('Application initialized');
    console.log('Auth status:', isAuthenticated() ? 'Logged In' : 'Not Logged In');

    // Check if we're on the callback page
    const params = new URLSearchParams(window.location.search);
    const code = params.get('code');

    if (code && window.location.pathname === '/api/gmail/callback') {
        console.log('Processing OAuth callback');
        // Auth.js will handle this
    }

    // Test API connection
    console.log('API Base URL:', CONFIG.API_BASE_URL);
    console.log('Token:', getToken() ? 'Present' : 'Not set');

    // Setup navigation
    setupNavigation();
});

function setupNavigation() {
    // Handle navigation link clicks (sidebar menu)
    const navLinks = document.querySelectorAll('.nav-link');
    console.log('Found nav links:', navLinks.length);
    
    navLinks.forEach(link => {
        const sectionId = link.getAttribute('data-section');
        console.log('Adding click listener to nav link:', sectionId);
        link.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            console.log('Navigation link clicked:', sectionId);
            switchSection(sectionId);
            // Close mobile menu if open
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.classList.remove('open');
            }
        });
    });

    // Handle old-style nav buttons for backwards compatibility
    const navButtons = document.querySelectorAll('.nav-btn[data-section]');
    navButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const section = button.getAttribute('data-section');
            switchSection(section);
        });
    });

    // Handle logout button
    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
        console.log('Adding logout listener');
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            console.log('Logout clicked');
            logout();
        });
    }
}

function switchSection(sectionId) {
    console.log('Switching to section:', sectionId);

    // Hide all sections
    const sections = document.querySelectorAll('.section');
    sections.forEach(section => {
        section.classList.remove('active');
        section.classList.add('hidden');
    });

    // Remove active class from all nav links
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach(link => {
        link.classList.remove('active');
    });

    // Show the selected section
    const targetSection = document.getElementById(sectionId);
    if (targetSection) {
        targetSection.classList.remove('hidden');
        targetSection.classList.add('active');
        console.log('Section shown:', sectionId);
    } else {
        console.warn('Section not found:', sectionId);
    }

    // Add active class to the corresponding nav link
    const activeLink = document.querySelector(`.nav-link[data-section="${sectionId}"]`);
    if (activeLink) {
        activeLink.classList.add('active');
    }

    // Scroll to top of section
    window.scrollTo(0, 0);
}

