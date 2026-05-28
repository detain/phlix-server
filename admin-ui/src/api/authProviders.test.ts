import { describe, expect, it } from 'vitest';
import { ApiClient } from './client';
import { AuthProvidersApi, OidcApi, LdapApi } from './authProviders';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeAuthProvidersApi(responses: Array<{ status: number; body: unknown }>): {
  api: AuthProvidersApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new AuthProvidersApi(client), calls };
}

function makeOidcApi(responses: Array<{ status: number; body: unknown }>): {
  api: OidcApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new OidcApi(client), calls };
}

function makeLdapApi(responses: Array<{ status: number; body: unknown }>): {
  api: LdapApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new LdapApi(client), calls };
}

describe('AuthProvidersApi', () => {
  describe('listProviders()', () => {
    it('GETs /api/v1/admin/auth-providers and unwraps { providers }', async () => {
      const { api, calls } = makeAuthProvidersApi([
        {
          status: 200,
          body: {
            providers: [
              { name: 'oidc', supports_authentication: true },
              { name: 'ldap', supports_authentication: true },
            ],
          },
        },
      ]);

      const result = await api.listProviders();

      expect(calls[0]!.url).toBe('/api/v1/admin/auth-providers');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toHaveLength(2);
      expect(result[0]!.name).toBe('oidc');
    });
  });

  describe('enableProvider()', () => {
    it('POSTs /api/v1/admin/auth-providers/{name}/enable', async () => {
      const { api, calls } = makeAuthProvidersApi([
        { status: 200, body: { name: 'oidc', enabled: true, message: 'OIDC enabled' } },
      ]);

      const result = await api.enableProvider('oidc');

      expect(calls[0]!.url).toBe('/api/v1/admin/auth-providers/oidc/enable');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.name).toBe('oidc');
      expect(result.enabled).toBe(true);
    });
  });

  describe('disableProvider()', () => {
    it('POSTs /api/v1/admin/auth-providers/{name}/disable', async () => {
      const { api, calls } = makeAuthProvidersApi([
        { status: 200, body: { name: 'ldap', enabled: false, message: 'LDAP disabled' } },
      ]);

      const result = await api.disableProvider('ldap');

      expect(calls[0]!.url).toBe('/api/v1/admin/auth-providers/ldap/disable');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.name).toBe('ldap');
      expect(result.enabled).toBe(false);
    });
  });
});

describe('OidcApi', () => {
  describe('getSettings()', () => {
    it('GETs /api/v1/admin/auth-providers/oidc/config', async () => {
      const { api, calls } = makeOidcApi([
        {
          status: 200,
          body: {
            provider_url: 'https://idp.example.com',
            client_id: 'client-123',
            scopes: 'openid profile email',
            configured: true,
          },
        },
      ]);

      const result = await api.getSettings();

      expect(calls[0]!.url).toBe('/api/v1/admin/auth-providers/oidc/config');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result.provider_url).toBe('https://idp.example.com');
      expect(result.configured).toBe(true);
    });
  });

  describe('saveSettings()', () => {
    it('POSTs /api/v1/admin/auth-providers/oidc/config with correct body', async () => {
      const { api, calls } = makeOidcApi([
        { status: 200, body: { message: 'OIDC settings saved' } },
      ]);

      const result = await api.saveSettings({
        provider_url: 'https://idp.example.com',
        client_id: 'client-123',
        client_secret: 'secret-value',
        scopes: 'openid profile',
      });

      expect(calls[0]!.url).toBe('/api/v1/admin/auth-providers/oidc/config');
      expect(calls[0]!.init!.method).toBe('POST');
      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body).toEqual({
        provider_url: 'https://idp.example.com',
        client_id: 'client-123',
        client_secret: 'secret-value',
        scopes: 'openid profile',
      });
      expect(result.message).toBe('OIDC settings saved');
    });

    it('omits client_secret when not provided', async () => {
      const { api, calls } = makeOidcApi([
        { status: 200, body: { message: 'Saved' } },
      ]);

      await api.saveSettings({
        provider_url: 'https://idp.example.com',
        client_id: 'client-123',
        scopes: 'openid',
      });

      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body).not.toHaveProperty('client_secret');
    });

    it('throws ApiError on 400', async () => {
      const { api } = makeOidcApi([
        { status: 400, body: { error: 'Invalid provider URL' } },
      ]);

      await expect(
        api.saveSettings({ provider_url: '', client_id: '', scopes: '' }),
      ).rejects.toThrow('Invalid provider URL');
    });
  });

  describe('getSchema()', () => {
    it('GETs /api/v1/admin/auth-providers/oidc/schema', async () => {
      const { api, calls } = makeOidcApi([
        { status: 200, body: { schema: { type: 'object', properties: {} } } },
      ]);

      await api.getSchema();

      expect(calls[0]!.url).toBe('/api/v1/admin/auth-providers/oidc/schema');
      expect(calls[0]!.init!.method).toBe('GET');
    });
  });
});

describe('LdapApi', () => {
  describe('getSettings()', () => {
    it('GETs /api/v1/admin/auth-providers/ldap/config', async () => {
      const { api, calls } = makeLdapApi([
        {
          status: 200,
          body: {
            host: 'ldap.example.com',
            port: 636,
            ssl: true,
            base_dn: 'dc=example,dc=com',
            bind_dn: 'cn=admin,dc=example,dc=com',
            user_filter: '(uid=%s)',
            admin_group: 'cn=admins,dc=example,dc=com',
            configured: true,
          },
        },
      ]);

      const result = await api.getSettings();

      expect(calls[0]!.url).toBe('/api/v1/admin/auth-providers/ldap/config');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result.host).toBe('ldap.example.com');
      expect(result.port).toBe(636);
      expect(result.ssl).toBe(true);
    });
  });

  describe('saveSettings()', () => {
    it('POSTs /api/v1/admin/auth-providers/ldap/config with correct body', async () => {
      const { api, calls } = makeLdapApi([
        { status: 200, body: { message: 'LDAP settings saved' } },
      ]);

      const result = await api.saveSettings({
        host: 'ldap.example.com',
        port: 636,
        ssl: true,
        base_dn: 'dc=example,dc=com',
        bind_dn: 'cn=admin,dc=example,dc=com',
        bind_pw: 'secret',
        user_filter: '(uid=%s)',
        admin_group: 'cn=admins,dc=example,dc=com',
      });

      expect(calls[0]!.url).toBe('/api/v1/admin/auth-providers/ldap/config');
      expect(calls[0]!.init!.method).toBe('POST');
      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body.host).toBe('ldap.example.com');
      expect(body.bind_pw).toBe('secret');
      expect(result.message).toBe('LDAP settings saved');
    });

    it('omits bind_pw when not provided', async () => {
      const { api, calls } = makeLdapApi([
        { status: 200, body: { message: 'Saved' } },
      ]);

      await api.saveSettings({
        host: 'ldap.example.com',
        port: 389,
        ssl: false,
        base_dn: 'dc=example,dc=com',
        bind_dn: 'cn=admin,dc=example,dc=com',
        user_filter: '(uid=%s)',
        admin_group: '',
      });

      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body).not.toHaveProperty('bind_pw');
    });
  });

  describe('testConnection()', () => {
    it('POSTs /api/v1/admin/auth-providers/ldap/test with current form values', async () => {
      const { api, calls } = makeLdapApi([
        { status: 200, body: { success: true, message: 'Connection OK' } },
      ]);

      const result = await api.testConnection({
        host: 'ldap.example.com',
        port: 636,
        ssl: true,
        base_dn: 'dc=example,dc=com',
        bind_dn: 'cn=admin,dc=example,dc=com',
        bind_pw: 'secret',
        user_filter: '(uid=%s)',
        admin_group: 'cn=admins,dc=example,dc=com',
      });

      expect(calls[0]!.url).toBe('/api/v1/admin/auth-providers/ldap/test');
      expect(calls[0]!.init!.method).toBe('POST');
      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body.host).toBe('ldap.example.com');
      expect(result.success).toBe(true);
      expect(result.message).toBe('Connection OK');
    });

    it('returns failure result when connection fails', async () => {
      const { api } = makeLdapApi([
        { status: 200, body: { success: false, message: 'Connection refused' } },
      ]);

      const result = await api.testConnection({
        host: 'ldap.example.com',
        port: 636,
        ssl: true,
        base_dn: 'dc=example,dc=com',
        bind_dn: 'cn=admin,dc=example,dc=com',
        user_filter: '(uid=%s)',
        admin_group: '',
      });

      expect(result.success).toBe(false);
      expect(result.message).toBe('Connection refused');
    });

    it('throws ApiError on 400', async () => {
      const { api } = makeLdapApi([
        { status: 400, body: { error: 'Invalid LDAP configuration' } },
      ]);

      await expect(
        api.testConnection({
          host: '',
          port: 0,
          ssl: false,
          base_dn: '',
          bind_dn: '',
          user_filter: '',
          admin_group: '',
        }),
      ).rejects.toThrow('Invalid LDAP configuration');
    });
  });

  describe('getSchema()', () => {
    it('GETs /api/v1/admin/auth-providers/ldap/schema', async () => {
      const { api, calls } = makeLdapApi([
        { status: 200, body: { schema: { type: 'object', properties: {} } } },
      ]);

      await api.getSchema();

      expect(calls[0]!.url).toBe('/api/v1/admin/auth-providers/ldap/schema');
      expect(calls[0]!.init!.method).toBe('GET');
    });
  });
});
