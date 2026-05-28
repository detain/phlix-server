/**
 * SettingsPage — the admin "Server Settings" feature page.
 *
 * Renders all 8 schema groups as tabbed sections, consumes the 0.5
 * GET/PUT /api/v1/admin/settings contract, and shows per-field validation
 * errors on 400 responses.
 *
 * Security:
 *  - Every server/API string is rendered as a React text child — no
 *    `dangerouslySetInnerHTML`.
 *  - Admin gate is handled server-side + `useAdminGuard` client-side
 *    (in the App shell).
 *
 * Async/resident rules:
 *  - No polling needed. useEffect cleans up on unmount.
 *
 * @since 1.3
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import { SettingsApi } from '../api/settings';
import { Form, Field } from '../components/Form';
import { useToast } from '../components/Toast';

export interface SettingsPageProps {
  client: ApiClient;
}

/** Tab definitions — order must match the spec. */
const TABS = [
  { id: 'transcoding', label: 'Transcoding' },
  { id: 'metadata', label: 'Metadata' },
  { id: 'markers', label: 'Markers' },
  { id: 'subtitles', label: 'Subtitles' },
  { id: 'discovery', label: 'Discovery' },
  { id: 'trickplay', label: 'Trickplay' },
  { id: 'newsletter', label: 'Newsletter' },
  { id: 'port-forward', label: 'Port Forward' },
] as const;

type TabId = (typeof TABS)[number]['id'];

/** Keys that belong to each tab group. */
const TAB_KEYS: Record<TabId, string[]> = {
  transcoding: ['hwaccel.enabled', 'hwaccel.prefer_hardware', 'hwaccel.probe_timeout'],
  metadata: ['tmdb.api_key'],
  markers: ['marker_detection.similarity_threshold', 'marker_detection.intro_max_duration'],
  subtitles: ['subtitles.enabled', 'subtitles.default_language', 'subtitles.burn_in_by_default'],
  discovery: ['discovery.discovery_port'],
  trickplay: ['trickplay.enabled', 'trickplay.interval_seconds'],
  newsletter: ['newsletter.enabled', 'newsletter.send_hour'],
  'port-forward': ['port-forward.port_forwarding.upnp_enabled'],
};

/** Constraints for number fields (min/max from schema). */
const NUMERIC_CONSTRAINTS: Record<string, { min?: number; max?: number }> = {
  'hwaccel.probe_timeout': { min: 0 },
  'marker_detection.similarity_threshold': { min: 0, max: 1 },
  'marker_detection.intro_max_duration': { min: 0 },
  'discovery.discovery_port': { min: 1, max: 65535 },
  'trickplay.interval_seconds': { min: 1 },
  'newsletter.send_hour': { min: 0, max: 23 },
};

/** Fields that should render as password type. */
const PASSWORD_FIELDS = new Set(['tmdb.api_key']);

function getDisplayName(key: string): string {
  // Human-readable label from key: "hwaccel.enabled" → "Hwaccel enabled"
  // or "tmdb.api_key" → "Api Key"
  return key
    .split('.')
    .pop()!
    .replace(/_/g, ' ')
    .replace(/\b[a-z]/g, (c) => c.toUpperCase());
}

function isOverridden(key: string, overridden: string[]): boolean {
  return overridden.includes(key);
}

function renderFieldError(key: string, errors: Record<string, string> | undefined): JSX.Element | null {
  if (!errors || !errors[key]) return null;
  return (
    <span className="form__error" role="alert">
      {errors[key]}
    </span>
  );
}

export function SettingsPage({ client }: SettingsPageProps): JSX.Element {
  const settingsApiRef = useRef(new SettingsApi(client));
  // Destructure the stable `push` callback — the whole `useToast()` context
  // value is a fresh object reference on every toast queue change.
  const { push: pushToast } = useToast();

  // Settings data
  const [settings, setSettings] = useState<Record<string, unknown>>({});
  const [overridden, setOverridden] = useState<string[]>([]);
  const [types, setTypes] = useState<Record<string, string>>({});

  // UI state
  const [activeTab, setActiveTab] = useState<TabId>('transcoding');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [showPassword, setShowPassword] = useState<Record<string, boolean>>({});

  // Dirty state — track which keys have been changed from original
  const [dirty, setDirty] = useState<Record<string, boolean>>({});
  const [formValues, setFormValues] = useState<Record<string, string>>({});

  const loadSettings = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      const data = await settingsApiRef.current.get();
      setSettings(data.settings);
      setOverridden(data.overridden);
      setTypes(data.types);

      // Initialize form values from settings
      const initial: Record<string, string> = {};
      for (const [key, val] of Object.entries(data.settings)) {
        initial[key] = String(val ?? '');
      }
      setFormValues(initial);
      setDirty({});
      setFieldErrors({});
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to load settings.';
      pushToast(message, 'error');
    } finally {
      setLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadSettings();
  }, [loadSettings]);

  const handleFieldChange = (key: string, value: string): void => {
    setFormValues((prev) => ({ ...prev, [key]: value }));
    setDirty((prev) => ({ ...prev, [key]: value !== String(settings[key] ?? '') }));
  };

  const toggleShowPassword = (key: string): void => {
    setShowPassword((prev) => ({ ...prev, [key]: !prev[key] }));
  };

  const handleSubmit = async (): Promise<void> => {
    setSubmitting(true);
    setFieldErrors({});
    try {
      // Collect only dirty fields
      const toSave: Record<string, unknown> = {};
      for (const [key, isDirty] of Object.entries(dirty)) {
        if (isDirty) {
          const type = types[key];
          const value = formValues[key] ?? '';
          // Coerce string to appropriate type based on schema
          if (type === 'bool') {
            toSave[key] = value === 'true' || value === '1';
          } else if (type === 'int') {
            toSave[key] = parseInt(value, 10);
          } else if (type === 'float') {
            toSave[key] = parseFloat(value);
          } else {
            toSave[key] = value;
          }
        }
      }

      const result = await settingsApiRef.current.save(toSave);
      pushToast('Settings saved.', 'success');
      // Refresh with new overridden list
      setSettings(result.settings);
      setOverridden(result.overridden);
      setDirty({});
      // Re-sync form values
      const updated: Record<string, string> = {};
      for (const [key, val] of Object.entries(result.settings)) {
        updated[key] = String(val ?? '');
      }
      setFormValues(updated);
    } catch (err) {
      if (err instanceof ApiError && err.status === 400) {
        const body = err.body as { errors?: Record<string, string> } | undefined;
        if (body?.errors && Object.keys(body.errors).length > 0) {
          setFieldErrors(body.errors);
          pushToast('Please fix the validation errors.', 'error');
        } else {
          pushToast(err.message, 'error');
        }
      } else {
        const message =
          err instanceof ApiError ? err.message : 'Failed to save settings.';
        pushToast(message, 'error');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const hasAnyChanges = Object.values(dirty).some(Boolean);

  // Render a single field for a given key
  const renderField = (key: string): JSX.Element => {
    const type = types[key] ?? 'string';
    const value = formValues[key] ?? '';
    const constraints = NUMERIC_CONSTRAINTS[key] ?? {};
    const isPassword = PASSWORD_FIELDS.has(key);
    const showPw = showPassword[key] ?? false;

    if (type === 'bool') {
      return (
        <div key={key} className="settings-field">
          <Field
            id={`field-${key}`}
            label={getDisplayName(key)}
            value={value}
            onChange={(v) => handleFieldChange(key, v)}
            type="switch"
          />
          {isOverridden(key, overridden) && (
            <span className="overridden-badge">custom</span>
          )}
          {renderFieldError(key, fieldErrors)}
        </div>
      );
    }

    if (type === 'int' || type === 'float') {
      return (
        <div key={key} className="settings-field">
          <Field
            id={`field-${key}`}
            label={getDisplayName(key)}
            value={value}
            onChange={(v) => handleFieldChange(key, v)}
            type="number"
            placeholder={constraints.min !== undefined ? `min: ${constraints.min}` : undefined}
            min={constraints.min}
            max={constraints.max}
          />
          {isOverridden(key, overridden) && (
            <span className="overridden-badge">custom</span>
          )}
          {renderFieldError(key, fieldErrors)}
        </div>
      );
    }

    // string type
    if (isPassword) {
      return (
        <div key={key} className="settings-field settings-field--password">
          <Field
            id={`field-${key}`}
            label={getDisplayName(key)}
            value={value}
            onChange={(v) => handleFieldChange(key, v)}
            type={showPw ? 'text' : 'password'}
          />
          <button
            type="button"
            className="password-toggle"
            onClick={() => toggleShowPassword(key)}
          >
            {showPw ? 'Hide' : 'Show'}
          </button>
          {isOverridden(key, overridden) && (
            <span className="overridden-badge">custom</span>
          )}
          {renderFieldError(key, fieldErrors)}
        </div>
      );
    }

    return (
      <div key={key} className="settings-field">
        <Field
          id={`field-${key}`}
          label={getDisplayName(key)}
          value={value}
          onChange={(v) => handleFieldChange(key, v)}
          type="text"
        />
        {isOverridden(key, overridden) && (
          <span className="overridden-badge">custom</span>
        )}
        {renderFieldError(key, fieldErrors)}
      </div>
    );
  };

  const activeKeys = TAB_KEYS[activeTab] ?? [];

  if (loading) {
    return (
      <section className="page page--settings" aria-labelledby="settings-heading">
        <header className="page__header">
          <h1 id="settings-heading">Settings</h1>
        </header>
        <p role="status" aria-live="polite">Loading…</p>
      </section>
    );
  }

  return (
    <section className="page page--settings" aria-labelledby="settings-heading">
      <header className="page__header">
        <h1 id="settings-heading">Settings</h1>
      </header>

      {/* Tab bar */}
      <div className="settings-tabs" role="tablist" aria-label="Settings groups">
        {TABS.map((tab) => (
          <button
            key={tab.id}
            type="button"
            role="tab"
            aria-selected={activeTab === tab.id}
            aria-controls={`tabpanel-${tab.id}`}
            className={`settings-tab${activeTab === tab.id ? ' settings-tab--active' : ''}`}
            onClick={() => setActiveTab(tab.id)}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Tab panel */}
      <div
        id={`tabpanel-${activeTab}`}
        role="tabpanel"
        aria-labelledby={`tab-${activeTab}`}
        className="settings-panel"
      >
        {activeKeys.length === 0 ? (
          <p className="settings-empty">No settings in this group.</p>
        ) : (
          <Form onSubmit={handleSubmit} busy={submitting}>
            {activeKeys.map((key) => renderField(key))}
            <div className="form__actions settings-save">
              <button type="submit" disabled={!hasAnyChanges || submitting}>
                {submitting ? 'Saving…' : 'Save settings'}
              </button>
            </div>
          </Form>
        )}
      </div>
    </section>
  );
}
