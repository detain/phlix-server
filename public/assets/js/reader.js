/**
 * Books reader JavaScript
 *
 * Basic reader functionality including:
 * - Page navigation
 * - Font size controls
 * - Theme switching (light/sepia/dark)
 *
 * @since 0.17.0
 */

(function() {
    'use strict';

    // Reader state
    const state = {
        currentPage: 1,
        totalPages: 1,
        fontSize: 16,
        theme: 'light'
    };

    // DOM elements
    const readerContent = document.querySelector('.reader-content');
    const pageIndicator = document.querySelector('.page-indicator');
    const prevBtn = document.querySelector('.reader-pagination .btn:first-child');
    const nextBtn = document.querySelector('.reader-pagination .btn:last-child');
    const themeButtons = document.querySelectorAll('.btn-theme');
    const fontSizeButtons = document.querySelectorAll('.btn-font-size');

    // Initialize
    function init() {
        // Get initial page from URL
        const urlParams = new URLSearchParams(window.location.search);
        const pageParam = urlParams.get('page');
        if (pageParam) {
            state.currentPage = parseInt(pageParam, 10) || 1;
        }

        // Load saved preferences
        loadPreferences();

        // Set up event listeners
        setupEventListeners();

        // Apply initial state
        updateUI();
    }

    // Load preferences from localStorage
    function loadPreferences() {
        const savedFontSize = localStorage.getItem('reader_font_size');
        const savedTheme = localStorage.getItem('reader_theme');

        if (savedFontSize) {
            state.fontSize = parseInt(savedFontSize, 10);
        }

        if (savedTheme) {
            state.theme = savedTheme;
        }
    }

    // Save preferences to localStorage
    function savePreferences() {
        localStorage.setItem('reader_font_size', state.fontSize);
        localStorage.setItem('reader_theme', state.theme);
    }

    // Set up event listeners
    function setupEventListeners() {
        // Theme buttons
        themeButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                const theme = btn.getAttribute('data-theme');
                if (theme) {
                    setTheme(theme);
                }
            });
        });

        // Font size buttons
        fontSizeButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                const action = btn.getAttribute('data-action');
                if (action === 'increase') {
                    setFontSize(state.fontSize + 2);
                } else if (action === 'decrease') {
                    setFontSize(state.fontSize - 2);
                }
            });
        });

        // Navigation buttons
        if (prevBtn) {
            prevBtn.addEventListener('click', function() {
                goToPage(state.currentPage - 1);
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function() {
                goToPage(state.currentPage + 1);
            });
        }

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowLeft' && state.currentPage > 1) {
                goToPage(state.currentPage - 1);
            } else if (e.key === 'ArrowRight' && state.currentPage < state.totalPages) {
                goToPage(state.currentPage + 1);
            }
        });
    }

    // Set theme
    function setTheme(theme) {
        state.theme = theme;

        if (readerContent) {
            readerContent.classList.remove('reader-theme-light', 'reader-theme-sepia', 'reader-theme-dark');
            readerContent.classList.add('reader-theme-' + theme);
        }

        // Update active state on buttons
        themeButtons.forEach(function(btn) {
            if (btn.getAttribute('data-theme') === theme) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        savePreferences();
    }

    // Set font size
    function setFontSize(size) {
        // Clamp between 12 and 32
        state.fontSize = Math.max(12, Math.min(32, size));

        if (readerContent) {
            const content = readerContent.querySelector('.reader-page-content');
            if (content) {
                content.style.fontSize = state.fontSize + 'px';
            }
        }

        savePreferences();
    }

    // Go to page
    function goToPage(page) {
        if (page < 1 || page > state.totalPages) {
            return;
        }

        state.currentPage = page;

        // Update URL without reload
        const url = new URL(window.location);
        url.searchParams.set('page', page);
        window.history.pushState({}, '', url);

        updateUI();

        // Scroll to top
        if (readerContent) {
            readerContent.scrollTop = 0;
        }
    }

    // Update UI based on state
    function updateUI() {
        // Update page indicator
        if (pageIndicator) {
            pageIndicator.textContent = 'Page ' + state.currentPage;
        }

        // Update navigation buttons
        if (prevBtn) {
            prevBtn.disabled = state.currentPage <= 1;
        }
        if (nextBtn) {
            nextBtn.disabled = state.currentPage >= state.totalPages;
        }

        // Apply font size
        setFontSize(state.fontSize);

        // Apply theme
        setTheme(state.theme);
    }

    // Wait for DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
