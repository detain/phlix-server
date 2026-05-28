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
  type?: 'text' | 'email' | 'password' | 'url' | 'number' | 'switch';
  placeholder?: string;
  required?: boolean;
  /** Minimum value for type="number" inputs (HTML min attribute). */
  min?: number;
  /** Maximum value for type="number" inputs (HTML max attribute). */
  max?: number;
}

export function Field({
  id,
  label,
  value,
  onChange,
  type = 'text',
  placeholder,
  required = false,
  min,
  max,
}: FieldProps): JSX.Element {
  if (type === 'switch') {
    return (
      <div className="form__field">
        <label className="form__label form__label--switch" htmlFor={id}>
          <input
            id={id}
            className="form__switch"
            type="checkbox"
            checked={value === 'true' || value === '1'}
            onChange={(e) => onChange(e.target.checked ? 'true' : 'false')}
          />
          {' '}{label}
          {required ? <span aria-hidden="true"> *</span> : null}
        </label>
      </div>
    );
  }

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
        min={min}
        max={max}
        onChange={(e) => onChange(e.target.value)}
      />
    </div>
  );
}
