import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { PathPicker } from './PathPicker';
import { ApiClient } from '../api/client';
import { FilesystemApi } from '../api/filesystem';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** A real FilesystemApi backed by ordered, real-shaped fs/browse responses. */
function makeFs(responses: Array<{ status: number; body: unknown }>): {
  fs: FilesystemApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { fs: new FilesystemApi(client), calls };
}

function env(path: string | null, parent: string | null, entries: Array<{ name: string; path: string }>) {
  return { status: 200, body: { success: true, data: { path, parent, entries } } };
}

/** Controlled harness so we can observe/seed the selected-path list. */
function Harness({
  fs,
  initial = [],
}: {
  fs: FilesystemApi;
  initial?: string[];
}): JSX.Element {
  const [selected, setSelected] = useState<string[]>(initial);
  return <PathPicker fs={fs} selected={selected} onChange={setSelected} />;
}

describe('PathPicker', () => {
  it('renders the roots listing on mount', async () => {
    const { fs } = makeFs([env(null, null, [{ name: 'media', path: '/media' }])]);
    render(<Harness fs={fs} />);

    expect(await screen.findByRole('button', { name: 'media' })).toBeInTheDocument();
    expect(screen.getByTestId('path-picker-current')).toHaveTextContent('(roots)');
  });

  it('drills into a subdir and back up to the parent', async () => {
    const user = userEvent.setup();
    const { fs, calls } = makeFs([
      env(null, null, [{ name: 'media', path: '/media' }]),
      env('/media', null, [{ name: 'movies', path: '/media/movies' }]),
      env('/media/movies', '/media', []),
      env('/media', null, [{ name: 'movies', path: '/media/movies' }]),
    ]);
    render(<Harness fs={fs} />);

    await user.click(await screen.findByRole('button', { name: 'media' }));
    await user.click(await screen.findByRole('button', { name: 'movies' }));
    await waitFor(() =>
      expect(screen.getByTestId('path-picker-current')).toHaveTextContent('/media/movies'),
    );
    // No subfolders message at the leaf.
    expect(screen.getByText('No subfolders.')).toBeInTheDocument();

    // "Up" goes back to /media (the parent).
    await user.click(screen.getByRole('button', { name: 'Up' }));
    await waitFor(() =>
      expect(screen.getByTestId('path-picker-current')).toHaveTextContent('/media'),
    );
    expect(calls[3]!.url).toContain('path=%2Fmedia');
  });

  it('selects the current folder and removes a selected path', async () => {
    const user = userEvent.setup();
    const { fs } = makeFs([
      env(null, null, [{ name: 'media', path: '/media' }]),
      env('/media', null, []),
    ]);
    render(<Harness fs={fs} />);

    await user.click(await screen.findByRole('button', { name: 'media' }));
    await waitFor(() =>
      expect(screen.getByTestId('path-picker-current')).toHaveTextContent('/media'),
    );

    await user.click(screen.getByRole('button', { name: 'Select this folder' }));
    // Selected list now contains /media.
    const selectedList = screen.getByRole('list', { name: 'Selected paths' });
    expect(selectedList).toHaveTextContent('/media');

    await user.click(screen.getByRole('button', { name: 'Remove /media' }));
    expect(screen.getByText('No paths selected yet.')).toBeInTheDocument();
  });

  it('renders an untrusted directory name as literal text (XSS guard)', async () => {
    const evil = '<img src=x onerror=alert(1)>';
    const { fs } = makeFs([env(null, null, [{ name: evil, path: '/evil' }])]);
    render(<Harness fs={fs} />);

    expect(await screen.findByRole('button', { name: evil })).toBeInTheDocument();
    // The literal string is shown; no <img> element was created.
    expect(document.querySelector('img')).toBeNull();
  });

  it('surfaces a browse error inline and via onError', async () => {
    const { fs } = makeFs([{ status: 403, body: { success: false, error: 'Outside roots' } }]);
    const onError = vi.fn();
    render(<PathPicker fs={fs} selected={[]} onChange={() => {}} onError={onError} />);

    expect(await screen.findByRole('alert')).toHaveTextContent('Outside roots');
    expect(onError).toHaveBeenCalledWith('Outside roots');
  });

  it('falls back to a generic message when the failure is not an ApiError', async () => {
    // Simulate a non-ApiError (e.g. a network failure before fetch resolves)
    // by injecting a fetch that throws a plain Error. The page renders the
    // generic fallback string from the `err instanceof ApiError ? ... : ...`
    // branch in PathPicker's catch.
    const throwingFetch: typeof fetch = (async () => {
      throw new Error('boom');
    }) as unknown as typeof fetch;
    const client = new ApiClient({
      baseUrl: '',
      tokenStore: new MemoryTokenStore({ access: 't' }),
      fetchImpl: throwingFetch,
    });
    const fs = new FilesystemApi(client);
    render(<PathPicker fs={fs} selected={[]} onChange={() => {}} />);
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Failed to browse the filesystem.',
    );
  });
});
