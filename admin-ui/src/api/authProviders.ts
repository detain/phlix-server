/**
 * AuthProvidersApi — typed wrappers over the OIDC + LDAP auth provider admin endpoints.
 *
 * @since 1.4b
 */
import type { ApiClient } from './client';

/**
 * A registered auth provider as returned by `GET /api/v1/admin/auth-providers`.
 *
 * @since 1.4b
 */
export interface AuthProvider {
  name: string;
  supports_authentication: boolean;
}

/**
 * Result of enabling a provider.
 *
 * @since 1.4b
 */
export interface EnableProviderResult {
  name: string;
  enabled: true;
  message: string;
}

/**
 * Result of disabling a provider.
 *
 * @since 1.4b
 */
export interface DisableProviderResult {
  name: string;
  enabled: false;
  message: string;
}

/**
 * OIDC settings shape from `GET /api/v1/admin/auth-providers/oidc/config`.
 *
 * @since 1.4b
 */
export interface OidcSettings {
  provider_url: string;
  client_id: string;
  scopes: string;
  configured: boolean;
}

/**
 * Input for saving OIDC settings.
 *
 * @since 1.4b
 */
export interface SaveOidcInput {
  provider_url: string;
  client_id: string;
  /** Optional — omit to keep existing secret server-side. */
  client_secret?: string;
  scopes: string;
}

/**
 * LDAP settings shape from `GET /api/v1/admin/auth-providers/ldap/config`.
 *
 * @since 1.4b
 */
export interface LdapSettings {
  host: string;
  port: number;
  ssl: boolean;
  base_dn: string;
  bind_dn: string;
  user_filter: string;
  admin_group: string;
  configured: boolean;
}

/**
 * Input for saving LDAP settings.
 *
 * @since 1.4b
 */
export interface SaveLdapInput {
  host: string;
  port: number;
  ssl: boolean;
  base_dn: string;
  bind_dn: string;
  /** Optional — omit to keep existing password server-side. */
  bind_pw?: string;
  user_filter: string;
  admin_group: string;
}

/**
 * Result of testing LDAP connection.
 *
 * @since 1.4b
 */
export interface LdapTestResult {
  success: boolean;
  message: string;
}

/**
 * Generic auth providers list wrapper.
 *
 * @since 1.4b
 */
export class AuthProvidersApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/admin/auth-providers` → `{ providers }`.
   */
  async listProviders(): Promise<AuthProvider[]> {
    const { providers } = await this.client.get<{ providers: AuthProvider[] }>(
      '/api/v1/admin/auth-providers',
    );
    return providers;
  }

  /**
   * `POST /api/v1/admin/auth-providers/{name}/enable` → `{ name, enabled: true, message }`.
   */
  async enableProvider(name: string): Promise<EnableProviderResult> {
    return this.client.post<EnableProviderResult>(
      `/api/v1/admin/auth-providers/${encodeURIComponent(name)}/enable`,
    );
  }

  /**
   * `POST /api/v1/admin/auth-providers/{name}/disable` → `{ name, enabled: false, message }`.
   */
  async disableProvider(name: string): Promise<DisableProviderResult> {
    return this.client.post<DisableProviderResult>(
      `/api/v1/admin/auth-providers/${encodeURIComponent(name)}/disable`,
    );
  }
}

/**
 * OIDC-specific settings wrapper.
 *
 * @since 1.4b
 */
export class OidcApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/admin/auth-providers/oidc/config` → OidcSettings.
   */
  async getSettings(): Promise<OidcSettings> {
    return this.client.get<OidcSettings>('/api/v1/admin/auth-providers/oidc/config');
  }

  /**
   * `POST /api/v1/admin/auth-providers/oidc/config` → 200 | 400.
   */
  async saveSettings(input: SaveOidcInput): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(
      '/api/v1/admin/auth-providers/oidc/config',
      input,
    );
  }

  /**
   * `GET /api/v1/admin/auth-providers/oidc/schema` → `{ schema }`.
   */
  async getSchema(): Promise<{ schema: Record<string, unknown> }> {
    return this.client.get<{ schema: Record<string, unknown> }>(
      '/api/v1/admin/auth-providers/oidc/schema',
    );
  }
}

/**
 * LDAP-specific settings wrapper.
 *
 * @since 1.4b
 */
export class LdapApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/admin/auth-providers/ldap/config` → LdapSettings.
   */
  async getSettings(): Promise<LdapSettings> {
    return this.client.get<LdapSettings>('/api/v1/admin/auth-providers/ldap/config');
  }

  /**
   * `POST /api/v1/admin/auth-providers/ldap/config` → 200 | 400.
   */
  async saveSettings(input: SaveLdapInput): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(
      '/api/v1/admin/auth-providers/ldap/config',
      input,
    );
  }

  /**
   * `POST /api/v1/admin/auth-providers/ldap/test` → `{ success, message }` | 400.
   */
  async testConnection(input: SaveLdapInput): Promise<LdapTestResult> {
    return this.client.post<LdapTestResult>(
      '/api/v1/admin/auth-providers/ldap/test',
      input,
    );
  }

  /**
   * `GET /api/v1/admin/auth-providers/ldap/schema` → `{ schema }`.
   */
  async getSchema(): Promise<{ schema: Record<string, unknown> }> {
    return this.client.get<{ schema: Record<string, unknown> }>(
      '/api/v1/admin/auth-providers/ldap/schema',
    );
  }
}
