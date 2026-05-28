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
 *
 * URL-Based Routing: Responses are selected based on the URL pattern of the
 * incoming request, not a sequential counter. This fixes issues with React
 * StrictMode double-invoking effects, where parallel fetch calls would cause
 * the counter to get out of sync.
 *
 * Each response entry can optionally specify a `urlMatch` string (substring to
 * match against the URL). If provided, only URLs containing that string will
 * use that response entry. If not provided, the response is considered a
 * "fallback" for URLs that don't match any specific urlMatch.
 *
 * Each unique urlMatch (or the empty string fallback) maintains its own counter,
 * so responses are returned round-robin per endpoint type.
 */
export interface RecordedRequest {
  url: string;
  init: RequestInit | undefined;
}

/** Extended response spec with optional URL pattern matcher */
export interface ResponseSpec {
  status: number;
  body: unknown;
  json?: boolean;
  /** Substring to match against the URL. If provided, this response only
   *  matches URLs containing this string. */
  urlMatch?: string;
}

export function makeFetch(
  responses: ResponseSpec[],
): { fetch: typeof fetch; calls: RecordedRequest[] } {
  const calls: RecordedRequest[] = [];
  // Per-URL-pattern counter for round-robin selection
  const urlCounters: Record<string, number> = {};

  const fetchImpl = (async (url: string | URL, init?: RequestInit) => {
    const urlStr = String(url);
    calls.push({ url: urlStr, init });

    // Determine which urlMatch this URL corresponds to
    // If any response has a urlMatch that the URL contains, use the first match.
    // Otherwise, treat it as a "fallback" (empty string) call.
    let matchedUrlPattern = '';
    for (const spec of responses) {
      const match = spec.urlMatch ?? '';
      if (match !== '' && urlStr.includes(match)) {
        matchedUrlPattern = match;
        break;
      }
    }

    // Get and increment counter for this URL pattern
    const counter = urlCounters[matchedUrlPattern] ?? 0;
    urlCounters[matchedUrlPattern] = counter + 1;

    // Find all responses that match this urlPattern (including fallbacks)
    const matchingResponses = responses.filter(
      (spec) => (spec.urlMatch ?? '') === matchedUrlPattern,
    );

    // If no specific matches but we have fallback responses, use them
    // (This maintains backward compatibility with tests that don't use urlMatch)
    const specsToUse =
      matchingResponses.length > 0
        ? matchingResponses
        : responses.filter((spec) => (spec.urlMatch ?? '') === '');

    if (specsToUse.length === 0) {
      throw new Error(`makeFetch: no responses configured for URL: ${urlStr}`);
    }

    const spec = specsToUse[counter % specsToUse.length]!;
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
