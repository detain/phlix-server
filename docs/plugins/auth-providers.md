# Auth Provider Plugins

Phlex supports external authentication providers via a plugin-based
architecture. This allows integration with OIDC providers (Keycloak, Authelia,
Authentik, Google, GitHub), LDAP directories, SAML IdPs, and passkey
services without modifying the core authentication system.

## Overview

Auth provider plugins implement the `Phlex\Shared\Auth\ProviderInterface`
contract. Each provider handles its own credential validation, token
exchange, and user info retrieval. The core `AuthManager` coordinates
the authentication flow and issues local session tokens.

## ProviderInterface

```php
interface ProviderInterface
{
    public function name(): string;
    public function supportsAuthentication(array $credentials): bool;
    public function authenticate(array $credentials): AuthResult;
    public function getUserInfo(string $externalId): ?UserInfo;
    public function linkAccount(string $localUserId, array $externalIds): void;
}
```

### Methods

- **`name()`** — Returns a unique lowercase identifier (e.g., `"oidc"`,
  `"ldap"`, `"saml"`). Used as the prefix when parsing provider-prefixed
  usernames (`"oidc:alice@provider.com"`).

- **`supportsAuthentication($credentials)`** — Returns `true` when the
  provider can handle the given credentials. Used for provider
  discovery before delegation.

- **`authenticate($credentials)`** — The main entry point. Receives a
  provider-specific credential bag and returns an `AuthResult`. The
  implementation handles all I/O (HTTP calls, token validation, etc.).

- **`getUserInfo($externalId)`** — Looks up user information by the
  provider's external identifier. Used for account linking.

- **`linkAccount($localUserId, $externalIds)`** — Called when an existing
  local user connects their account to this provider.

### AuthResult

Returned by `authenticate()` on success or failure:

```php
new AuthResult(
    success:    true,
    userId:     '550e8400-e29b-41d4-a716-446655440000',  // local UUID
    externalId: 'oidc.12345',                           // provider-specific ID
    attributes: [
        'email'    => 'alice@example.com',
        'name'     => 'Alice',
        'avatarUrl' => 'https://...',
    ],
);
```

## OIDC Provider Plugin (`phlex-plugin-oidc`)

The OIDC plugin supports any OIDC-compliant identity provider using the
Authorization Code flow with PKCE.

### Features

- Authorization Code flow with RS256/RS384/RS512 signature validation
- Discovery document caching for 24 hours (JWKS cached alongside)
- CSRF protection via state parameter encoding
- Account linking: creates local user on first login, links on subsequent
- Settings UI: Provider URL, Client ID, Client Secret, Scopes

### Configuration

1. Install `phlex-plugin-oidc` via the admin UI (Plugins → Install from URL)
2. Navigate to **Admin → Auth Providers → OIDC**
3. Configure:
   - **Provider URL**: Base URL of your OIDC provider (e.g., `https://keycloak.example.com`)
   - **Client ID**: Registered client ID with the provider
   - **Client Secret**: Registered client secret
   - **Scopes**: Space-separated scopes (default: `openid profile email`)
4. Save settings

### Callback URLs

Register the following callback URL with your OIDC provider:

```
https://your-phlex-server/auth/oidc/callback
```

### Keycloak Configuration

1. Create a new Client in Keycloak with:
   - Client ID: `phlex`
   - Client Protocol: `openid-connect`
   - Access Type: `confidential`
   - Valid Redirect URIs: `https://your-phlex-server/auth/oidc/callback`
2. Under Credentials, copy the Client Secret
3. In Phlex admin:
   - Provider URL: `https://keycloak.example.com/realms/your-realm`
   - Client ID: `phlex`
   - Client Secret: (from step 2)

### Authelia / Authentik Configuration

Both use the same OIDC protocol. In Authelia:

```yaml
identity_providers:
  oidc:
    clients:
      - id: phlex
        description: Phlex Media Server
        secret: your-client-secret
        redirect_uris:
          - https://your-phlex-server/auth/oidc/callback
        scopes:
          - openid
          - profile
          - email
```

Provider URL would be: `https://your-authelia.example.com`

### Google Workspace / Gmail OAuth

1. Create a project in Google Cloud Console
2. Enable the Google+ API
3. Create OAuth 2.0 credentials (Web application type)
4. Add redirect URI: `https://your-phlex-server/auth/oidc/callback`
5. In Phlex admin:
   - Provider URL: `https://accounts.google.com`
   - Client ID: (from Google Console)
   - Client Secret: (from Google Console)

### GitHub OAuth App

1. Create a new OAuth App in GitHub Settings
2. Homepage URL: `https://your-phlex-server`
3. Authorization callback URL: `https://your-phlex-server/auth/oidc/callback`
4. In Phlex admin:
   - Provider URL: `https://github.com`
   - Client ID: (from GitHub)
   - Client Secret: (from GitHub)

Note: GitHub is not a true OIDC provider but supports OAuth 2.0. The plugin
will extract basic profile information from the `/userinfo` endpoint.

## Security Considerations

### Token Validation

All ID tokens are validated:
- Signature verified against provider's JWKS (RS256/RS384/RS512)
- `iss` claim must match the discovered issuer
- `aud` claim must contain the configured client ID
- `exp` claim must not be in the past
- `nonce` claim must match the value sent in the authorization request

### State Parameter

The `state` parameter in the authorization request encodes:
- The original `redirect_uri` for the callback
- A CSRF nonce

The callback verifies the state before processing the authorization code.

### Account Creation

On first login via OIDC, a local user account is automatically created
with:
- `external_id` = `oidc.{provider_sub}`
- `password_hash` = `NULL` (cannot log in with password)
- Profile info synced from the ID token claims

On subsequent logins, the existing local account is looked up by
`external_id` and updated if needed.

## See Also

- [Plugin Developer Guide](./developer-guide.md)
- [Plugin Manifest](./manifest.md)
- [Plugin Installation](./install-from-url.md)
- [Plugin Installation from Catalog](./install-from-catalog.md)
