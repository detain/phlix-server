/**
 * PathPicker — a controlled directory picker for library paths.
 *
 * Drives the 0.6 `fs/browse` endpoint (via an injected {@link FilesystemApi},
 * so tests can supply a `makeFetch`-backed client). It shows the current
 * directory, an "Up" control to the parent, and the immediate subdirectory
 * entries; the user drills in by clicking an entry and adds the current
 * directory to the form's path list with "Select this folder". Already-selected
 * paths are listed with a remove control.
 *
 * Security: every directory `name`/`path` is rendered as a React text child
 * (NEVER `dangerouslySetInnerHTML`), so an untrusted directory name cannot
 * inject markup. Browse failures surface as inline text (and via the optional
 * `onError` callback) rather than crashing the form.
 *
 * @since 1.1c
 */
import { useCallback, useEffect, useState } from 'react';
import {
  FilesystemApi,
  type FsBrowseResult,
} from '../api/filesystem';
import { ApiError } from '../api/client';

export interface PathPickerProps {
  /** The filesystem client to browse with (inject for tests). */
  fs: FilesystemApi;
  /** The currently-selected library paths (controlled). */
  selected: string[];
  /** Called when the selected-path list changes. */
  onChange: (paths: string[]) => void;
  /** Optional callback invoked with a human-readable browse error message. */
  onError?: (message: string) => void;
}

export function PathPicker({
  fs,
  selected,
  onChange,
  onError,
}: PathPickerProps): JSX.Element {
  const [result, setResult] = useState<FsBrowseResult | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const browse = useCallback(
    async (path?: string): Promise<void> => {
      setLoading(true);
      setError(null);
      try {
        const next = await fs.browse(path);
        setResult(next);
      } catch (err) {
        const message =
          err instanceof ApiError
            ? err.message
            : 'Failed to browse the filesystem.';
        setError(message);
        onError?.(message);
      } finally {
        setLoading(false);
      }
    },
    [fs, onError],
  );

  useEffect(() => {
    // Initial load: list the roots (empty path).
    void browse();
  }, [browse]);

  const current = result?.path ?? null;

  const addCurrent = (): void => {
    if (current === null || selected.includes(current)) {
      return;
    }
    onChange([...selected, current]);
  };

  const removeSelected = (path: string): void => {
    onChange(selected.filter((p) => p !== path));
  };

  return (
    <div className="path-picker" data-testid="path-picker">
      <div className="path-picker__current">
        <span className="path-picker__label">Current folder:</span>{' '}
        <span className="path-picker__path" data-testid="path-picker-current">
          {current ?? '(roots)'}
        </span>
      </div>

      <div className="path-picker__controls">
        <button
          type="button"
          className="path-picker__up"
          disabled={loading || result === null || result.parent === null}
          onClick={() => {
            if (result && result.parent !== null) {
              void browse(result.parent);
            }
          }}
        >
          Up
        </button>
        <button
          type="button"
          className="path-picker__select"
          disabled={loading || current === null || selected.includes(current)}
          onClick={addCurrent}
        >
          Select this folder
        </button>
      </div>

      {error !== null ? (
        <p className="path-picker__error" role="alert">
          {error}
        </p>
      ) : null}

      {loading ? (
        <p className="path-picker__loading" role="status">
          Loading…
        </p>
      ) : (
        <ul className="path-picker__entries" aria-label="Subdirectories">
          {result && result.entries.length > 0 ? (
            result.entries.map((entry) => (
              <li key={entry.path} className="path-picker__entry">
                <button
                  type="button"
                  className="path-picker__entry-btn"
                  onClick={() => void browse(entry.path)}
                >
                  {entry.name}
                </button>
              </li>
            ))
          ) : (
            <li className="path-picker__empty">No subfolders.</li>
          )}
        </ul>
      )}

      <div className="path-picker__selected">
        <span className="path-picker__label">Selected paths:</span>
        {selected.length === 0 ? (
          <p className="path-picker__none">No paths selected yet.</p>
        ) : (
          <ul aria-label="Selected paths">
            {selected.map((path) => (
              <li key={path} className="path-picker__selected-item">
                <span className="path-picker__selected-path">{path}</span>
                <button
                  type="button"
                  className="path-picker__remove"
                  aria-label={`Remove ${path}`}
                  onClick={() => removeSelected(path)}
                >
                  Remove
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
