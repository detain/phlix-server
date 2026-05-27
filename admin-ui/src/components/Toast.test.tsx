import { describe, expect, it } from 'vitest';
import { act, render, renderHook, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ToastProvider, useToast } from './Toast';
import type { ReactNode } from 'react';

function wrapper(timeoutMs = 0) {
  return ({ children }: { children: ReactNode }) => (
    <ToastProvider timeoutMs={timeoutMs}>{children}</ToastProvider>
  );
}

describe('Toast', () => {
  it('useToast throws outside a provider', () => {
    expect(() => renderHook(() => useToast())).toThrow(/within a <ToastProvider>/);
  });

  it('pushes and renders a toast with the chosen level', () => {
    const { result } = renderHook(() => useToast(), { wrapper: wrapper(0) });
    act(() => {
      result.current.push('Saved!', 'success');
    });
    expect(screen.getByText('Saved!')).toBeInTheDocument();
    expect(result.current.toasts).toHaveLength(1);
    expect(result.current.toasts[0]!.level).toBe('success');
  });

  it('renders error toasts as alerts and info/success as status', () => {
    const { result } = renderHook(() => useToast(), { wrapper: wrapper(0) });
    act(() => {
      result.current.push('bad', 'error');
    });
    expect(screen.getByRole('alert')).toHaveTextContent('bad');
    act(() => {
      result.current.push('fyi', 'info');
    });
    expect(screen.getAllByRole('status').length).toBeGreaterThanOrEqual(1);
  });

  it('dismisses a toast on the close button', async () => {
    const user = userEvent.setup();
    function Harness() {
      const { push } = useToast();
      return (
        <button type="button" onClick={() => push('dismiss me')}>
          add
        </button>
      );
    }
    render(
      <ToastProvider timeoutMs={0}>
        <Harness />
      </ToastProvider>,
    );
    await user.click(screen.getByRole('button', { name: 'add' }));
    expect(screen.getByText('dismiss me')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /dismiss notification/i }));
    expect(screen.queryByText('dismiss me')).not.toBeInTheDocument();
  });

  it('auto-dismisses after the timeout', async () => {
    const { result } = renderHook(() => useToast(), { wrapper: wrapper(20) });
    act(() => {
      result.current.push('temporary');
    });
    expect(result.current.toasts).toHaveLength(1);
    await act(() => new Promise((r) => setTimeout(r, 40)));
    expect(result.current.toasts).toHaveLength(0);
  });

  it('renders messages as text (no HTML injection)', () => {
    const { result } = renderHook(() => useToast(), { wrapper: wrapper(0) });
    act(() => {
      result.current.push('<img src=x onerror=alert(1)>');
    });
    // The literal string is shown; no <img> element is created.
    expect(screen.getByText('<img src=x onerror=alert(1)>')).toBeInTheDocument();
    expect(document.querySelector('img')).toBeNull();
  });
});
