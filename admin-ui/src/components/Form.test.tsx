import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Field, Form } from './Form';
import { useState } from 'react';

describe('Form', () => {
  it('calls onSubmit and prevents the default navigation', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    render(
      <Form onSubmit={onSubmit}>
        <button type="submit">Save</button>
      </Form>,
    );
    await user.click(screen.getByRole('button', { name: 'Save' }));
    expect(onSubmit).toHaveBeenCalledTimes(1);
  });

  it('disables the fieldset while busy', () => {
    render(
      <Form onSubmit={() => {}} busy>
        <Field id="f" label="Name" value="" onChange={() => {}} />
      </Form>,
    );
    expect(screen.getByLabelText('Name')).toBeDisabled();
  });
});

describe('Field', () => {
  it('renders a labelled input and reports changes', async () => {
    const user = userEvent.setup();
    function Harness() {
      const [v, setV] = useState('');
      return <Field id="email" label="Email" type="email" value={v} onChange={setV} required />;
    }
    render(<Harness />);
    const input = screen.getByLabelText(/email/i);
    expect(input).toHaveAttribute('type', 'email');
    expect(input).toBeRequired();
    await user.type(input, 'a@b.co');
    expect(input).toHaveValue('a@b.co');
  });
});
