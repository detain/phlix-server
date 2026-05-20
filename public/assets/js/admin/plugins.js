/**
 * Phlix admin plugins page — progressive enhancement (Step A.5).
 *
 * Vanilla ES2023. Talks to the JSON API at /api/v1/admin/plugins/*
 * using the same JWT the user authenticated with (read from the
 * "phlix_access_token" cookie or, failing that, sessionStorage).
 *
 * The page degrades gracefully: every action also has a regular form
 * submission target so disabling JavaScript still works.
 */
(function () {
    'use strict';

    function getToken() {
        // Cookie first (set by the login flow on this origin).
        const cookieMatch = document.cookie.match(/(?:^|; )phlix_access_token=([^;]+)/);
        if (cookieMatch) {
            return decodeURIComponent(cookieMatch[1]);
        }
        try {
            return sessionStorage.getItem('phlix_access_token') || '';
        } catch (_e) {
            return '';
        }
    }

    function authHeaders() {
        const token = getToken();
        if (!token) {
            return { 'Content-Type': 'application/json' };
        }
        return {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`,
        };
    }

    async function callApi(method, path, body) {
        const opts = { method, headers: authHeaders() };
        if (body !== undefined) {
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(path, opts);
        if (res.status === 204) {
            return null;
        }
        let payload = null;
        try {
            payload = await res.json();
        } catch (_e) {
            payload = null;
        }
        if (!res.ok) {
            const message =
                (payload && (payload.error || payload.message)) ||
                `Request failed (${res.status})`;
            throw new Error(message);
        }
        return payload;
    }

    function reloadAfter(promise) {
        return promise
            .then(() => {
                window.location.reload();
            })
            .catch((err) => {
                window.alert(err.message || 'Action failed');
            });
    }

    function bindTableActions() {
        const table = document.querySelector('[data-role="plugin-table"]');
        if (!table) return;
        table.addEventListener('click', (event) => {
            const btn = event.target.closest('button[data-action]');
            if (!btn) return;
            event.preventDefault();
            const action = btn.dataset.action;
            const name = btn.dataset.plugin;
            if (!name) return;
            if (action === 'enable') {
                reloadAfter(callApi('POST', `/api/v1/admin/plugins/${encodeURIComponent(name)}/enable`));
            } else if (action === 'disable') {
                reloadAfter(callApi('POST', `/api/v1/admin/plugins/${encodeURIComponent(name)}/disable`));
            } else if (action === 'uninstall') {
                if (!window.confirm(`Uninstall ${name}? This removes the plugin files and database row.`)) {
                    return;
                }
                reloadAfter(callApi('DELETE', `/api/v1/admin/plugins/${encodeURIComponent(name)}`));
            }
        });
    }

    function bindInstallForm() {
        const form = document.getElementById('plugin-install-form');
        if (!form) return;
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const urlInput = form.querySelector('input[name="url"]');
            const url = urlInput ? urlInput.value.trim() : '';
            if (!url) return;
            reloadAfter(callApi('POST', '/api/v1/admin/plugins/install', { url }));
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        bindTableActions();
        bindInstallForm();
    });
})();
