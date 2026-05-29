import { describe, expect, it } from 'vitest';
import { RemoteAccessApi } from './remoteAccess';
import { ApiClient } from './client';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

function makeApiClient(responses: Array<{ status: number; body: unknown }>): {
  api: RemoteAccessApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new RemoteAccessApi(client), calls };
}

describe('RemoteAccessApi', () => {
  // ─── Hub pairing tests ────────────────────────────────────────────────────────

  describe('hubStatus()', () => {
    it('issues GET /api/v1/admin/remote/hub/status and returns paired false', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { paired: false } },
      ]);

      const result = await api.hubStatus();

      expect(calls[0]!.init?.method).toBe('GET');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/hub/status');
      expect(result.paired).toBe(false);
    });

    it('returns full hub status when paired', async () => {
      const { api } = makeApiClient([
        {
          status: 200,
          body: {
            paired: true,
            serverId: 'srv-123',
            hubUrl: 'https://hub.example.com',
            enrolledAt: '2024-01-15T10:00:00+00:00',
          },
        },
      ]);

      const result = await api.hubStatus();

      expect(result.paired).toBe(true);
      expect(result.serverId).toBe('srv-123');
      expect(result.hubUrl).toBe('https://hub.example.com');
    });
  });

  describe('hubPair()', () => {
    it('issues POST /api/v1/admin/remote/hub/pair with body', async () => {
      const { api, calls } = makeApiClient([
        {
          status: 200,
          body: {
            success: true,
            claimCode: 'CODE123',
            claimId: 'id-456',
            serverId: '',
            hubUrl: 'https://hub.example.com',
          },
        },
      ]);

      const result = await api.hubPair('https://hub.example.com', 'Test Server');

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/hub/pair');
      const reqBody = JSON.parse(calls[0]!.init!.body as string);
      expect(reqBody).toEqual({
        hubUrl: 'https://hub.example.com',
        serverName: 'Test Server',
      });
      expect(result.claimCode).toBe('CODE123');
    });
  });

  describe('hubPoll()', () => {
    it('issues POST /api/v1/admin/remote/hub/poll', async () => {
      const { api, calls } = makeApiClient([
        {
          status: 200,
          body: { success: false, message: 'Claim is still pending.' },
        },
      ]);

      await api.hubPoll('claim-123', 'https://hub.example.com');

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/hub/poll');
      const reqBody = JSON.parse(calls[0]!.init!.body as string);
      expect(reqBody).toEqual({
        claimId: 'claim-123',
        hubUrl: 'https://hub.example.com',
      });
    });

    it('returns token when claim is completed', async () => {
      const { api } = makeApiClient([
        {
          status: 200,
          body: {
            success: true,
            token: 'jwt-token-abc',
            serverId: 'srv-456',
          },
        },
      ]);

      const result = await api.hubPoll('claim-123', 'https://hub.example.com');

      expect(result.success).toBe(true);
      expect(result.token).toBe('jwt-token-abc');
    });
  });

  describe('hubComplete()', () => {
    it('issues POST /api/v1/admin/remote/hub/complete', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.hubComplete(
        'jwt-token',
        'https://hub.example.com/.well-known/jwks.json',
        'srv-123',
        'https://hub.example.com',
      );

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/hub/complete');
      const reqBody = JSON.parse(calls[0]!.init!.body as string);
      expect(reqBody).toEqual({
        enrollmentJwt: 'jwt-token',
        hubJwksUrl: 'https://hub.example.com/.well-known/jwks.json',
        serverId: 'srv-123',
        hubUrl: 'https://hub.example.com',
      });
      expect(result.success).toBe(true);
    });
  });

  describe('hubUnenroll()', () => {
    it('issues POST /api/v1/admin/remote/hub/unenroll', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.hubUnenroll();

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/hub/unenroll');
      expect(result.success).toBe(true);
    });
  });

  describe('hubHeartbeat()', () => {
    it('issues POST /api/v1/admin/remote/hub/heartbeat', async () => {
      const { api, calls } = makeApiClient([
        {
          status: 200,
          body: { success: true, receivedAt: '2024-01-15T10:05:00+00:00' },
        },
      ]);

      const result = await api.hubHeartbeat();

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/hub/heartbeat');
      expect(result.success).toBe(true);
    });
  });

  // ─── Subdomain tests ─────────────────────────────────────────────────────────

  describe('subdomainStatus()', () => {
    it('issues GET /api/v1/admin/remote/subdomain/status', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { claimed: false } },
      ]);

      const result = await api.subdomainStatus();

      expect(calls[0]!.init?.method).toBe('GET');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/subdomain/status');
      expect(result.claimed).toBe(false);
    });

    it('returns claimed subdomain details', async () => {
      const { api } = makeApiClient([
        {
          status: 200,
          body: {
            claimed: true,
            subdomain: 'myserver',
            fqdn: 'myserver.hub.example.com',
          },
        },
      ]);

      const result = await api.subdomainStatus();

      expect(result.claimed).toBe(true);
      expect(result.subdomain).toBe('myserver');
      expect(result.fqdn).toBe('myserver.hub.example.com');
    });
  });

  describe('subdomainClaim()', () => {
    it('issues POST /api/v1/admin/remote/subdomain/claim', async () => {
      const { api, calls } = makeApiClient([
        {
          status: 200,
          body: { success: true, subdomain: 'myserver', fqdn: 'myserver.hub.example.com' },
        },
      ]);

      const result = await api.subdomainClaim();

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/subdomain/claim');
      expect(result.subdomain).toBe('myserver');
    });
  });

  describe('subdomainRelease()', () => {
    it('issues POST /api/v1/admin/remote/subdomain/release', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.subdomainRelease();

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/subdomain/release');
      expect(result.success).toBe(true);
    });
  });

  // ─── Relay tunnel tests ────────────────────────────────────────────────────

  describe('relayStatus()', () => {
    it('issues GET /api/v1/admin/remote/relay/status', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { connected: false, active: false } },
      ]);

      const result = await api.relayStatus();

      expect(calls[0]!.init?.method).toBe('GET');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/relay/status');
      expect(result.connected).toBe(false);
      expect(result.active).toBe(false);
    });

    it('returns connected and active status', async () => {
      const { api } = makeApiClient([
        { status: 200, body: { connected: true, active: true } },
      ]);

      const result = await api.relayStatus();

      expect(result.connected).toBe(true);
      expect(result.active).toBe(true);
    });
  });

  describe('relayEnable()', () => {
    it('issues POST /api/v1/admin/remote/relay/enable', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.relayEnable();

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/relay/enable');
      expect(result.success).toBe(true);
    });
  });

  describe('relayDisable()', () => {
    it('issues POST /api/v1/admin/remote/relay/disable', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.relayDisable();

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/relay/disable');
      expect(result.success).toBe(true);
    });
  });

  describe('relayPing()', () => {
    it('issues POST /api/v1/admin/remote/relay/ping', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true, latencyMs: 42 } },
      ]);

      const result = await api.relayPing();

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/relay/ping');
      expect(result.latencyMs).toBe(42);
    });
  });

  // ─── Port forward tests ───────────────────────────────────────────────────

  describe('portForwardStatus()', () => {
    it('issues GET /api/v1/admin/remote/portforward/status', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { enabled: false } },
      ]);

      const result = await api.portForwardStatus();

      expect(calls[0]!.init?.method).toBe('GET');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/portforward/status');
      expect(result.enabled).toBe(false);
    });

    it('returns full port-forward status', async () => {
      const { api } = makeApiClient([
        {
          status: 200,
          body: {
            enabled: true,
            method: 'upnp',
            externalIp: '203.0.113.50',
            externalPort: 32400,
            hostname: '203.0.113.50:32400',
          },
        },
      ]);

      const result = await api.portForwardStatus();

      expect(result.enabled).toBe(true);
      expect(result.method).toBe('upnp');
      expect(result.externalIp).toBe('203.0.113.50');
    });
  });

  describe('portForwardEnable()', () => {
    it('issues POST /api/v1/admin/remote/portforward/enable', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.portForwardEnable();

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/portforward/enable');
      expect(result.success).toBe(true);
    });
  });

  describe('portForwardDisable()', () => {
    it('issues POST /api/v1/admin/remote/portforward/disable', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.portForwardDisable();

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/portforward/disable');
      expect(result.success).toBe(true);
    });
  });

  describe('portForwardCandidates()', () => {
    it('issues GET /api/v1/admin/remote/portforward/candidates', async () => {
      const { api, calls } = makeApiClient([
        {
          status: 200,
          body: {
            candidates: [
              { hostname: 'http://192.168.1.100:32400', externalIp: '192.168.1.100', port: 32400 },
            ],
          },
        },
      ]);

      const result = await api.portForwardCandidates();

      expect(calls[0]!.init?.method).toBe('GET');
      expect(calls[0]!.url).toContain('/api/v1/admin/remote/portforward/candidates');
      expect(result.candidates.length).toBe(1);
      expect(result.candidates[0]!.hostname).toBe('http://192.168.1.100:32400');
    });
  });
});
