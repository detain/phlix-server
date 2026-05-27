/**
 * Toast notifications — a minimal, dependency-free toast system.
 *
 * Exposes a {@link ToastProvider} that owns the toast queue and a
 * {@link useToast} hook returning `push(message, level)`. Toasts
 * auto-dismiss after a timeout and can be dismissed manually.
 *
 * Messages are rendered as text content (never `dangerouslySetInnerHTML`)
 * so a message containing markup cannot inject script — an explicit XSS
 * precaution given toasts often echo server/API error strings.
 */
import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';

export type ToastLevel = 'info' | 'success' | 'error';

export interface Toast {
  id: number;
  message: string;
  level: ToastLevel;
}

interface ToastContextValue {
  toasts: Toast[];
  push: (message: string, level?: ToastLevel) => number;
  dismiss: (id: number) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

export const DEFAULT_TOAST_TIMEOUT_MS = 5000;

export function ToastProvider({
  children,
  timeoutMs = DEFAULT_TOAST_TIMEOUT_MS,
}: {
  children: ReactNode;
  timeoutMs?: number;
}): JSX.Element {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const nextId = useRef(1);

  const dismiss = useCallback((id: number) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const push = useCallback(
    (message: string, level: ToastLevel = 'info'): number => {
      const id = nextId.current++;
      setToasts((prev) => [...prev, { id, message, level }]);
      if (timeoutMs > 0) {
        setTimeout(() => dismiss(id), timeoutMs);
      }
      return id;
    },
    [dismiss, timeoutMs],
  );

  const value = useMemo<ToastContextValue>(
    () => ({ toasts, push, dismiss }),
    [toasts, push, dismiss],
  );

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div className="toast-region" role="region" aria-label="Notifications">
        {toasts.map((t) => (
          <div
            key={t.id}
            className={`toast toast--${t.level}`}
            role={t.level === 'error' ? 'alert' : 'status'}
          >
            <span className="toast__message">{t.message}</span>
            <button
              type="button"
              className="toast__close"
              aria-label="Dismiss notification"
              onClick={() => dismiss(t.id)}
            >
              ×
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext);
  if (ctx === null) {
    throw new Error('useToast must be used within a <ToastProvider>');
  }
  return ctx;
}
