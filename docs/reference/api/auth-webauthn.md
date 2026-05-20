# WebAuthn / Passkey API Reference

Base URL: `/api/v1`

All endpoints return JSON. Authentication requires a valid bearer token
(except where noted).

## Endpoints

### Start Passkey Registration

Begins the WebAuthn registration ceremony. Returns options for the browser's
`navigator.credentials.create()` call.

**Endpoint:** `POST /api/v1/auth/webauthn/register/options`

**Headers:**
- `Authorization: Bearer <token>` (required)
- `Content-Type: application/json`

**Request Body:**
```json
{}
```

**Response (200):**
```json
{
  "challenge": "base64-encoded-32-byte-challenge",
  "rp": {
    "id": "localhost",
    "name": "Phlix Media Server"
  },
  "user": {
    "id": "user-uuid",
    "name": "username",
    "displayName": "Username"
  },
  "pubKeyCredParams": [
    {"type": "public-key", "alg": 1},
    {"type": "public-key", "alg": 7}
  ],
  "timeout": 60000,
  "excludeCredentials": [],
  "authenticatorSelection": {
    "authenticatorAttachment": null,
    "residentKey": true,
    "userVerification": "preferred"
  },
  "attestation": "none"
}
```

---

### Complete Passkey Registration

Finishes the registration by verifying the attestation from the authenticator.

**Endpoint:** `POST /api/v1/auth/webauthn/register/verify`

**Headers:**
- `Authorization: Bearer <token>` (required)
- `Content-Type: application/json`

**Request Body:**
```json
{
  "credential": {
    "attestationObject": "base64-encoded-attestation-object",
    "clientDataJSON": "base64-encoded-client-data",
    "transports": ["usb", "nfc", "ble"]
  },
  "challenge": "base64-encoded-challenge-from-start"
}
```

**Response (200):**
```json
{
  "credential_id": "base64-encoded-credential-id",
  "message": "Passkey registered successfully"
}
```

**Error Response (400):**
```json
{
  "error": "Invalid or expired challenge"
}
```

---

### Start Passkey Login

Begins the authentication ceremony. Returns options for the browser's
`navigator.credentials.get()` call.

**Endpoint:** `POST /api/v1/auth/webauthn/login/options`

**Headers:**
- `Content-Type: application/json`

**Request Body:**
```json
{
  "username": "the_username"
}
```

**Response (200):**
```json
{
  "challenge": "base64-encoded-32-byte-challenge",
  "rpId": "localhost",
  "allowCredentials": [
    {
      "type": "public-key",
      "id": "base64-encoded-credential-id",
      "transports": ["usb", "nfc"]
    }
  ],
  "timeout": 60000,
  "userVerification": "preferred"
}
```

**Error Response (400):**
```json
{
  "error": "User not found"
}
```

---

### Complete Passkey Login

Finishes authentication by verifying the assertion from the authenticator.
Returns standard auth tokens on success.

**Endpoint:** `POST /api/v1/auth/webauthn/login/verify`

**Headers:**
- `Content-Type: application/json`
- `X-Device-Id: device-identifier` (optional)

**Request Body:**
```json
{
  "username": "the_username",
  "credential": {
    "id": "base64-encoded-credential-id",
    "clientDataJSON": "base64-encoded-client-data",
    "authenticatorData": "base64-encoded-authenticator-data",
    "signature": "base64-encoded-signature"
  },
  "challenge": "base64-encoded-challenge-from-start"
}
```

**Response (200):**
```json
{
  "access_token": "jwt-access-token",
  "refresh_token": "jwt-refresh-token",
  "token_type": "Bearer",
  "expires_in": 3600,
  "user": {
    "id": "user-uuid",
    "username": "the_username",
    "email": "user@example.com"
  }
}
```

**Error Response (401):**
```json
{
  "error": "Invalid or expired challenge"
}
```

---

### List Credentials

Returns all registered passkeys for the authenticated user.

**Endpoint:** `GET /api/v1/me/webauthn/credentials`

**Headers:**
- `Authorization: Bearer <token>` (required)

**Response (200):**
```json
{
  "credentials": [
    {
      "credential_id": "base64-encoded-credential-id",
      "user_id": "user-uuid",
      "type": "public-key",
      "device_type": "platform",
      "aaguid": "aaguid-hex",
      "registered_at": 1717000000
    }
  ]
}
```

---

### Delete Credential

Removes a registered passkey for the authenticated user.

**Endpoint:** `DELETE /api/v1/me/webauthn/credentials/{id}`

**Headers:**
- `Authorization: Bearer <token>` (required)

**Path Parameters:**
- `id`: Base64-encoded credential ID

**Response (200):**
```json
{
  "message": "Credential deleted successfully"
}
```

**Error Response (404):**
```json
{
  "error": "Credential not found or not owned by user"
}
```

---

## Error Codes

| Code | Description |
|------|-------------|
| `missing_required_fields` | Request missing required fields |
| `User not found` | User does not exist |
| `No credentials registered for user` | User has no passkeys |
| `Invalid or expired challenge` | Challenge not found or timed out |
| `Challenge mismatch` | Challenge doesn't match |
| `Credential not found` | Credential ID not recognized |
| `Potential replay attack detected` | Sign counter not incremented |

---

## Client Implementation Notes

### Registration Flow

1. Call `POST /api/v1/auth/webauthn/register/options`
2. Decode base64 challenge: `Uint8Array.from(atob(challenge), c => c.charCodeAt(0))`
3. Decode user.id similarly
4. Call `navigator.credentials.create({ publicKey: options })`
5. Extract `attestationObject` and `clientDataJSON` from the credential
6. Call `POST /api/v1/auth/webauthn/register/verify` with the credential data

### Login Flow

1. Call `POST /api/v1/auth/webauthn/login/options` with username
2. Decode the challenge and prepare allowCredentials
3. Call `navigator.credentials.get({ publicKey: options })`
4. Extract credential data and call `POST /api/v1/auth/webauthn/login/verify`

### Transports

If the authenticator supports it, transports can be detected via
`credential.response.getTransports()` and sent during registration.
