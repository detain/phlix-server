/**
 * Form — a thin, controlled form wrapper plus a labelled text field.
 *
 * {@link Form} calls `onSubmit` with `event.preventDefault()` already
 * applied (so callers never accidentally do a full-page navigation under
 * the SPA). {@link Field} renders a labelled input wired to a controlled
 * value/onChange pair. These are intentionally minimal building blocks for
 * Phase-1 feature pages — no validation library, no uncontrolled inputs.
 */
import { type FormEvent, type ReactNode } from 'react';

export interface FormProps {
  onSubmit: () => void | Promise<void>;
  children: ReactNode;
  /** Disable the whole form (e.g. while submitting). */
  busy?: boolean;
}

export function Form({ onSubmit, children, busy = false }: FormProps): JSX.Element {
  const handle = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    void onSubmit();
  };
  return (
    <form className="form" onSubmit={handle} noValidate>
      <fieldset disabled={busy} className="form__fieldset">
        {children}
      </fieldset>
    </form>
  );
}

export interface FieldProps {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  type?: 'text' | 'email' | 'password' | 'url' | 'number';
  placeholder?: string;
  required?: boolean;
}

export function Field({
  id,
  label,
  value,
  onChange,
  type = 'text',
  placeholder,
  required = false,
}: FieldProps): JSX.Element {
  return (
    <div className="form__field">
      <label className="form__label" htmlFor={id}>
        {label}
        {required ? <span aria-hidden="true"> *</span> : null}
      </label>
      <input
        id={id}
        className="form__input"
        type={type}
        value={value}
        placeholder={placeholder}
        required={required}
        onChange={(e) => onChange(e.target.value)}
      />
    </div>
  );
}
