{extends file="layouts/app.tpl"}

{block name="content"}
<div class="webauthn-settings">
    <h2>Passkey Settings</h2>
    <p class="lead">Manage your passkeys (WebAuthn/FIDO2 credentials) for passwordless login.</p>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Registered Passkeys</h5>
        </div>
        <div class="card-body">
            <div id="credentials-list">
                <div class="text-center text-muted py-3">
                    <div class="spinner-border spinner-border-sm" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <span class="ms-2">Loading credentials...</span>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="button" class="btn btn-primary" id="register-passkey-btn">
                <i class="bi bi-plus-circle"></i> Register New Passkey
            </button>
        </div>
    </div>

    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i>
        <strong>What is a passkey?</strong>
        <p class="mb-0">A passkey is a FIDO2/WebAuthn credential that allows you to log in securely
        without a password. It uses public-key cryptography to protect your account
        from phishing and credential theft attacks.</p>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Security Notes</h5>
        </div>
        <div class="card-body">
            <ul class="mb-0">
                <li>Passkeys are unique to each device and cannot be reused if lost.</li>
                <li>Keep backup codes or alternative login methods in a safe place.</li>
                <li>Deleting a passkey is permanent and cannot be undone.</li>
                <li>Platform authenticators (like Touch ID or Windows Hello) require the specific device.</li>
            </ul>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const credentialsList = document.getElementById('credentials-list');
    const registerBtn = document.getElementById('register-passkey-btn');

    async function loadCredentials() {
        try {
            const response = await fetch('/api/v1/me/webauthn/credentials', {
                headers: {
                    'Authorization': 'Bearer ' + (window.PhlixApp ? window.PhlixApp.getToken() : '')
                }
            });

            if (!response.ok) {
                throw new Error('Failed to load credentials');
            }

            const data = await response.json();
            renderCredentials(data.credentials || []);
        } catch (error) {
            credentialsList.innerHTML = '<div class="text-danger">Failed to load credentials: ' + error.message + '</div>';
        }
    }

    function renderCredentials(credentials) {
        if (credentials.length === 0) {
            credentialsList.innerHTML = '<p class="text-muted mb-0">No passkeys registered yet.</p>';
            return;
        }

        let html = '<ul class="list-group list-group-flush">';
        credentials.forEach(function(cred) {
            const created = new Date(cred.registered_at * 1000).toLocaleDateString();
            const deviceIcon = cred.device_type === 'platform' ? 'bi-laptop' : 'bi-phone';
            html += '<li class="list-group-item d-flex justify-content-between align-items-center">';
            html += '<div><i class="bi ' + deviceIcon + ' me-2"></i>';
            html += '<span class="credential-id">' + cred.credential_id.substring(0, 20) + '...</span>';
            html += '<small class="text-muted d-block">Registered: ' + created + '</small>';
            html += '</div>';
            html += '<button type="button" class="btn btn-sm btn-outline-danger delete-credential-btn" ';
            html += 'data-credential-id="' + cred.credential_id + '">';
            html += '<i class="bi bi-trash"></i> Delete</button>';
            html += '</li>';
        });
        html += '</ul>';
        credentialsList.innerHTML = html;

        document.querySelectorAll('.delete-credential-btn').forEach(function(btn) {
            btn.addEventListener('click', handleDeleteCredential);
        });
    }

    async function handleDeleteCredential(event) {
        const btn = event.currentTarget;
        const credentialId = btn.getAttribute('data-credential-id');

        if (!confirm('Are you sure you want to delete this passkey? This action cannot be undone.')) {
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        try {
            const response = await fetch('/api/v1/me/webauthn/credentials/' + encodeURIComponent(credentialId), {
                method: 'DELETE',
                headers: {
                    'Authorization': 'Bearer ' + (window.PhlixApp ? window.PhlixApp.getToken() : '')
                }
            });

            if (!response.ok) {
                throw new Error('Failed to delete credential');
            }

            loadCredentials();
        } catch (error) {
            alert('Error deleting credential: ' + error.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-trash"></i> Delete';
        }
    }

    async function handleRegisterPasskey() {
        registerBtn.disabled = true;
        registerBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Starting...';

        try {
            const response = await fetch('/api/v1/auth/webauthn/register/options', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + (window.PhlixApp ? window.PhlixApp.getToken() : ''),
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({})
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to start registration');
            }

            const options = await response.json();
            options.challenge = Uint8Array.from(atob(options.challenge), c => c.charCodeAt(0));
            options.user.id = Uint8Array.from(atob(options.user.id), c => c.charCodeAt(0));

            if (options.excludeCredentials) {
                options.excludeCredentials = options.excludeCredentials.map(function(cred) {
                    cred.id = Uint8Array.from(atob(cred.id), c => c.charCodeAt(0));
                    return cred;
                });
            }

            const credential = await navigator.credentials.create({
                publicKey: options
            });

            const verifyResponse = await fetch('/api/v1/auth/webauthn/register/verify', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + (window.PhlixApp ? window.PhlixApp.getToken() : ''),
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    credential: {
                        attestationObject: btoa(String.fromCharCode.apply(null, new Uint8Array(credential.attestationObject))),
                        clientDataJSON: btoa(String.fromCharCode.apply(null, new Uint8Array(credential.response.clientDataJSON))),
                        transports: credential.response.getTransports ? credential.response.getTransports() : []
                    },
                    challenge: options.challenge
                })
            });

            if (!verifyResponse.ok) {
                const error = await verifyResponse.json();
                throw new Error(error.error || 'Failed to register credential');
            }

            loadCredentials();
        } catch (error) {
            alert('Error registering passkey: ' + error.message);
        } finally {
            registerBtn.disabled = false;
            registerBtn.innerHTML = '<i class="bi bi-plus-circle"></i> Register New Passkey';
        }
    }

    registerBtn.addEventListener('click', handleRegisterPasskey);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadCredentials);
    } else {
        loadCredentials();
    }
})();
</script>
{/block}
