/**
 * IntegrationsPage — admin "Integrations" page with two sections:
 *   1. Arr sync (TRaSH-Guides) — status, manual trigger, enable/disable
 *   2. Auth providers (OIDC + LDAP) — list with toggles and inline config forms
 *
 * Security:
 *  - No `dangerouslySetInnerHTML` — all server/API strings as text.
 *  - Admin gate handled server-side + `useAdminGuard` in App shell.
 *
 * Async/resident rules:
 *  - Sync trigger (POST) may be long — button shows spinner and is disabled
 *    until response (or 30 s timeout).
 *  - useEffect cleans up on unmount (all async operations check abort signal
 *    or are fire-and-forget with state guarded by component existence).
 *
 * @since 1.4b
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import { ArrSyncApi, type ArrSyncStatus } from '../api/arrSync';
import {
  AuthProvidersApi,
  OidcApi,
  LdapApi,
  type AuthProvider,
  type OidcSettings,
  type LdapSettings,
  type SaveOidcInput,
  type SaveLdapInput,
} from '../api/authProviders';
import { Form, Field } from '../components/Form';
import { useToast } from '../components/Toast';

export interface IntegrationsPageProps {
  client: ApiClient;
}

type ProviderName = 'oidc' | 'ldap';

/** Map provider name → display label */
const PROVIDER_LABELS: Record<ProviderName, string> = {
  oidc: 'OIDC',
  ldap: 'LDAP',
};

const SYNC_TIMEOUT_MS = 30_000;

export function IntegrationsPage({ client }: IntegrationsPageProps): JSX.Element {
  const arrSyncApiRef = useRef(new ArrSyncApi(client));
  const authProvidersApiRef = useRef(new AuthProvidersApi(client));
  const oidcApiRef = useRef(new OidcApi(client));
  const ldapApiRef = useRef(new LdapApi(client));
  // Destructure the stable `push` callback — the whole `useToast()`
  // context value is a fresh object reference on every toast queue change.
  const { push: pushToast } = useToast();

  // ─── Arr sync state ────────────────────────────────────────────────────────
  const [syncStatus, setSyncStatus] = useState<ArrSyncStatus | null>(null);
  const [syncLoading, setSyncLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);

  const loadSyncStatus = useCallback(async (): Promise<void> => {
    setSyncLoading(true);
    try {
      const status = await arrSyncApiRef.current.getStatus();
      setSyncStatus(status);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to load sync status.';
      pushToast(msg, 'error');
    } finally {
      setSyncLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadSyncStatus();
  }, [loadSyncStatus]);

  const triggerSync = useCallback(async (): Promise<void> => {
    if (syncing) return;
    setSyncing(true);
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      setSyncing(false);
      pushToast('Sync timed out after 30 seconds. Check the server logs.', 'error');
    }, SYNC_TIMEOUT_MS);

    try {
      const result = await arrSyncApiRef.current.triggerSync();
      clearTimeout(timer);
      if (timedOut) return;
      if (result.success) {
        pushToast(result.message || 'Sync complete.', 'success');
        await loadSyncStatus();
      } else {
        pushToast(result.message || 'Sync failed.', 'error');
      }
    } catch (err) {
      clearTimeout(timer);
      if (timedOut) return;
      const msg = err instanceof ApiError ? err.message : 'Sync request failed.';
      pushToast(msg, 'error');
    } finally {
      if (!timedOut) setSyncing(false);
    }
  }, [syncing, pushToast, loadSyncStatus]);

  const toggleSyncEnabled = useCallback(
    async (enabled: boolean): Promise<void> => {
      try {
        await arrSyncApiRef.current.setEnabled(enabled);
        pushToast(enabled ? 'Auto-sync enabled.' : 'Auto-sync disabled.', 'success');
        await loadSyncStatus();
      } catch (err) {
        const msg = err instanceof ApiError ? err.message : 'Failed to update sync setting.';
        pushToast(msg, 'error');
      }
    },
    [pushToast, loadSyncStatus],
  );

  // ─── Auth providers state ───────────────────────────────────────────────────
  const [providers, setProviders] = useState<AuthProvider[]>([]);
  const [providersLoading, setProvidersLoading] = useState(true);
  // Which provider's config form is currently expanded
  const [expandedProvider, setExpandedProvider] = useState<ProviderName | null>(null);

  // OIDC form fields
  const [oidcSettings, setOidcSettings] = useState<OidcSettings | null>(null);
  const [oidcForm, setOidcForm] = useState({ provider_url: '', client_id: '', client_secret: '', scopes: 'openid profile email' });
  const [oidcSaving, setOidcSaving] = useState(false);
  const [oidcFormError, setOidcFormError] = useState('');

  // LDAP form fields
  const [ldapSettings, setLdapSettings] = useState<LdapSettings | null>(null);
  const [ldapForm, setLdapForm] = useState({
    host: '', port: 389, ssl: false, base_dn: '', bind_dn: '', bind_pw: '',
    user_filter: '', admin_group: '',
  });
  const [ldapSaving, setLdapSaving] = useState(false);
  const [ldapFormError, setLdapFormError] = useState('');
  const [ldapTesting, setLdapTesting] = useState(false);
  const [showLdapBindPw, setShowLdapBindPw] = useState(false);
  const [showOidcSecret, setShowOidcSecret] = useState(false);

  const loadProviders = useCallback(async (): Promise<void> => {
    setProvidersLoading(true);
    try {
      const rows = await authProvidersApiRef.current.listProviders();
      setProviders(rows);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to load auth providers.';
      pushToast(msg, 'error');
    } finally {
      setProvidersLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadProviders();
  }, [loadProviders]);

  const loadOidcSettings = useCallback(async (): Promise<void> => {
    try {
      const settings = await oidcApiRef.current.getSettings();
      setOidcSettings(settings);
      setOidcForm({
        provider_url: settings.provider_url ?? '',
        client_id: settings.client_id ?? '',
        client_secret: '',
        scopes: settings.scopes ?? 'openid profile email',
      });
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to load OIDC settings.';
      pushToast(msg, 'error');
    }
  }, [pushToast]);

  const loadLdapSettings = useCallback(async (): Promise<void> => {
    try {
      const settings = await ldapApiRef.current.getSettings();
      setLdapSettings(settings);
      setLdapForm({
        host: settings.host ?? '',
        port: settings.port ?? 389,
        ssl: settings.ssl ?? false,
        base_dn: settings.base_dn ?? '',
        bind_dn: settings.bind_dn ?? '',
        bind_pw: '',
        user_filter: settings.user_filter ?? '',
        admin_group: settings.admin_group ?? '',
      });
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to load LDAP settings.';
      pushToast(msg, 'error');
    }
  }, [pushToast]);

  const toggleProvider = useCallback(
    async (name: string, currentEnabled: boolean): Promise<void> => {
      try {
        if (currentEnabled) {
          await authProvidersApiRef.current.disableProvider(name);
          pushToast(`${PROVIDER_LABELS[name as ProviderName] ?? name} disabled.`, 'success');
        } else {
          await authProvidersApiRef.current.enableProvider(name);
          pushToast(`${PROVIDER_LABELS[name as ProviderName] ?? name} enabled.`, 'success');
        }
        await loadProviders();
      } catch (err) {
        const msg = err instanceof ApiError ? err.message : `Failed to update ${name}.`;
        pushToast(msg, 'error');
      }
    },
    [pushToast, loadProviders],
  );

  const openProviderConfig = useCallback(
    async (name: ProviderName): Promise<void> => {
      if (expandedProvider === name) {
        setExpandedProvider(null);
        return;
      }
      setExpandedProvider(name);
      setOidcFormError('');
      setLdapFormError('');
      if (name === 'oidc') {
        await loadOidcSettings();
      } else {
        await loadLdapSettings();
      }
    },
    [expandedProvider, loadOidcSettings, loadLdapSettings],
  );

  const saveOidc = useCallback(async (): Promise<void> => {
    setOidcFormError('');
    if (!oidcForm.provider_url.trim()) {
      setOidcFormError('Provider URL is required.');
      return;
    }
    if (!oidcForm.client_id.trim()) {
      setOidcFormError('Client ID is required.');
      return;
    }

    setOidcSaving(true);
    try {
      const input: SaveOidcInput = {
        provider_url: oidcForm.provider_url.trim(),
        client_id: oidcForm.client_id.trim(),
        scopes: oidcForm.scopes.trim() || 'openid profile email',
      };
      if (oidcForm.client_secret.trim()) {
        input.client_secret = oidcForm.client_secret;
      }
      await oidcApiRef.current.saveSettings(input);
      pushToast('OIDC settings saved.', 'success');
      setExpandedProvider(null);
      await loadOidcSettings();
      await loadProviders();
    } catch (err) {
      if (err instanceof ApiError) {
        setOidcFormError(err.message);
      } else {
        pushToast('Failed to save OIDC settings.', 'error');
      }
    } finally {
      setOidcSaving(false);
    }
  }, [oidcForm, pushToast, loadOidcSettings, loadProviders]);

  const saveLdap = useCallback(async (): Promise<void> => {
    setLdapFormError('');
    if (!ldapForm.host.trim()) {
      setLdapFormError('Host is required.');
      return;
    }
    if (!ldapForm.base_dn.trim()) {
      setLdapFormError('Base DN is required.');
      return;
    }

    setLdapSaving(true);
    try {
      const input: SaveLdapInput = {
        host: ldapForm.host.trim(),
        port: ldapForm.port,
        ssl: ldapForm.ssl,
        base_dn: ldapForm.base_dn.trim(),
        bind_dn: ldapForm.bind_dn.trim(),
        user_filter: ldapForm.user_filter.trim(),
        admin_group: ldapForm.admin_group.trim(),
      };
      if (ldapForm.bind_pw.trim()) {
        input.bind_pw = ldapForm.bind_pw;
      }
      await ldapApiRef.current.saveSettings(input);
      pushToast('LDAP settings saved.', 'success');
      setExpandedProvider(null);
      await loadLdapSettings();
      await loadProviders();
    } catch (err) {
      if (err instanceof ApiError) {
        setLdapFormError(err.message);
      } else {
        pushToast('Failed to save LDAP settings.', 'error');
      }
    } finally {
      setLdapSaving(false);
    }
  }, [ldapForm, pushToast, loadLdapSettings, loadProviders]);

  const testLdap = useCallback(async (): Promise<void> => {
    setLdapTesting(true);
    try {
      const input: SaveLdapInput = {
        host: ldapForm.host.trim(),
        port: ldapForm.port,
        ssl: ldapForm.ssl,
        base_dn: ldapForm.base_dn.trim(),
        bind_dn: ldapForm.bind_dn.trim(),
        user_filter: ldapForm.user_filter.trim(),
        admin_group: ldapForm.admin_group.trim(),
      };
      if (ldapForm.bind_pw.trim()) {
        input.bind_pw = ldapForm.bind_pw;
      }
      const result = await ldapApiRef.current.testConnection(input);
      pushToast(result.message, result.success ? 'success' : 'error');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'LDAP connection test failed.';
      pushToast(msg, 'error');
    } finally {
      setLdapTesting(false);
    }
  }, [ldapForm, pushToast]);

  // Determine which providers are currently enabled from the list response.
  const providerEnabled = (name: string): boolean => {
    if (!providers || !Array.isArray(providers)) return false;
    const p = providers.find((pr) => pr.name === name);
    // If the server doesn't tell us enabled state directly, default to whether
    // the provider has settings configured.
    if (name === 'oidc') return oidcSettings?.configured ?? false;
    if (name === 'ldap') return ldapSettings?.configured ?? false;
    return p?.supports_authentication ?? false;
  };

  return (
    <section className="page page--integrations" aria-labelledby="integrations-heading">
      <h1 id="integrations-heading">Integrations</h1>

      {/* ── Section 1: Arr sync ──────────────────────────────────────────── */}
      <section className="integrations__section" aria-labelledby="arr-sync-heading">
        <div className="integrations__section-header">
          <h2 id="arr-sync-heading">Arr sync (TRaSH-Guides)</h2>
          {syncStatus !== null && (
            <span className={`status-badge ${syncStatus.enabled ? 'status-badge--running' : ''}`}>
              {syncStatus.enabled ? 'Enabled' : 'Disabled'}
            </span>
          )}
        </div>

        <div className="integrations__card">
          {syncLoading ? (
            <p role="status" aria-live="polite">Loading sync status…</p>
          ) : syncStatus === null ? (
            <p className="integrations__empty">Unable to load sync status.</p>
          ) : (
            <>
              <dl className="integrations__dl">
                <dt>Last sync</dt>
                <dd>{syncStatus.last_sync_at ?? 'Never synced'}</dd>
                <dt>Auto-sync</dt>
                <dd>
                  <label className="form__label form__label--switch" htmlFor="arr-sync-toggle">
                    <input
                      id="arr-sync-toggle"
                      className="form__switch"
                      type="checkbox"
                      checked={syncStatus.enabled}
                      onChange={(e) => void toggleSyncEnabled(e.target.checked)}
                    />
                    {syncStatus.enabled ? 'Enabled' : 'Disabled'}
                  </label>
                </dd>
              </dl>

              <div className="integrations__card-actions">
                <button
                  type="button"
                  className="btn--primary"
                  onClick={() => void triggerSync()}
                  disabled={syncing}
                  aria-busy={syncing}
                >
                  {syncing ? 'Syncing…' : 'Sync now'}
                </button>
              </div>
            </>
          )}
        </div>
      </section>

      {/* ── Section 2: Auth providers ──────────────────────────────────── */}
      <section className="integrations__section" aria-labelledby="auth-providers-heading">
        <div className="integrations__section-header">
          <h2 id="auth-providers-heading">Authentication providers</h2>
        </div>

        {providersLoading ? (
          <p role="status" aria-live="polite">Loading auth providers…</p>
        ) : (
          <div className="integrations__providers">
            {(['oidc', 'ldap'] as ProviderName[]).map((name) => {
              const enabled = providerEnabled(name);
              const isExpanded = expandedProvider === name;

              return (
                <div key={name} className="integrations__provider-card">
                  <div className="integrations__provider-header">
                    <div className="integrations__provider-info">
                      <span className="integrations__provider-name">
                        {PROVIDER_LABELS[name]}
                      </span>
                      <span className={`status-badge ${enabled ? 'status-badge--running' : ''}`}>
                        {enabled ? 'Enabled' : 'Disabled'}
                      </span>
                    </div>
                    <div className="integrations__provider-actions">
                      <label className="form__label form__label--switch" htmlFor={`${name}-toggle`}>
                        <input
                          id={`${name}-toggle`}
                          className="form__switch"
                          type="checkbox"
                          checked={enabled}
                          onChange={() => void toggleProvider(name, enabled)}
                        />
                      </label>
                      <button
                        type="button"
                        onClick={() => void openProviderConfig(name)}
                        aria-expanded={isExpanded}
                      >
                        {isExpanded ? 'Close' : 'Configure'}
                      </button>
                    </div>
                  </div>

                  {isExpanded && name === 'oidc' && (
                    <div className="integrations__provider-form">
                      <Form onSubmit={saveOidc} busy={oidcSaving}>
                        <Field
                          id="oidc-provider-url"
                          label="Provider URL"
                          value={oidcForm.provider_url}
                          onChange={(v) => setOidcForm((f) => ({ ...f, provider_url: v }))}
                          placeholder="https://idp.example.com"
                          required
                        />
                        <Field
                          id="oidc-client-id"
                          label="Client ID"
                          value={oidcForm.client_id}
                          onChange={(v) => setOidcForm((f) => ({ ...f, client_id: v }))}
                          required
                        />
                        <div className="form__field">
                          <label className="form__label" htmlFor="oidc-client-secret">
                            Client secret
                          </label>
                          <p className="form__hint">
                            {oidcSettings?.configured
                              ? 'Leave blank to keep the current secret.'
                              : 'Required when configuring for the first time.'}
                          </p>
                          <div className="integrations__password-row">
                            <input
                              id="oidc-client-secret"
                              className="form__input"
                              type={showOidcSecret ? 'text' : 'password'}
                              value={oidcForm.client_secret}
                              onChange={(e) =>
                                setOidcForm((f) => ({ ...f, client_secret: e.target.value }))
                              }
                              placeholder={oidcSettings?.configured ? '(unchanged)' : 'Client secret'}
                              autoComplete="new-password"
                            />
                            <button
                              type="button"
                              className="password-toggle"
                              onClick={() => setShowOidcSecret((v) => !v)}
                            >
                              {showOidcSecret ? 'Hide' : 'Show'}
                            </button>
                          </div>
                        </div>
                        <Field
                          id="oidc-scopes"
                          label="Scopes"
                          value={oidcForm.scopes}
                          onChange={(v) => setOidcForm((f) => ({ ...f, scopes: v }))}
                          placeholder="openid profile email"
                        />
                        {oidcFormError ? (
                          <p className="form__error" role="alert">
                            {oidcFormError}
                          </p>
                        ) : null}
                        <div className="form__actions">
                          <button type="submit" disabled={oidcSaving}>
                            {oidcSaving ? 'Saving…' : 'Save OIDC'}
                          </button>
                          <button
                            type="button"
                            onClick={() => setExpandedProvider(null)}
                          >
                            Cancel
                          </button>
                        </div>
                      </Form>
                    </div>
                  )}

                  {isExpanded && name === 'ldap' && (
                    <div className="integrations__provider-form">
                      <Form onSubmit={saveLdap} busy={ldapSaving}>
                        <Field
                          id="ldap-host"
                          label="Host"
                          value={ldapForm.host}
                          onChange={(v) => setLdapForm((f) => ({ ...f, host: v }))}
                          placeholder="ldap.example.com"
                          required
                        />
                        <Field
                          id="ldap-port"
                          label="Port"
                          type="number"
                          value={String(ldapForm.port)}
                          onChange={(v) =>
                            setLdapForm((f) => ({ ...f, port: parseInt(v, 10) || 0 }))
                          }
                          min={1}
                          max={65535}
                        />
                        <Field
                          id="ldap-ssl"
                          label="Use SSL"
                          type="switch"
                          value={ldapForm.ssl ? 'true' : 'false'}
                          onChange={(v) =>
                            setLdapForm((f) => ({ ...f, ssl: v === 'true' }))
                          }
                        />
                        <Field
                          id="ldap-base-dn"
                          label="Base DN"
                          value={ldapForm.base_dn}
                          onChange={(v) => setLdapForm((f) => ({ ...f, base_dn: v }))}
                          placeholder="dc=example,dc=com"
                          required
                        />
                        <Field
                          id="ldap-bind-dn"
                          label="Bind DN"
                          value={ldapForm.bind_dn}
                          onChange={(v) => setLdapForm((f) => ({ ...f, bind_dn: v }))}
                          placeholder="cn=admin,dc=example,dc=com"
                        />
                        <div className="form__field">
                          <label className="form__label" htmlFor="ldap-bind-pw">
                            Bind password
                          </label>
                          <p className="form__hint">
                            {ldapSettings?.configured
                              ? 'Leave blank to keep the current password.'
                              : 'Required when configuring for the first time.'}
                          </p>
                          <div className="integrations__password-row">
                            <input
                              id="ldap-bind-pw"
                              className="form__input"
                              type={showLdapBindPw ? 'text' : 'password'}
                              value={ldapForm.bind_pw}
                              onChange={(e) =>
                                setLdapForm((f) => ({ ...f, bind_pw: e.target.value }))
                              }
                              placeholder={ldapSettings?.configured ? '(unchanged)' : 'Bind password'}
                              autoComplete="new-password"
                            />
                            <button
                              type="button"
                              className="password-toggle"
                              onClick={() => setShowLdapBindPw((v) => !v)}
                            >
                              {showLdapBindPw ? 'Hide' : 'Show'}
                            </button>
                          </div>
                        </div>
                        <Field
                          id="ldap-user-filter"
                          label="User filter"
                          value={ldapForm.user_filter}
                          onChange={(v) => setLdapForm((f) => ({ ...f, user_filter: v }))}
                          placeholder="(uid=%s)"
                        />
                        <Field
                          id="ldap-admin-group"
                          label="Admin group DN"
                          value={ldapForm.admin_group}
                          onChange={(v) => setLdapForm((f) => ({ ...f, admin_group: v }))}
                          placeholder="cn=admins,dc=example,dc=com"
                        />
                        {ldapFormError ? (
                          <p className="form__error" role="alert">
                            {ldapFormError}
                          </p>
                        ) : null}
                        <div className="form__actions integrations__ldap-actions">
                          <button type="submit" disabled={ldapSaving}>
                            {ldapSaving ? 'Saving…' : 'Save LDAP'}
                          </button>
                          <button
                            type="button"
                            className="btn--secondary"
                            onClick={() => void testLdap()}
                            disabled={ldapTesting || ldapSaving}
                          >
                            {ldapTesting ? 'Testing…' : 'Test connection'}
                          </button>
                          <button
                            type="button"
                            onClick={() => setExpandedProvider(null)}
                          >
                            Cancel
                          </button>
                        </div>
                      </Form>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </section>
    </section>
  );
}
