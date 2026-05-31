/**
 * LogsPage — view & tail the server log files from the admin UI.
 *
 * Lists `.log` files (via {@link LogsApi}), tails the selected one, and can
 * auto-refresh on an interval. The API client is held in a `useRef` so it is
 * stable across renders (a fresh client each render would make the fetch
 * callbacks change identity and loop the effects).
 *
 * @since 1.7
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiClient } from '../api/client';
import type { ApiClient as ApiClientInterface } from '../api/client';
import { LogsApi, type LogFile } from '../api/logs';
import { ApiError } from '../api/client';
import { useToast } from '../components/Toast';

export interface LogsPageProps {
  client?: ApiClientInterface;
}

const LINE_OPTIONS = [200, 500, 1000, 2000] as const;
const AUTO_REFRESH_MS = 5000;

export function LogsPage({ client = new ApiClient() }: LogsPageProps): JSX.Element {
  const apiRef = useRef(new LogsApi(client));
  const { push: pushToast } = useToast();

  const [files, setFiles] = useState<LogFile[]>([]);
  const [selected, setSelected] = useState<string>('');
  const [lineCount, setLineCount] = useState<number>(200);
  const [lines, setLines] = useState<string[]>([]);
  const [truncated, setTruncated] = useState(false);
  const [loading, setLoading] = useState(false);
  const [autoRefresh, setAutoRefresh] = useState(false);

  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const preRef = useRef<HTMLPreElement | null>(null);

  // Load the file list once on mount; default to the first file.
  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const list = await apiRef.current.list();
        if (cancelled) return;
        setFiles(list);
        if (list.length > 0) {
          setSelected((cur) => cur || list[0]!.name);
        }
      } catch (err) {
        if (cancelled) return;
        pushToast(err instanceof ApiError ? err.message : 'Failed to list logs.', 'error');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [pushToast]);

  const refresh = useCallback(
    async (file: string, count: number): Promise<void> => {
      if (file === '') return;
      setLoading(true);
      try {
        const res = await apiRef.current.tail(file, count);
        setLines(res.lines);
        setTruncated(res.truncated);
        // Keep the view pinned to the newest lines.
        requestAnimationFrame(() => {
          if (preRef.current) {
            preRef.current.scrollTop = preRef.current.scrollHeight;
          }
        });
      } catch (err) {
        pushToast(err instanceof ApiError ? err.message : 'Failed to read log.', 'error');
      } finally {
        setLoading(false);
      }
    },
    [pushToast],
  );

  // Fetch when the selected file or line count changes.
  useEffect(() => {
    void refresh(selected, lineCount);
  }, [selected, lineCount, refresh]);

  // Auto-refresh polling.
  useEffect(() => {
    if (!autoRefresh || selected === '') {
      return;
    }
    timerRef.current = setInterval(() => {
      void refresh(selected, lineCount);
    }, AUTO_REFRESH_MS);
    return () => {
      if (timerRef.current !== null) {
        clearInterval(timerRef.current);
        timerRef.current = null;
      }
    };
  }, [autoRefresh, selected, lineCount, refresh]);

  return (
    <section className="page page--logs" aria-labelledby="logs-heading">
      <div className="page__header">
        <h1 id="logs-heading">Logs</h1>
      </div>

      <div className="logs-controls">
        <label className="logs-controls__field">
          <span>File</span>
          <select
            aria-label="Log file"
            value={selected}
            onChange={(e) => setSelected(e.target.value)}
          >
            {files.length === 0 ? <option value="">(no log files)</option> : null}
            {files.map((f) => (
              <option key={f.name} value={f.name}>
                {f.name}
              </option>
            ))}
          </select>
        </label>

        <label className="logs-controls__field">
          <span>Lines</span>
          <select
            aria-label="Line count"
            value={String(lineCount)}
            onChange={(e) => setLineCount(Number(e.target.value))}
          >
            {LINE_OPTIONS.map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </select>
        </label>

        <button
          type="button"
          className="btn"
          disabled={loading || selected === ''}
          onClick={() => void refresh(selected, lineCount)}
        >
          {loading ? 'Refreshing…' : 'Refresh'}
        </button>

        <label className="logs-controls__toggle">
          <input
            type="checkbox"
            checked={autoRefresh}
            onChange={(e) => setAutoRefresh(e.target.checked)}
          />
          <span>Auto-refresh (5s)</span>
        </label>
      </div>

      {truncated ? (
        <p className="logs-truncated" role="note">
          Showing the most recent {lineCount} lines (file is larger).
        </p>
      ) : null}

      <pre className="logs-output" data-testid="logs-output" ref={preRef} aria-live="polite">
        {lines.length === 0 ? '(no output)' : lines.join('\n')}
      </pre>
    </section>
  );
}
