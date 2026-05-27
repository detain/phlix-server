/**
 * Modal dialog — a controlled, accessible overlay.
 *
 * Renders nothing when `open` is false. When open, shows a backdrop +
 * dialog with a title and close button. Closes on backdrop click and on
 * the Escape key. Content is passed as children (rendered as React nodes,
 * never raw HTML).
 */
import { useEffect, type ReactNode } from 'react';

export interface ModalProps {
  open: boolean;
  title: string;
  onClose: () => void;
  children: ReactNode;
}

export function Modal({ open, title, onClose, children }: ModalProps): JSX.Element | null {
  useEffect(() => {
    if (!open) {
      return;
    }
    const onKey = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) {
    return null;
  }

  return (
    <div
      className="modal-backdrop"
      onClick={onClose}
      data-testid="modal-backdrop"
    >
      <div
        className="modal"
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onClick={(e) => e.stopPropagation()}
      >
        <header className="modal__header">
          <h2 className="modal__title">{title}</h2>
          <button
            type="button"
            className="modal__close"
            aria-label="Close dialog"
            onClick={onClose}
          >
            ×
          </button>
        </header>
        <div className="modal__body">{children}</div>
      </div>
    </div>
  );
}
