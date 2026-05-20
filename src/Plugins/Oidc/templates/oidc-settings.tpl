{**
 * OIDC Provider Settings Template
 *
 * @package Phlix\Plugins\Oidc\templates
 * @since 0.11.0
 *}
<form id="oidc-settings-form" class="auth-provider-settings">
    <h3>OIDC / OAuth2 Provider</h3>
    <p class="description">
        Configure an OIDC-compliant identity provider such as Keycloak,
        Authelia, Authentik, Google, or GitHub.
    </p>

    <div class="form-group">
        <label for="oidc-provider-url">Provider URL</label>
        <input
            type="url"
            id="oidc-provider-url"
            name="provider_url"
            placeholder="https://your-provider.com"
            required
        />
        <span class="help-text">
            The base URL of your OIDC provider. Must use HTTPS (or localhost for development).
        </span>
    </div>

    <div class="form-group">
        <label for="oidc-client-id">Client ID</label>
        <input
            type="text"
            id="oidc-client-id"
            name="client_id"
            placeholder="your-client-id"
            required
        />
        <span class="help-text">
            The client ID registered with your OIDC provider.
        </span>
    </div>

    <div class="form-group">
        <label for="oidc-client-secret">Client Secret</label>
        <input
            type="password"
            id="oidc-client-secret"
            name="client_secret"
            autocomplete="new-password"
        />
        <span class="help-text">
            The client secret from your OIDC provider. Leave empty to keep existing.
        </span>
    </div>

    <div class="form-group">
        <label for="oidc-scopes">Scopes</label>
        <input
            type="text"
            id="oidc-scopes"
            name="scopes"
            value="openid profile email"
        />
        <span class="help-text">
            Space-separated list of OAuth scopes. Must include "openid".
        </span>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn-primary">Save Settings</button>
        <button type="button" class="btn-secondary" id="test-connection">Test Connection</button>
    </div>

    <div id="oidc-status" class="status-message" style="display: none;"></div>
</form>

<script>
(function() {
    const form = document.getElementById('oidc-settings-form');
    const statusDiv = document.getElementById('oidc-status');
    const saveBtn = form.querySelector('button[type="submit"]');
    const testBtn = document.getElementById('test-connection');

    function showStatus(message, isError) {
        statusDiv.textContent = message;
        statusDiv.className = 'status-message ' + (isError ? 'error' : 'success');
        statusDiv.style.display = 'block';
    }

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        saveBtn.disabled = true;
        statusDiv.style.display = 'none';

        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());

        if (data.client_secret === '') {
            delete data.client_secret;
        }

        try {
            const response = await fetch('/api/v1/admin/auth-providers/oidc/config', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + window.PhlixApp?.getToken(),
                },
                body: JSON.stringify(data),
            });

            const result = await response.json();

            if (response.ok) {
                showStatus(result.message || 'Settings saved successfully', false);
                if (data.client_secret) {
                    form.querySelector('#oidc-client-secret').value = '';
                }
            } else {
                showStatus(result.message || 'Failed to save settings', true);
            }
        } catch (err) {
            showStatus('Network error: ' + err.message, true);
        } finally {
            saveBtn.disabled = false;
        }
    });

    testBtn.addEventListener('click', async function() {
        testBtn.disabled = true;
        statusDiv.style.display = 'none';

        const providerUrl = form.querySelector('#oidc-provider-url').value;
        if (!providerUrl) {
            showStatus('Please enter a provider URL first', true);
            testBtn.disabled = false;
            return;
        }

        try {
            const wellKnownUrl = providerUrl.replace(/\/$/, '') + '/.well-known/openid-configuration';
            const response = await fetch(wellKnownUrl);
            if (response.ok) {
                const config = await response.json();
                showStatus('Connection successful! Provider: ' + (config.issuer || providerUrl), false);
            } else {
                showStatus('Provider not responding. Check the URL.', true);
            }
        } catch (err) {
            showStatus('Connection failed: ' + err.message, true);
        } finally {
            testBtn.disabled = false;
        }
    });

    async function loadSettings() {
        try {
            const response = await fetch('/api/v1/admin/auth-providers/oidc/config', {
                headers: {
                    'Authorization': 'Bearer ' + window.PhlixApp?.getToken(),
                },
            });

            if (response.ok) {
                const data = await response.json();
                if (data.provider_url) {
                    form.querySelector('#oidc-provider-url').value = data.provider_url;
                }
                if (data.client_id) {
                    form.querySelector('#oidc-client-id').value = data.client_id;
                }
                if (data.scopes) {
                    form.querySelector('#oidc-scopes').value = data.scopes;
                }
            }
        } catch (err) {
            console.error('Failed to load OIDC settings:', err);
        }
    }

    loadSettings();
})();
</script>

<style>
.auth-provider-settings {
    max-width: 600px;
}

.auth-provider-settings .form-group {
    margin-bottom: 1rem;
}

.auth-provider-settings label {
    display: block;
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.auth-provider-settings input[type="text"],
.auth-provider-settings input[type="url"],
.auth-provider-settings input[type="password"] {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 1rem;
}

.auth-provider-settings .help-text {
    display: block;
    font-size: 0.875rem;
    color: #666;
    margin-top: 0.25rem;
}

.auth-provider-settings .form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 1.5rem;
}

.auth-provider-settings .btn-primary,
.auth-provider-settings .btn-secondary {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 1rem;
}

.auth-provider-settings .btn-primary {
    background: #0066cc;
    color: white;
}

.auth-provider-settings .btn-primary:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.auth-provider-settings .btn-secondary {
    background: #f0f0f0;
    color: #333;
}

.auth-provider-settings .status-message {
    margin-top: 1rem;
    padding: 0.75rem;
    border-radius: 4px;
}

.auth-provider-settings .status-message.success {
    background: #d4edda;
    color: #155724;
}

.auth-provider-settings .status-message.error {
    background: #f8d7da;
    color: #721c24;
}
</style>
