import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Modal } from './Modal';

describe('Modal', () => {
  it('renders nothing when closed', () => {
    const { container } = render(
      <Modal open={false} title="X" onClose={() => {}}>
        body
      </Modal>,
    );
    expect(container).toBeEmptyDOMElement();
  });

  it('renders title + body when open', () => {
    render(
      <Modal open title="Delete library" onClose={() => {}}>
        <p>Are you sure?</p>
      </Modal>,
    );
    expect(screen.getByRole('dialog', { name: 'Delete library' })).toBeInTheDocument();
    expect(screen.getByText('Are you sure?')).toBeInTheDocument();
  });

  it('closes on backdrop click but not on dialog click', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(
      <Modal open title="T" onClose={onClose}>
        <button type="button">inner</button>
      </Modal>,
    );
    await user.click(screen.getByRole('button', { name: 'inner' }));
    expect(onClose).not.toHaveBeenCalled();
    await user.click(screen.getByTestId('modal-backdrop'));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('closes on the X button', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(
      <Modal open title="T" onClose={onClose}>
        body
      </Modal>,
    );
    await user.click(screen.getByRole('button', { name: /close dialog/i }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('closes on Escape', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(
      <Modal open title="T" onClose={onClose}>
        body
      </Modal>,
    );
    await user.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
