/**
 * LiveTvPage — admin "Live TV / DVR" page with four collapsible sections:
 *   1. Tuners — discovered/configured TV tuners
 *   2. Guide / EPG — program guide grid with date picker
 *   3. Recordings — list of recordings with All / Upcoming / By Series tabs
 *   4. Series Rules — auto-DVR rules
 *
 * Security:
 *  - No `dangerouslySetInnerHTML` — all server/API strings as text.
 *  - Admin gate handled server-side + `useAdminGuard` in App shell.
 *
 * Async/resident rules:
 *  - Data loaded via API calls on mount / section expand.
 *  - useEffect cleans up on unmount.
 *
 * @since 2.5
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import {
  LiveTvApi,
  type Tuner,
  type Program,
  type Recording,
  type SeriesRule,
  type Channel,
} from '../api/liveTv';
import { useToast } from '../components/Toast';

export interface LiveTvPageProps {
  client: ApiClient;
}

// ─── Shared types ───────────────────────────────────────────────────────────

type TunerUpdate = { name?: string; enabled?: boolean };
type RecordingTab = 'all' | 'upcoming' | 'by-series';

interface SectionProps {
  title: string;
  expanded: boolean | undefined;
  onToggle: () => void;
  statusSummary: string | null;
  children: React.ReactNode;
}

function Section({ title, expanded = false, onToggle, statusSummary, children }: SectionProps): JSX.Element {
  const slug = title.toLowerCase().replace(/\s+/g, '-');
  return (
    <section className="livetv__section" aria-labelledby={`livetv-${slug}-heading`}>
      <button
        type="button"
        className="livetv__section-header"
        onClick={onToggle}
        aria-expanded={expanded}
        aria-controls={`livetv-${slug}-body`}
      >
        <div className="livetv__section-title-row">
          <h2 id={`livetv-${slug}-heading`}>{title}</h2>
          <span className={`livetv__chevron ${expanded ? 'livetv__chevron--up' : ''}`} aria-hidden="true">
            &#9660;
          </span>
        </div>
        {statusSummary !== null && (
          <p className="livetv__section-summary">{statusSummary}</p>
        )}
      </button>
      {expanded && (
        <div
          id={`livetv-${slug}-body`}
          className="livetv__section-body"
        >
          {children}
        </div>
      )}
    </section>
  );
}

// ─── Utility helpers ───────────────────────────────────────────────────────

function formatDuration(startSecs: number, endSecs: number): string {
  const mins = Math.round((endSecs - startSecs) / 60);
  if (mins < 60) return `${mins}m`;
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

function formatDate(ts: number): string {
  return new Date(ts * 1000).toLocaleDateString();
}

function formatTime(ts: number): string {
  return new Date(ts * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

// ─── Tuner Card ─────────────────────────────────────────────────────────────

interface TunerCardProps {
  tuner: Tuner;
  onUpdate: (id: string, data: TunerUpdate) => Promise<void>;
  onDelete: (id: string) => Promise<void>;
  pushToast: (msg: string, level?: 'info' | 'success' | 'error') => void;
}

function TunerCard({ tuner, onUpdate, onDelete, pushToast }: TunerCardProps): JSX.Element {
  const [toggling, setToggling] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const enabled = Boolean(tuner.enabled);

  const handleToggle = useCallback(async (): Promise<void> => {
    if (toggling) return;
    setToggling(true);
    try {
      await onUpdate(tuner.tuner_id, { enabled: !enabled });
    } catch (err) {
      pushToast(err instanceof ApiError ? err.message : 'Failed to update tuner.', 'error');
    } finally {
      setToggling(false);
    }
  }, [toggling, enabled, tuner.tuner_id, onUpdate, pushToast]);

  const handleDelete = useCallback(async (): Promise<void> => {
    if (deleting) return;
    if (!window.confirm(`Remove tuner "${tuner.name}"? This cannot be undone.`)) return;
    setDeleting(true);
    try {
      await onDelete(tuner.tuner_id);
      pushToast('Tuner removed.', 'success');
    } catch (err) {
      pushToast(err instanceof ApiError ? err.message : 'Failed to delete tuner.', 'error');
    } finally {
      setDeleting(false);
    }
  }, [deleting, tuner, onDelete, pushToast]);

  return (
    <div className="livetv__tuner-card">
      <div className="livetv__tuner-card__header">
        <div>
          <span className={`badge ${tuner.type === 'HDHomeRun' ? 'badge--accent' : 'badge--purple'}`}>
            {tuner.type}
          </span>
          <span className="livetv__tuner-name">{tuner.name}</span>
        </div>
        <span className={`livetv__status-dot ${enabled ? 'livetv__status-dot--active' : ''}`} title={enabled ? 'Enabled' : 'Disabled'} />
      </div>
      <dl className="livetv__dl">
        <dt>Host</dt>
        <dd>{tuner.host}:{tuner.port}</dd>
        {tuner.device_id && (
          <>
            <dt>Device ID</dt>
            <dd>{tuner.device_id}</dd>
          </>
        )}
        {tuner.last_seen && (
          <>
            <dt>Last Seen</dt>
            <dd>{new Date(tuner.last_seen).toLocaleString()}</dd>
          </>
        )}
        {tuner.status && (
          <>
            <dt>Status</dt>
            <dd>{tuner.status}</dd>
          </>
        )}
      </dl>
      <div className="livetv__card-actions">
        <label className="form__label--switch">
          <input
            type="checkbox"
            className="form__switch"
            checked={enabled}
            onChange={() => void handleToggle()}
            disabled={toggling}
            aria-busy={toggling}
          />
          {enabled ? 'Enabled' : 'Disabled'}
        </label>
        <button
          type="button"
          className="btn--danger btn--sm"
          onClick={() => void handleDelete()}
          disabled={deleting}
          aria-busy={deleting}
        >
          {deleting ? 'Removing…' : 'Remove'}
        </button>
      </div>
    </div>
  );
}

// ─── Recording Card ─────────────────────────────────────────────────────────

interface RecordingCardProps {
  recording: Recording;
  onDelete: (id: string) => Promise<void>;
  pushToast: (msg: string, level?: 'info' | 'success' | 'error') => void;
}

function RecordingCard({ recording, onDelete, pushToast }: RecordingCardProps): JSX.Element {
  const [deleting, setDeleting] = useState(false);

  const handleDelete = useCallback(async (): Promise<void> => {
    if (deleting) return;
    if (!window.confirm(`Delete recording "${recording.program_title ?? recording.id}"?`)) return;
    setDeleting(true);
    try {
      await onDelete(recording.id);
      pushToast('Recording deleted.', 'success');
    } catch (err) {
      pushToast(err instanceof ApiError ? err.message : 'Failed to delete recording.', 'error');
    } finally {
      setDeleting(false);
    }
  }, [deleting, recording, onDelete, pushToast]);

  const statusBadgeClass =
    recording.status === 'completed' ? 'badge--success'
    : recording.status === 'failed' ? 'badge--warning'
    : 'badge--muted';

  return (
    <div className="livetv__recording-card">
      <div className="livetv__recording-card__header">
        <span className="livetv__recording-title">{recording.program_title ?? 'Untitled'}</span>
        {recording.status && (
          <span className={`badge ${statusBadgeClass}`}>{recording.status}</span>
        )}
      </div>
      <div className="livetv__recording-meta">
        <span className="text-muted">{recording.channel_name ?? recording.channel_id}</span>
        <span className="text-muted">
          {formatDate(recording.start_time)} &middot; {formatTime(recording.start_time)} – {formatTime(recording.end_time)}
        </span>
        <span className="text-muted">{formatDuration(recording.start_time, recording.end_time)}</span>
        {recording.size && (
          <span className="text-muted">{(recording.size / 1024 / 1024).toFixed(1)} MB</span>
        )}
      </div>
      <button
        type="button"
        className="btn--danger btn--sm"
        onClick={() => void handleDelete()}
        disabled={deleting}
        aria-busy={deleting}
      >
        {deleting ? 'Deleting…' : 'Delete'}
      </button>
    </div>
  );
}

// ─── Series Rule Row ───────────────────────────────────────────────────────

interface SeriesRuleRowProps {
  rule: SeriesRule;
  channels: Channel[];
  onDelete: (id: string) => Promise<void>;
  pushToast: (msg: string, level?: 'info' | 'success' | 'error') => void;
}

function SeriesRuleRow({ rule, channels, onDelete, pushToast }: SeriesRuleRowProps): JSX.Element {
  const [deleting, setDeleting] = useState(false);
  const channel = channels.find(c => c.id === rule.channel_id);

  const handleDelete = useCallback(async (): Promise<void> => {
    if (deleting) return;
    if (!window.confirm(`Delete series rule "${rule.title_pattern}"?`)) return;
    setDeleting(true);
    try {
      await onDelete(rule.id);
      pushToast('Series rule deleted.', 'success');
    } catch (err) {
      pushToast(err instanceof ApiError ? err.message : 'Failed to delete rule.', 'error');
    } finally {
      setDeleting(false);
    }
  }, [deleting, rule, onDelete, pushToast]);

  return (
    <div className="livetv__rule-row">
      <div className="livetv__rule-row__info">
        <span className="livetv__rule-title">{rule.title_pattern}</span>
        <div className="livetv__rule-meta text-muted">
          {channel ? `${channel.name} (${channel.number})` : rule.channel_id ?? 'Any channel'}
          {rule.priority && <span>Priority {rule.priority}</span>}
          {rule.keep_until && <span>Keep: {rule.keep_until}</span>}
        </div>
      </div>
      <button
        type="button"
        className="btn--danger btn--sm"
        onClick={() => void handleDelete()}
        disabled={deleting}
        aria-busy={deleting}
      >
        {deleting ? 'Deleting…' : 'Delete'}
      </button>
    </div>
  );
}

// ─── Schedule Recording Modal ────────────────────────────────────────────────

interface ScheduleRecordingModalProps {
  channels: Channel[];
  onClose: () => void;
  onSubmit: (data: { channel_id: string; start_time: number; end_time: number; title: string }) => Promise<void>;
  pushToast: (msg: string, level?: 'info' | 'success' | 'error') => void;
}

function ScheduleRecordingModal({ channels, onClose, onSubmit, pushToast }: ScheduleRecordingModalProps): JSX.Element {
  const [channelId, setChannelId] = useState(channels[0]?.id ?? '');
  const [title, setTitle] = useState('');
  const [startDate, setStartDate] = useState('');
  const [startTime, setStartTime] = useState('');
  const [endDate, setEndDate] = useState('');
  const [endTime, setEndTime] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const validate = (): boolean => {
    const errs: Record<string, string> = {};
    if (!channelId) errs.channelId = 'Channel is required.';
    if (!title.trim()) errs.title = 'Title is required.';
    if (!startDate) errs.startDate = 'Start date is required.';
    if (!startTime) errs.startTime = 'Start time is required.';
    if (!endDate) errs.endDate = 'End date is required.';
    if (!endTime) errs.endTime = 'End time is required.';
    if (startDate && startTime && endDate && endTime) {
      const start = new Date(`${startDate}T${startTime}`).getTime() / 1000;
      const end = new Date(`${endDate}T${endTime}`).getTime() / 1000;
      if (end <= start) errs.endTime = 'End must be after start.';
    }
    setErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleSubmit = useCallback(async (): Promise<void> => {
    if (!validate()) return;
    setIsSubmitting(true);
    try {
      const start = Math.floor(new Date(`${startDate}T${startTime}`).getTime() / 1000);
      const end = Math.floor(new Date(`${endDate}T${endTime}`).getTime() / 1000);
      await onSubmit({ channel_id: channelId, start_time: start, end_time: end, title: title.trim() });
      onClose();
    } catch (err) {
      pushToast(err instanceof ApiError ? err.message : 'Failed to schedule recording.', 'error');
    } finally {
      setIsSubmitting(false);
    }
  }, [validate, startDate, startTime, endDate, endTime, channelId, title, onSubmit, onClose, pushToast]);

  return (
    <div className="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="schedule-recording-title">
      <div className="modal">
        <div className="modal__header">
          <h2 id="schedule-recording-title" className="modal__title">Schedule Recording</h2>
          <button type="button" className="modal__close" onClick={onClose} aria-label="Close modal">&#x2715;</button>
        </div>
        <div className="modal__body">
          <div className="form__field">
            <label htmlFor="rec-title">Title</label>
            <input
              id="rec-title"
              type="text"
              className="form__input"
              value={title}
              onChange={e => setTitle(e.target.value)}
              placeholder="e.g. News at Six"
            />
            {errors.title && <span className="form__error">{errors.title}</span>}
          </div>
          <div className="form__field">
            <label htmlFor="rec-channel">Channel</label>
            <select
              id="rec-channel"
              className="form__input"
              value={channelId}
              onChange={e => setChannelId(e.target.value)}
            >
              {channels.map(ch => (
                <option key={ch.id} value={ch.id}>{ch.name} ({ch.number})</option>
              ))}
            </select>
            {errors.channelId && <span className="form__error">{errors.channelId}</span>}
          </div>
          <div className="form__row">
            <div className="form__field">
              <label htmlFor="rec-start-date">Start Date</label>
              <input
                id="rec-start-date"
                type="date"
                className="form__input"
                value={startDate}
                onChange={e => setStartDate(e.target.value)}
              />
              {errors.startDate && <span className="form__error">{errors.startDate}</span>}
            </div>
            <div className="form__field">
              <label htmlFor="rec-start-time">Start Time</label>
              <input
                id="rec-start-time"
                type="time"
                className="form__input"
                value={startTime}
                onChange={e => setStartTime(e.target.value)}
              />
              {errors.startTime && <span className="form__error">{errors.startTime}</span>}
            </div>
          </div>
          <div className="form__row">
            <div className="form__field">
              <label htmlFor="rec-end-date">End Date</label>
              <input
                id="rec-end-date"
                type="date"
                className="form__input"
                value={endDate}
                onChange={e => setEndDate(e.target.value)}
              />
              {errors.endDate && <span className="form__error">{errors.endDate}</span>}
            </div>
            <div className="form__field">
              <label htmlFor="rec-end-time">End Time</label>
              <input
                id="rec-end-time"
                type="time"
                className="form__input"
                value={endTime}
                onChange={e => setEndTime(e.target.value)}
              />
              {errors.endTime && <span className="form__error">{errors.endTime}</span>}
            </div>
          </div>
          <div className="form__actions">
            <button
              type="button"
              className="btn--primary"
              onClick={() => void handleSubmit()}
              disabled={isSubmitting}
              aria-busy={isSubmitting}
            >
              {isSubmitting ? 'Scheduling…' : 'Schedule Recording'}
            </button>
            <button type="button" className="btn--secondary" onClick={onClose}>Cancel</button>
          </div>
        </div>
      </div>
    </div>
  );
}

// ─── Add Rule Modal ─────────────────────────────────────────────────────────

interface AddRuleModalProps {
  channels: Channel[];
  onClose: () => void;
  onSubmit: (data: { series_id: string; channel_id: string; title?: string; priority?: number; keep_until?: string }) => Promise<void>;
  pushToast: (msg: string, level?: 'info' | 'success' | 'error') => void;
}

function AddRuleModal({ channels, onClose, onSubmit, pushToast }: AddRuleModalProps): JSX.Element {
  const [titlePattern, setTitlePattern] = useState('');
  const [channelId, setChannelId] = useState(channels[0]?.id ?? '');
  const [keepUntil, setKeepUntil] = useState('space');
  const [priority, setPriority] = useState(3);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const validate = (): boolean => {
    const errs: Record<string, string> = {};
    if (!titlePattern.trim()) errs.titlePattern = 'Title pattern is required.';
    if (!channelId) errs.channelId = 'Channel is required.';
    setErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleSubmit = useCallback(async (): Promise<void> => {
    if (!validate()) return;
    setIsSubmitting(true);
    try {
      await onSubmit({
        series_id: `local-${Date.now()}`,
        channel_id: channelId,
        title: titlePattern.trim(),
        priority,
        keep_until: keepUntil,
      });
      onClose();
    } catch (err) {
      pushToast(err instanceof ApiError ? err.message : 'Failed to create rule.', 'error');
    } finally {
      setIsSubmitting(false);
    }
  }, [validate, titlePattern, channelId, priority, keepUntil, onSubmit, onClose, pushToast]);

  return (
    <div className="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="add-rule-title">
      <div className="modal">
        <div className="modal__header">
          <h2 id="add-rule-title" className="modal__title">Add Series Rule</h2>
          <button type="button" className="modal__close" onClick={onClose} aria-label="Close modal">&#x2715;</button>
        </div>
        <div className="modal__body">
          <div className="form__field">
            <label htmlFor="rule-title">Title Pattern</label>
            <input
              id="rule-title"
              type="text"
              className="form__input"
              value={titlePattern}
              onChange={e => setTitlePattern(e.target.value)}
              placeholder="e.g. News% or The Simpsons"
            />
            <span className="form__hint">Use % as a wildcard, e.g. "News%" matches all programmes starting with News.</span>
            {errors.titlePattern && <span className="form__error">{errors.titlePattern}</span>}
          </div>
          <div className="form__field">
            <label htmlFor="rule-channel">Channel</label>
            <select
              id="rule-channel"
              className="form__input"
              value={channelId}
              onChange={e => setChannelId(e.target.value)}
            >
              {channels.map(ch => (
                <option key={ch.id} value={ch.id}>{ch.name} ({ch.number})</option>
              ))}
            </select>
            {errors.channelId && <span className="form__error">{errors.channelId}</span>}
          </div>
          <div className="form__field">
            <label htmlFor="rule-priority">Priority (1–5)</label>
            <input
              id="rule-priority"
              type="number"
              className="form__input"
              min={1}
              max={5}
              value={priority}
              onChange={e => setPriority(Number(e.target.value))}
            />
            <span className="form__hint">Higher priority recordings are scheduled first.</span>
          </div>
          <div className="form__field">
            <label htmlFor="rule-keep">Keep Until</label>
            <select
              id="rule-keep"
              className="form__input"
              value={keepUntil}
              onChange={e => setKeepUntil(e.target.value)}
            >
              <option value="space">Until space needed</option>
              <option value="forever">Forever</option>
            </select>
          </div>
          <div className="form__actions">
            <button
              type="button"
              className="btn--primary"
              onClick={() => void handleSubmit()}
              disabled={isSubmitting}
              aria-busy={isSubmitting}
            >
              {isSubmitting ? 'Creating…' : 'Add Rule'}
            </button>
            <button type="button" className="btn--secondary" onClick={onClose}>Cancel</button>
          </div>
        </div>
      </div>
    </div>
  );
}

// ─── Main Page ───────────────────────────────────────────────────────────────

export function LiveTvPage({ client }: LiveTvPageProps): JSX.Element {
  const apiRef = useRef(new LiveTvApi(client));
  const { push: pushToast } = useToast();

  // ─── Section expand/collapse ──────────────────────────────────────────────
  const [expanded, setExpanded] = useState<Record<string, boolean>>({
    tuners: true,
    guide: false,
    recordings: false,
    seriesRules: false,
  });

  const toggleSection = useCallback((section: string): void => {
    setExpanded(prev => ({ ...prev, [section]: !prev[section] }));
  }, []);

  // ─── Channels (shared across sections) ───────────────────────────────────
  const [channels, setChannels] = useState<Channel[]>([]);

  const loadChannels = useCallback(async (): Promise<void> => {
    try {
      const { channels: ch } = await apiRef.current.listChannels();
      setChannels(ch);
    } catch {
      // channels are optional for some views; silently ignore
    }
  }, []);

  // ─── Tuners section ─────────────────────────────────────────────────────────
  const [tuners, setTuners] = useState<Tuner[]>([]);
  const [tunersLoading, setTunersLoading] = useState(false);
  const [scanning, setScanning] = useState(false);

  const loadTuners = useCallback(async (): Promise<void> => {
    setTunersLoading(true);
    try {
      const { tuners: t } = await apiRef.current.listTuners();
      setTuners(t);
    } catch (err) {
      pushToast(err instanceof ApiError ? err.message : 'Failed to load tuners.', 'error');
    } finally {
      setTunersLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    if (expanded.tuners && tuners?.length === 0) {
      void loadTuners();
    }
  }, [expanded.tuners, tuners?.length, loadTuners]);

  const handleScanTuners = useCallback(async (): Promise<void> => {
    if (scanning) return;
    setScanning(true);
    try {
      const { tuners: discovered } = await apiRef.current.scanTuners();
      setTuners(discovered);
      pushToast(`Scan complete. Found ${discovered.length} tuner(s).`, 'success');
    } catch (err) {
      pushToast(err instanceof ApiError ? err.message : 'Tuner scan failed.', 'error');
    } finally {
      setScanning(false);
    }
  }, [scanning, pushToast]);

  const handleUpdateTuner = useCallback(async (id: string, data: TunerUpdate): Promise<void> => {
    const { tuner } = await apiRef.current.updateTuner(id, data);
    setTuners(prev => prev.map(t => t.tuner_id === id ? { ...t, ...tuner } : t));
  }, []);

  const handleDeleteTuner = useCallback(async (id: string): Promise<void> => {
    await apiRef.current.deleteTuner(id);
    setTuners(prev => prev.filter(t => t.tuner_id !== id));
  }, []);

  // ─── Guide section ─────────────────────────────────────────────────────────
  const [programs, setPrograms] = useState<Program[]>([]);
  const [programsLoading, setProgramsLoading] = useState(false);
  const [guideOffset, setGuideOffset] = useState(0); // 0 = today, 1 = tomorrow, etc.
  const [selectedProgram, setSelectedProgram] = useState<Program | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const loadGuide = useCallback(async (offset: number): Promise<void> => {
    setProgramsLoading(true);
    try {
      const now = Math.floor(Date.now() / 1000);
      const from = now + offset * 86400;
      const to = from + 86400;
      const { programs: p } = await apiRef.current.listGuide({ from, to });
      setPrograms(p);
    } catch (err) {
      pushToast(err instanceof ApiError ? err.message : 'Failed to load guide.', 'error');
    } finally {
      setProgramsLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    if (expanded.guide && programs?.length === 0) {
      void loadGuide(guideOffset);
    }
  }, [expanded.guide, programs?.length, loadGuide, guideOffset]);

  const handleRefreshGuide = useCallback(async (): Promise<void> => {
    if (refreshing) return;
    setRefreshing(true);
    try {
      const { programs: count } = await apiRef.current.refreshGuide();
      pushToast(`Guide refreshed. ${count} programmes imported.`, 'success');
      void loadGuide(guideOffset);
    } catch (err) {
      pushToast(err instanceof ApiError ? err.message : 'Guide refresh failed.', 'error');
    } finally {
      setRefreshing(false);
    }
  }, [refreshing, guideOffset, loadGuide, pushToast]);

  // ─── Recordings section ────────────────────────────────────────────────────
  const [recordings, setRecordings] = useState<Recording[]>([]);
  const [recordingsLoading, setRecordingsLoading] = useState(false);
  const [recordingTab, setRecordingTab] = useState<RecordingTab>('all');
  const [showScheduleModal, setShowScheduleModal] = useState(false);

  const loadRecordings = useCallback(async (): Promise<void> => {
    setRecordingsLoading(true);
    try {
      const { recordings: r } = await apiRef.current.listRecordings();
      setRecordings(r);
    } catch (err) {
      pushToast(err instanceof ApiError ? err.message : 'Failed to load recordings.', 'error');
    } finally {
      setRecordingsLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    if (expanded.recordings && recordings?.length === 0) {
      void loadRecordings();
    }
  }, [expanded.recordings, recordings?.length, loadRecordings]);

  const handleDeleteRecording = useCallback(async (id: string): Promise<void> => {
    await apiRef.current.deleteRecording(id);
    setRecordings(prev => prev.filter(r => r.id !== id));
  }, []);

  const handleScheduleRecording = useCallback(async (data: {
    channel_id: string; start_time: number; end_time: number; title: string;
  }): Promise<void> => {
    const { recording } = await apiRef.current.createRecording(data);
    setRecordings(prev => [...prev, recording]);
    pushToast('Recording scheduled.', 'success');
  }, [pushToast]);

  // ─── Series Rules section ─────────────────────────────────────────────────
  const [rules, setRules] = useState<SeriesRule[]>([]);
  const [rulesLoading, setRulesLoading] = useState(false);
  const [showAddRuleModal, setShowAddRuleModal] = useState(false);

  const loadRules = useCallback(async (): Promise<void> => {
    setRulesLoading(true);
    try {
      const { rules: r } = await apiRef.current.listSeriesRules();
      setRules(r);
    } catch (err) {
      pushToast(err instanceof ApiError ? err.message : 'Failed to load series rules.', 'error');
    } finally {
      setRulesLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    if (expanded.seriesRules && rules?.length === 0) {
      void loadRules();
      void loadChannels();
    }
  }, [expanded.seriesRules, rules?.length, loadRules, loadChannels]);

  const handleCreateRule = useCallback(async (data: {
    series_id: string; channel_id: string; title?: string; priority?: number; keep_until?: string;
  }): Promise<void> => {
    const { rule } = await apiRef.current.createSeriesRule(data);
    setRules(prev => [...prev, rule]);
    pushToast('Series rule created.', 'success');
  }, [pushToast]);

  const handleDeleteRule = useCallback(async (id: string): Promise<void> => {
    await apiRef.current.deleteSeriesRule(id);
    setRules(prev => prev.filter(r => r.id !== id));
  }, []);

  // ─── Tuner status summary ──────────────────────────────────────────────────
  const tunerSummary = tunersLoading
    ? 'Loading…'
    : (tuners?.length ?? 0) === 0
      ? 'No tuners found'
      : `${tuners!.length} tuner${tuners!.length !== 1 ? 's' : ''} configured`;

  // ─── Render ────────────────────────────────────────────────────────────────
  return (
    <section className="page page--live-tv" aria-labelledby="live-tv-heading">
      <h1 id="live-tv-heading">Live TV / DVR</h1>

      {/* ── Section 1: Tuners ──────────────────────────────────────────── */}
      <Section
        title="Tuners"
        expanded={expanded.tuners}
        onToggle={() => toggleSection('tuners')}
        statusSummary={tunerSummary}
      >
        <div className="livetv__section-toolbar">
          <button
            type="button"
            className="btn--primary btn--sm"
            onClick={() => void handleScanTuners()}
            disabled={scanning}
            aria-busy={scanning}
          >
            {scanning ? 'Scanning…' : 'Scan for Tuners'}
          </button>
        </div>

        <div className="livetv__tuner-grid">
          {tunersLoading ? (
            <p role="status" aria-live="polite">Loading tuners…</p>
          ) : (tuners?.length ?? 0) === 0 ? (
            <p className="livetv__empty">
              No tuners found. Click &ldquo;Scan for Tuners&rdquo; to discover HDHomeRun devices on your network.
            </p>
          ) : (
            (tuners ?? []).map(tuner => (
              <TunerCard
                key={tuner.tuner_id}
                tuner={tuner}
                onUpdate={handleUpdateTuner}
                onDelete={handleDeleteTuner}
                pushToast={pushToast}
              />
            ))
          )}
        </div>
      </Section>

      {/* ── Section 2: Guide / EPG ─────────────────────────────────────── */}
      <Section
        title="Guide / EPG"
        expanded={expanded.guide}
        onToggle={() => toggleSection('guide')}
        statusSummary={
          programsLoading
            ? 'Loading…'
            : (programs?.length ?? 0) > 0
              ? `${programs!.length} programmes`
              : 'No programmes'
        }
      >
        <div className="livetv__section-toolbar">
          <div className="livetv__date-picker" role="group" aria-label="Guide date">
            {['Today', '+1 Day', '+2 Days'].map((label, i) => (
              <button
                key={i}
                type="button"
                className={`filter-btn ${guideOffset === i ? 'filter-btn--active' : ''}`}
                onClick={() => { setGuideOffset(i); void loadGuide(i); }}
              >
                {label}
              </button>
            ))}
          </div>
          <button
            type="button"
            className="btn--secondary btn--sm"
            onClick={() => void handleRefreshGuide()}
            disabled={refreshing}
            aria-busy={refreshing}
          >
            {refreshing ? 'Refreshing…' : 'Refresh Guide'}
          </button>
        </div>

        {programsLoading ? (
          <p role="status" aria-live="polite">Loading guide…</p>
        ) : (programs?.length ?? 0) === 0 ? (
          <p className="livetv__empty">No programmes listed for this date. Try a different day or refresh the guide.</p>
        ) : (
          <div className="livetv__guide-grid" role="list">
            {(programs ?? []).map(prog => (
              <div
                key={prog.id}
                className={`livetv__program-card ${selectedProgram?.id === prog.id ? 'livetv__program-card--selected' : ''}`}
                role="listitem"
                tabIndex={0}
                onClick={() => setSelectedProgram(selectedProgram?.id === prog.id ? null : prog)}
                onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') setSelectedProgram(selectedProgram?.id === prog.id ? null : prog); }}
              >
                <div className="livetv__program-time">
                  {formatTime(prog.start_time)} – {formatTime(prog.end_time)}
                </div>
                <div className="livetv__program-title">{prog.title}</div>
                {prog.description && (
                  <p className="livetv__program-desc">{prog.description.slice(0, 100)}{prog.description.length > 100 ? '…' : ''}</p>
                )}
                {selectedProgram?.id === prog.id && (
                  <div className="livetv__program-expanded">
                    {prog.description && <p className="livetv__program-full-desc">{prog.description}</p>}
                    <div className="livetv__program-meta text-muted">
                      {prog.rating && <span>Rating: {prog.rating}</span>}
                      {prog.season && <span>S{String(prog.season).padStart(2, '0')}E{String(prog.episode ?? 0).padStart(2, '0')}</span>}
                      {prog.year && <span>{prog.year}</span>}
                    </div>
                    <button
                      type="button"
                      className="btn--primary btn--sm"
                      onClick={e => { e.stopPropagation(); pushToast('Recording not yet implemented via guide.', 'info'); }}
                    >
                      Record
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </Section>

      {/* ── Section 3: Recordings ────────────────────────────────────── */}
      <Section
        title="Recordings"
        expanded={expanded.recordings}
        onToggle={() => toggleSection('recordings')}
        statusSummary={
          recordingsLoading
            ? 'Loading…'
            : `${recordings?.length ?? 0} recording${(recordings?.length ?? 0) !== 1 ? 's' : ''}`
        }
      >
        <div className="livetv__section-toolbar">
          <div className="livetv__recording-tabs" role="tablist">
            {(['all', 'upcoming', 'by-series'] as RecordingTab[]).map(tab => (
              <button
                key={tab}
                role="tab"
                aria-selected={recordingTab === tab}
                className={`livetv__recording-tab ${recordingTab === tab ? 'livetv__recording-tab--active' : ''}`}
                onClick={() => setRecordingTab(tab)}
              >
                {tab === 'all' ? 'All Recordings' : tab === 'upcoming' ? 'Upcoming' : 'By Series'}
              </button>
            ))}
          </div>
          <button
            type="button"
            className="btn--primary btn--sm"
            onClick={() => { void loadChannels(); setShowScheduleModal(true); }}
          >
            Schedule Recording
          </button>
        </div>

        {recordingsLoading ? (
          <p role="status" aria-live="polite">Loading recordings…</p>
        ) : (recordings?.length ?? 0) === 0 ? (
          <p className="livetv__empty">
            {recordingTab === 'all' ? 'No recordings yet.' : recordingTab === 'upcoming' ? 'No upcoming recordings.' : 'No series recordings.'}
          </p>
        ) : (
          <div className="livetv__recordings-list">
            {(recordings ?? []).map(rec => (
              <RecordingCard
                key={rec.id}
                recording={rec}
                onDelete={handleDeleteRecording}
                pushToast={pushToast}
              />
            ))}
          </div>
        )}
      </Section>

      {/* ── Section 4: Series Rules ───────────────────────────────────── */}
      <Section
        title="Series Rules"
        expanded={expanded.seriesRules}
        onToggle={() => toggleSection('seriesRules')}
        statusSummary={
          rulesLoading
            ? 'Loading…'
            : `${rules?.length ?? 0} rule${(rules?.length ?? 0) !== 1 ? 's' : ''}`
        }
      >
        <div className="livetv__section-toolbar">
          <button
            type="button"
            className="btn--primary btn--sm"
            onClick={() => setShowAddRuleModal(true)}
          >
            Add Rule
          </button>
        </div>

        {rulesLoading ? (
          <p role="status" aria-live="polite">Loading rules…</p>
        ) : (rules?.length ?? 0) === 0 ? (
          <p className="livetv__empty">No series rules defined. Add a rule to automatically record programmes by title pattern.</p>
        ) : (
          <div className="livetv__rules-list">
            {(rules ?? []).map(rule => (
              <SeriesRuleRow
                key={rule.id}
                rule={rule}
                channels={channels}
                onDelete={handleDeleteRule}
                pushToast={pushToast}
              />
            ))}
          </div>
        )}
      </Section>

      {/* ── Schedule Recording Modal ─────────────────────────────────── */}
      {showScheduleModal && channels.length > 0 && (
        <ScheduleRecordingModal
          channels={channels}
          onClose={() => setShowScheduleModal(false)}
          onSubmit={handleScheduleRecording}
          pushToast={pushToast}
        />
      )}

      {/* ── Add Rule Modal ────────────────────────────────────────────── */}
      {showAddRuleModal && (
        <AddRuleModal
          channels={channels}
          onClose={() => setShowAddRuleModal(false)}
          onSubmit={handleCreateRule}
          pushToast={pushToast}
        />
      )}
    </section>
  );
}
