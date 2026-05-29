{extends file="layouts/main.tpl"}

{block name="title"}Settings - Phlix{/block}

{block name="main"}
<div class="settings-page">
    <header class="settings-header">
        <h1>Settings</h1>
        <p>Manage your Phlix account, playback preferences, and parental controls.</p>
    </header>

    {* Section 1: Account *}
    <section class="settings-section">
        <h2>Account</h2>
        <p>Signed in as <strong>{$user.display_name|default:'User'|escape:'html'}</strong>.</p>
        <p><a href="/login" class="btn btn-secondary">Sign in / Switch profile</a></p>
    </section>

    {* Section 2: Streaming Preferences *}
    <section class="settings-section">
        <h2>Streaming Preferences</h2>
        <form id="settings-form" class="settings-form">
            <div class="form-group">
                <label for="max_streams">Max concurrent streams</label>
                <input type="number" id="max_streams" name="max_streams" min="1" max="10" value="1">
                <small class="form-hint">Number of simultaneous streams allowed (1-10)</small>
            </div>

            <div class="form-group">
                <label for="max_bitrate">Max bitrate</label>
                <select id="max_bitrate" name="max_bitrate">
                    <option value="auto">Auto</option>
                    <option value="10000000">10 Mbps</option>
                    <option value="25000000">25 Mbps</option>
                    <option value="50000000">50 Mbps</option>
                    <option value="100000000">100 Mbps</option>
                </select>
                <small class="form-hint">Restrict streaming bandwidth</small>
            </div>

            {* Section 3: Audio & Subtitles *}
            <h3>Audio &amp; Subtitles</h3>

            <div class="form-group">
                <label for="preferred_audio_language">Preferred audio language</label>
                <select id="preferred_audio_language" name="preferred_audio_language">
                    <option value="original">Original</option>
                    <option value="en">English</option>
                    <option value="es">Spanish</option>
                    <option value="fr">French</option>
                    <option value="de">German</option>
                    <option value="it">Italian</option>
                    <option value="pt">Portuguese</option>
                    <option value="ja">Japanese</option>
                    <option value="ko">Korean</option>
                    <option value="zh">Chinese</option>
                    <option value="hi">Hindi</option>
                    <option value="ar">Arabic</option>
                </select>
            </div>

            <div class="form-group">
                <label for="preferred_subtitle_language">Preferred subtitle language</label>
                <select id="preferred_subtitle_language" name="preferred_subtitle_language">
                    <option value="">None</option>
                    <option value="original">Original</option>
                    <option value="en">English</option>
                    <option value="es">Spanish</option>
                    <option value="fr">French</option>
                    <option value="de">German</option>
                    <option value="it">Italian</option>
                    <option value="pt">Portuguese</option>
                    <option value="ja">Japanese</option>
                    <option value="ko">Korean</option>
                    <option value="zh">Chinese</option>
                    <option value="hi">Hindi</option>
                    <option value="ar">Arabic</option>
                </select>
            </div>

            <div class="form-group">
                <label for="subtitle_mode">Subtitle mode</label>
                <select id="subtitle_mode" name="subtitle_mode">
                    <option value="foreign_only">Foreign only</option>
                    <option value="all">All</option>
                    <option value="none">None</option>
                </select>
                <small class="form-hint">When to display subtitles</small>
            </div>

            {* Section 4: Parental Controls *}
            <h3>Parental Controls</h3>

            <div class="form-group">
                <label for="default_content_rating">Content rating filter</label>
                <select id="default_content_rating" name="default_content_rating">
                    <option value="all">All</option>
                    <option value="G">G</option>
                    <option value="PG">PG</option>
                    <option value="PG-13">PG-13</option>
                    <option value="R">R</option>
                    <option value="NC-17">NC-17</option>
                </select>
                <small class="form-hint">Filter content by rating level</small>
            </div>

            <p class="settings-note">PIN-protect mature content per profile. Manage profiles in the admin dashboard.</p>

            <div class="form-actions">
                <button type="submit" id="save-btn" class="btn btn-primary" disabled>Save</button>
                <div id="settings-message" class="settings-message" hidden></div>
            </div>
        </form>
    </section>

    {* Section 5: Appearance (Theme Switcher) *}
    <section class="settings-section">
        <h2>Appearance</h2>
        <p>Choose your preferred theme.</p>
        <div class="form-group">
            <label for="theme">Theme</label>
            <select id="theme" name="theme">
                <option value="phlix-light">Phlix Light</option>
                <option value="phlix-dark">Phlix Dark</option>
                <option value="phlix-amoled">Phlix AMOLED</option>
                <option value="phlix-contrast">Phlix High Contrast</option>
            </select>
            <small class="form-hint">Changes apply immediately and are saved automatically</small>
        </div>
    </section>

    {* Section 6: Plugins *}
    <section class="settings-section">
        <h2>Plugins</h2>
        <p>Install or configure plugins. <a href="/admin/plugins">Plugin manager &rarr;</a></p>
    </section>
</div>

{* Loading skeleton overlay *}
<div id="loading-overlay" class="loading-overlay" hidden>
    <div class="loading-skeleton">
        <div class="skeleton-line skeleton-wide"></div>
        <div class="skeleton-line skeleton-medium"></div>
        <div class="skeleton-line skeleton-narrow"></div>
    </div>
</div>
{/block}

{literal}
<script>
(function () {
    'use strict';

    var form = document.getElementById('settings-form');
    var saveBtn = document.getElementById('save-btn');
    var messageEl = document.getElementById('settings-message');
    var loadingOverlay = document.getElementById('loading-overlay');

    if (!form) return;

    // Track original values for dirty-state detection
    var originalValues = {};

    // Show inline message (success or error)
    function showMessage(text, isError) {
        messageEl.textContent = text;
        messageEl.className = 'settings-message ' + (isError ? 'message-error' : 'message-success');
        messageEl.hidden = false;
    }

    // Hide message
    function hideMessage() {
        messageEl.hidden = true;
    }

    // Get current form values as object
    function getFormValues() {
        return {
            max_streams: parseInt(form.max_streams.value, 10) || 1,
            max_bitrate: form.max_bitrate.value,
            preferred_audio_language: form.preferred_audio_language.value,
            preferred_subtitle_language: form.preferred_subtitle_language.value,
            subtitle_mode: form.subtitle_mode.value,
            default_content_rating: form.default_content_rating.value
        };
    }

    // Check if form is dirty
    function isDirty() {
        var current = getFormValues();
        for (var key in current) {
            if (current[key] !== originalValues[key]) {
                return true;
            }
        }
        return false;
    }

    // Update save button state
    function updateSaveButton() {
        saveBtn.disabled = !isDirty();
    }

    // Populate form with settings data
    function populateForm(settings) {
        form.max_streams.value = settings.max_streams || 1;
        form.max_bitrate.value = settings.max_bitrate || 'auto';
        form.preferred_audio_language.value = settings.preferred_audio_language || 'original';
        form.preferred_subtitle_language.value = settings.preferred_subtitle_language || '';
        form.subtitle_mode.value = settings.subtitle_mode || 'foreign_only';
        form.default_content_rating.value = settings.default_content_rating || 'all';
        form.theme.value = settings.theme || 'phlix-dark';

        // Store original values
        originalValues = getFormValues();
        saveBtn.disabled = true;
    }

    // Show loading overlay
    function showLoading() {
        loadingOverlay.hidden = false;
    }

    // Hide loading overlay
    function hideLoading() {
        loadingOverlay.hidden = true;
    }

    // Fetch current settings from API
    function loadSettings() {
        showLoading();
        hideMessage();

        fetch('/api/v1/users/me/settings')
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('Failed to load settings: HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function (data) {
                populateForm(data);
            })
            .catch(function (err) {
                showMessage('Could not load settings: ' + err.message, true);
            })
            .finally(function () {
                hideLoading();
            });
    }

    // Save settings to API
    function saveSettings() {
        hideMessage();
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        var payload = getFormValues();

        fetch('/api/v1/users/me/settings', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('Failed to save settings: HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function (data) {
                // Update original values after successful save
                originalValues = getFormValues();
                showMessage('Settings saved.', false);
                saveBtn.disabled = true;
            })
            .catch(function (err) {
                showMessage('Could not save settings: ' + err.message, true);
                saveBtn.disabled = false;
            })
            .finally(function () {
                saveBtn.textContent = 'Save';
            });
    }

    // Event listeners
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        saveSettings();
    });

    // Track dirty state on input changes
    var inputs = form.querySelectorAll('input, select');
    for (var i = 0; i < inputs.length; i++) {
        inputs[i].addEventListener('change', updateSaveButton);
        inputs[i].addEventListener('input', updateSaveButton);
    }

    // Auto-save theme on change (no save button needed)
    form.theme.addEventListener('change', function() {
        fetch('/api/v1/users/me/settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ theme: form.theme.value })
        }).then(function(res) {
            if (res.ok) {
                showMessage('Theme saved.', false);
            }
        }).catch(function(err) {
            showMessage('Could not save theme: ' + err.message, true);
        });
    });

    // Load settings on page load
    loadSettings();
})();
</script>
{/literal}

{block name="styles"}
<style>
.settings-page {
    max-width: 640px;
    margin: 0 auto;
    padding: 2rem 1rem;
}

.settings-header {
    margin-bottom: 2rem;
}

.settings-header h1 {
    font-size: 1.75rem;
    margin-bottom: 0.5rem;
}

.settings-section {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e0e0e0);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.settings-section h2 {
    font-size: 1.25rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border-color, #e0e0e0);
}

.settings-section h3 {
    font-size: 1rem;
    margin: 1.25rem 0 0.75rem;
    color: var(--text-secondary, #666);
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 0.25rem;
}

.form-group input[type="number"],
.form-group select {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--border-color, #e0e0e0);
    border-radius: 4px;
    font-size: 1rem;
    background: var(--input-bg, #fff);
    color: var(--text-color, #333);
}

.form-hint {
    display: block;
    margin-top: 0.25rem;
    font-size: 0.875rem;
    color: var(--text-secondary, #666);
}

.settings-note {
    font-size: 0.875rem;
    color: var(--text-secondary, #666);
    margin-top: 1rem;
}

.form-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-top: 1.5rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color, #e0e0e0);
}

.settings-message {
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
    border-radius: 4px;
}

.message-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.message-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Loading overlay */
.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.loading-skeleton {
    text-align: center;
}

.skeleton-line {
    height: 1rem;
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: skeleton-shimmer 1.5s infinite;
    border-radius: 4px;
    margin-bottom: 0.75rem;
}

.skeleton-wide { width: 200px; }
.skeleton-medium { width: 150px; }
.skeleton-narrow { width: 100px; }

@keyframes skeleton-shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    .loading-overlay {
        background: rgba(30, 30, 30, 0.9);
    }

    .skeleton-line {
        background: linear-gradient(90deg, #2a2a2a 25%, #3a3a3a 50%, #2a2a2a 75%);
        background-size: 200% 100%;
    }
}
</style>
{/block}
