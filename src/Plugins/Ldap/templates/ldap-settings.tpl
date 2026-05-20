{**
 * LDAP Provider Settings Template
 *
 * @package Phlix\Plugins\Ldap\templates
 * @since 0.11.0
 *}
<form id="ldap-settings-form" class="auth-provider-settings">
    <h3>LDAP Authentication Provider</h3>
    <p class="description">
        Configure LDAP authentication for OpenLDAP or Active Directory.
        Supports SSL/TLS connections and StartTLS upgrade.
    </p>

    <div class="form-group">
        <label for="ldap-host">Host</label>
        <input
            type="text"
            id="ldap-host"
            name="host"
            placeholder="ldap.example.com"
            required
        />
        <span class="help-text">
            Hostname or IP address of your LDAP server.
        </span>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="ldap-port">Port</label>
            <input
                type="number"
                id="ldap-port"
                name="port"
                value="389"
                min="1"
                max="65535"
            />
            <span class="help-text">389 (plain) or 636 (SSL)</span>
        </div>

        <div class="form-group">
            <label for="ldap-ssl">Encryption</label>
            <select id="ldap-ssl" name="ssl">
                <option value="false">None (StartTLS)</option>
                <option value="true">SSL/TLS</option>
            </select>
            <span class="help-text">Use SSL for direct connection</span>
        </div>
    </div>

    <div class="form-group">
        <label for="ldap-base-dn">Base DN</label>
        <input
            type="text"
            id="ldap-base-dn"
            name="base_dn"
            placeholder="dc=example,dc=com"
            required
        />
        <span class="help-text">
            Base Distinguished Name for all LDAP searches (e.g., dc=example,dc=com).
        </span>
    </div>

    <div class="form-group">
        <label for="ldap-bind-dn">Bind DN</label>
        <input
            type="text"
            id="ldap-bind-dn"
            name="bind_dn"
            placeholder="cn=admin,dc=example,dc=com"
        />
        <span class="help-text">
            Bind DN for initial connection. Leave empty for anonymous bind.
        </span>
    </div>

    <div class="form-group">
        <label for="ldap-bind-pw">Bind Password</label>
        <input
            type="password"
            id="ldap-bind-pw"
            name="bind_pw"
            autocomplete="new-password"
        />
        <span class="help-text">
            Bind password. Leave empty to keep existing. Only needed if Bind DN is set.
        </span>
    </div>

    <div class="form-group">
        <label for="ldap-user-filter">User Filter</label>
        <input
            type="text"
            id="ldap-user-filter"
            name="user_filter"
            value="(uid={{username}})"
        />
        <span class="help-text">
            LDAP filter for finding users. Use <code>{{username}}</code> as placeholder.
            <br/>OpenLDAP: <code>(uid={{username}})</code>
            <br/>Active Directory: <code>(&amp;(objectClass=user)(sAMAccountName={{username}}))</code>
        </span>
    </div>

    <div class="form-group">
        <label for="ldap-admin-group">Admin Group DN</label>
        <input
            type="text"
            id="ldap-admin-group"
            name="admin_group"
            placeholder="cn=admins,ou=groups,dc=example,dc=com"
        />
        <span class="help-text">
            Optional. Members of this LDAP group will get admin privileges in Phlix.
        </span>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn-primary">Save Settings</button>
        <button type="button" class="btn-secondary" id="test-connection">Test Connection</button>
    </div>

    <div id="ldap-status" class="status-message" style="display: none;"></div>
</form>

<script>
(function() {
    var form = document.getElementById('ldap-settings-form');
    var statusDiv = document.getElementById('ldap-status');
    var saveBtn = form.querySelector('button[type="submit"]');
    var testBtn = document.getElementById('test-connection');

    function showStatus(message, isError) {
        statusDiv.textContent = message;
        statusDiv.className = 'status-message ' + (isError ? 'error' : 'success');
        statusDiv.style.display = 'block';
    }

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        saveBtn.disabled = true;
        statusDiv.style.display = 'none';

        var formData = new FormData(form);
        var data = Object.fromEntries(formData.entries());
        data.ssl = data.ssl === 'true';

        if (data.bind_pw === '') {
            delete data.bind_pw;
        }

        try {
            var response = await fetch('/api/v1/admin/auth-providers/ldap/config', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + (window.PhlixApp && window.PhlixApp.getToken ? window.PhlixApp.getToken() : ''),
                },
                body: JSON.stringify(data),
            });

            var result = await response.json();

            if (response.ok) {
                showStatus(result.message || 'Settings saved successfully', false);
                if (data.bind_pw) {
                    form.querySelector('#ldap-bind-pw').value = '';
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

        var formData = new FormData(form);
        var data = Object.fromEntries(formData.entries());
        data.ssl = data.ssl === 'true';

        if (data.bind_pw === '') {
            delete data.bind_pw;
        }

        try {
            var response = await fetch('/api/v1/admin/auth-providers/ldap/test', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + (window.PhlixApp && window.PhlixApp.getToken ? window.PhlixApp.getToken() : ''),
                },
                body: JSON.stringify(data),
            });

            var result = await response.json();

            if (result.success) {
                showStatus('Connection successful!', false);
            } else {
                showStatus('Connection failed: ' + (result.message || result.error), true);
            }
        } catch (err) {
            showStatus('Connection failed: ' + err.message, true);
        } finally {
            testBtn.disabled = false;
        }
    });

    async function loadSettings() {
        try {
            var response = await fetch('/api/v1/admin/auth-providers/ldap/config', {
                headers: {
                    'Authorization': 'Bearer ' + (window.PhlixApp && window.PhlixApp.getToken ? window.PhlixApp.getToken() : ''),
                },
            });

            if (response.ok) {
                var data = await response.json();
                if (data.host) {
                    form.querySelector('#ldap-host').value = data.host;
                }
                if (data.port) {
                    form.querySelector('#ldap-port').value = data.port;
                }
                if (data.ssl !== undefined) {
                    form.querySelector('#ldap-ssl').value = data.ssl ? 'true' : 'false';
                }
                if (data.base_dn) {
                    form.querySelector('#ldap-base-dn').value = data.base_dn;
                }
                if (data.bind_dn) {
                    form.querySelector('#ldap-bind-dn').value = data.bind_dn;
                }
                if (data.user_filter) {
                    form.querySelector('#ldap-user-filter').value = data.user_filter;
                }
                if (data.admin_group) {
                    form.querySelector('#ldap-admin-group').value = data.admin_group;
                }
            }
        } catch (err) {
            console.error('Failed to load LDAP settings:', err);
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
.auth-provider-settings input[type="password"],
.auth-provider-settings input[type="number"],
.auth-provider-settings select {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 1rem;
}

.auth-provider-settings .form-row {
    display: flex;
    gap: 1rem;
}

.auth-provider-settings .form-row .form-group {
    flex: 1;
}

.auth-provider-settings .help-text {
    display: block;
    font-size: 0.875rem;
    color: #666;
    margin-top: 0.25rem;
}

.auth-provider-settings .help-text code {
    background: #f5f5f5;
    padding: 0.125rem 0.25rem;
    border-radius: 2px;
    font-size: 0.8125rem;
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
