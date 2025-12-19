/* ================================================================
   THEME & UI FUNCTIONALITY
   ================================================================ */

// Dark Mode Management
class ThemeManager {
  constructor() {
    this.currentTheme = this.loadTheme();
    this.init();
  }

  init() {
    this.applyTheme(this.currentTheme);
    this.createThemeToggle();
  }

  loadTheme() {
    const saved = localStorage.getItem('theme');
    if (saved) return saved;
    
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    return prefersDark ? 'dark' : 'light';
  }

  applyTheme(theme) {
    this.currentTheme = theme;
    document.documentElement.setAttribute('data-theme', theme);
    document.body.classList.toggle('dark-mode', theme === 'dark');
    localStorage.setItem('theme', theme);
  }

  toggle() {
    const newTheme = this.currentTheme === 'light' ? 'dark' : 'light';
    this.applyTheme(newTheme);
  }

  createThemeToggle() {
    if (document.querySelector('.theme-toggle')) return;
    
    const toggle = document.createElement('button');
    toggle.className = 'theme-toggle';
    toggle.setAttribute('aria-label', 'Toggle theme');
    toggle.innerHTML = this.currentTheme === 'light' ? '🌙' : '☀️';
    toggle.onclick = () => this.toggleWithAnimation();
    document.body.appendChild(toggle);
  }

  toggleWithAnimation() {
    const toggle = document.querySelector('.theme-toggle');
    toggle.classList.add('theme-toggle-spinner');
    setTimeout(() => {
      this.toggle();
      this.updateToggleIcon();
      toggle.classList.remove('theme-toggle-spinner');
    }, 250);
  }

  updateToggleIcon() {
    const toggle = document.querySelector('.theme-toggle');
    toggle.innerHTML = this.currentTheme === 'light' ? '🌙' : '☀️';
  }
}

// Navigation Management
class NavigationManager {
  constructor() {
    this.sections = {};
    this.currentSection = null;
    this.init();
  }

  init() {
    this.collectSections();
    this.setupNavigation();
    this.setupMobileMenu();
  }

  collectSections() {
    const sections = document.querySelectorAll('.section');
    sections.forEach(section => {
      const id = section.id;
      this.sections[id] = section;
    });
    console.log('Collected sections:', Object.keys(this.sections));
  }

  setupNavigation() {
    // Only create sidebar if it doesn't already exist
    this.createSidebar();
    
    // Setup nav links
    const navLinks = document.querySelectorAll('.nav-link');
    console.log('Setting up nav links:', navLinks.length);
    navLinks.forEach(link => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const sectionId = link.getAttribute('data-section');
        this.showSection(sectionId);
        this.updateActiveNav(link);
        this.closeMobileMenu();
      });
    });
  }

  createSidebar() {
    const dashboard = document.querySelector('.dashboard-section');
    if (!dashboard) return;
    
    // Check if sidebar already exists
    if (document.querySelector('.sidebar')) {
      console.log('Sidebar already exists, skipping creation');
      return;
    }

    const sidebar = document.createElement('aside');
    sidebar.className = 'sidebar';
    
    const navMenu = document.createElement('ul');
    navMenu.className = 'nav-menu';
    
    const sections = [
      { id: 'inbox-section', icon: '📧', label: 'Inbox' },
      { id: 'attachments-section', icon: '📎', label: 'Attachments' },
      { id: 'senders-section', icon: '👥', label: 'Senders' },
      { id: 'unsubscribe-section', icon: '🔔', label: 'Unsubscribe' },
      { id: 'statistics-section', icon: '📊', label: 'Statistics' },
      { id: 'deleted-section', icon: '🗑️', label: 'Deleted' },
      { id: 'export-section', icon: '⬇️', label: 'Export' }
    ];
    
    sections.forEach(section => {
      const item = document.createElement('li');
      item.className = 'nav-item';
      
      const link = document.createElement('a');
      link.className = 'nav-link';
      link.href = '#';
      link.setAttribute('data-section', section.id);
      link.innerHTML = `<span>${section.icon}</span> ${section.label}`;
      
      link.addEventListener('click', (e) => {
        e.preventDefault();
        this.showSection(section.id);
        this.updateActiveNav(link);
        this.closeMobileMenu();
      });
      
      item.appendChild(link);
      navMenu.appendChild(item);
    });
    
    sidebar.appendChild(navMenu);
    
    const mainContent = dashboard.querySelector('.main-content');
    if (mainContent) {
      // Insert sidebar as FIRST child of main-content
      mainContent.insertBefore(sidebar, mainContent.firstChild);
      console.log('Sidebar created and inserted inside main-content');
    }
  }

  setupMobileMenu() {
    const navbar = document.querySelector('.navbar');
    if (!navbar) return;

    const menuToggle = document.createElement('button');
    menuToggle.className = 'menu-toggle';
    menuToggle.innerHTML = '☰';
    menuToggle.setAttribute('aria-label', 'Toggle menu');
    menuToggle.addEventListener('click', () => this.toggleMobileMenu());
    
    navbar.insertBefore(menuToggle, navbar.firstChild);
  }

  toggleMobileMenu() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
      sidebar.classList.toggle('open');
    }
  }

  closeMobileMenu() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
      sidebar.classList.remove('open');
    }
  }

  showSection(sectionId) {
    // Hide all sections
    document.querySelectorAll('.section').forEach(section => {
      section.classList.remove('active');
      section.classList.add('hidden');
    });
    
    // Show selected section
    const section = document.getElementById(sectionId);
    if (section) {
      section.classList.remove('hidden');
      section.classList.add('active');
      this.currentSection = sectionId;
      console.log('Showing section:', sectionId);
    } else {
      console.warn('Section not found:', sectionId);
    }
  }

  updateActiveNav(activeLink) {
    document.querySelectorAll('.nav-link').forEach(link => {
      link.classList.remove('active');
    });
    activeLink.classList.add('active');
  }
}

// Loading Manager
class LoadingManager {
  static show() {
    const spinner = document.getElementById('loading-spinner');
    if (spinner) {
      spinner.classList.remove('hidden');
    }
  }

  static hide() {
    const spinner = document.getElementById('loading-spinner');
    if (spinner) {
      spinner.classList.add('hidden');
    }
  }
}

// Toast Notifications
class Toast {
  static show(message, type = 'info', duration = 3000) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    const iconMap = {
      success: '✓',
      error: '✕',
      warning: '⚠',
      info: 'ℹ'
    };
    
    toast.innerHTML = `
      <span class="toast-icon">${iconMap[type]}</span>
      <span class="toast-message">${message}</span>
      <button class="toast-close" aria-label="Close">×</button>
    `;
    
    container.appendChild(toast);
    
    toast.querySelector('.toast-close').addEventListener('click', () => {
      toast.remove();
    });
    
    setTimeout(() => {
      toast.style.animation = 'slideOutRight 0.3s ease forwards';
      setTimeout(() => toast.remove(), 300);
    }, duration);
  }

  static success(message, duration) {
    this.show(message, 'success', duration);
  }

  static error(message, duration) {
    this.show(message, 'error', duration);
  }

  static warning(message, duration) {
    this.show(message, 'warning', duration);
  }

  static info(message, duration) {
    this.show(message, 'info', duration);
  }
}

// Keyboard shortcuts
class KeyboardShortcuts {
  static init() {
    document.addEventListener('keydown', (e) => {
      if (e.ctrlKey && e.key === 'k') {
        e.preventDefault();
        Toast.info('Search coming soon! 🚀');
      }
      if (e.ctrlKey && e.key === 't') {
        e.preventDefault();
        const theme = new ThemeManager();
        theme.toggle();
      }
    });
  }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
  console.log('Initializing Theme and Navigation...');
  
  // Initialize theme
  const themeManager = new ThemeManager();
  
  // Initialize navigation - only once!
  const navManager = new NavigationManager();
  
  // Initialize keyboard shortcuts
  KeyboardShortcuts.init();
  
  // Show first section by default after a small delay to ensure DOM is ready
  setTimeout(() => {
    console.log('Showing inbox section...');
    navManager.showSection('inbox-section');
    const firstNavLink = document.querySelector('.nav-link[data-section="inbox-section"]');
    if (firstNavLink) {
      firstNavLink.classList.add('active');
      console.log('Inbox section activated');
    }
  }, 200);
});

// Add slideOutRight animation
const style = document.createElement('style');
style.textContent = `
  @keyframes slideOutRight {
    from {
      opacity: 1;
      transform: translateX(0);
    }
    to {
      opacity: 0;
      transform: translateX(100%);
    }
  }
`;
document.head.appendChild(style);