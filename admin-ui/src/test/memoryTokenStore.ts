/**
 * In-memory {@link TokenStore} for tests — no `localStorage` dependency.
 */
import type { TokenStore } from '../api/tokenStore';

export class MemoryTokenStore implements TokenStore {
  private access: string | null;
  private refresh: string | null;
  private user: unknown | null;

  constructor(init: { access?: string; refresh?: string; user?: unknown } = {}) {
    this.access = init.access ?? null;
    this.refresh = init.refresh ?? null;
    this.user = init.user ?? null;
  }

  getAccessToken(): string | null {
    return this.access;
  }

  setAccessToken(token: string): void {
    this.access = token;
  }

  getRefreshToken(): string | null {
    return this.refresh;
  }

  setRefreshToken(token: string): void {
    this.refresh = token;
  }

  getUser(): unknown | null {
    return this.user;
  }

  setUser(user: unknown): void {
    this.user = user;
  }

  clear(): void {
    this.access = null;
    this.refresh = null;
    this.user = null;
  }
}

/**
 * Build a `fetch`-compatible mock from an ordered list of responses, each
 * described by status + body. Records the requests it received so tests can
 * assert on headers/URLs.
 */
export interface RecordedRequest {
  url: string;
  init: RequestInit | undefined;
}

export function makeFetch(
  responses: Array<{ status: number; body: unknown; json?: boolean }>,
): { fetch: typeof fetch; calls: RecordedRequest[] } {
  const calls: RecordedRequest[] = [];
  let i = 0;
  const fetchImpl = (async (url: string | URL, init?: RequestInit) => {
    calls.push({ url: String(url), init });
    const spec = responses[i] ?? responses[responses.length - 1];
    if (spec === undefined) {
      throw new Error('makeFetch: no responses configured');
    }
    i += 1;
    const isJson = spec.json ?? true;
    return {
      ok: spec.status >= 200 && spec.status < 300,
      status: spec.status,
      headers: {
        get: (name: string) =>
          name.toLowerCase() === 'content-type'
            ? isJson
              ? 'application/json'
              : 'text/plain'
            : null,
      },
      json: async () => spec.body,
      text: async () => String(spec.body),
    } as unknown as Response;
  }) as unknown as typeof fetch;
  return { fetch: fetchImpl, calls };
}
