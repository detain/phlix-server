# Passkeys User Guide

Passkeys are a modern, passwordless authentication method that uses public-key
cryptography to secure your account. They are more secure than passwords and
protect against phishing attacks.

## What is a Passkey?

A passkey is a FIDO2/WebAuthn credential stored on your device or in a
hardware security key. It consists of:

- **Private key**: Stored securely on your device or hardware key
- **Public key**: Stored on the Phlex server

When you authenticate, the server sends a challenge that your device signs
with the private key. The server verifies the signature using the public key.

## Benefits

### Security

- **Phishing-resistant**: Passkeys are bound to the specific domain
- **No passwords to steal**: Private keys never leave your device
- **Hardware-bound**: Platform authenticators are secured by device security
- **Replay protection**: Sign counters detect stolen credential data

### Convenience

- **No passwords to remember**: Just use your fingerprint, face, or PIN
- **Fast login**: One tap or glance to authenticate
- **Works everywhere**: Roaming passkeys work on any compatible device

## Supported Authenticators

### Platform Authenticators

- **Touch ID** (macOS)
- **Face ID** (iOS/macOS)
- **Windows Hello** (Windows)
- **Android fingerprints** (Google Pixel, Samsung Galaxy, etc.)

### Roaming Authenticators

- **YubiKey** (USB-C, USB-A, NFC)
- **Solo Key**
- **Feitian ePass** (NFC)
- **Any FIDO2-certified token**

## How to Register a Passkey

1. Log in to Phlex with your existing username and password
2. Navigate to **Account Settings** → **Passkeys**
3. Click **Register New Passkey**
4. Follow your browser's prompts to confirm
5. Your passkey is now registered and ready to use

## How to Login with a Passkey

### On a Device with an Existing Passkey

1. Go to the Phlex login page
2. Enter your username
3. When prompted, use your authenticator (Touch ID, Windows Hello, etc.)
4. You're logged in!

### On a New Device

If you set up a roaming passkey on another device:

1. Enter your username on the new device
2. Connect your hardware key or use NFC
3. Touch the key or confirm with biometrics
4. You're logged in!

## Managing Passkeys

### View Registered Passkeys

1. Go to **Account Settings** → **Passkeys**
2. You'll see a list of all registered passkeys with:
   - Credential ID (partial)
   - Device type (platform or cross-platform)
   - Registration date

### Delete a Passkey

1. Go to **Account Settings** → **Passkeys**
2. Click **Delete** next to the passkey you want to remove
3. Confirm the deletion

**Warning**: Deleting a passkey is permanent. Make sure you have another
login method available before deleting your last passkey.

## Multiple Passkeys

You can register multiple passkeys on a single account. This is useful for:

- Having both a platform authenticator and a hardware key
- Using different devices
- Having backup authenticators

## Troubleshooting

### "No passkeys found" during login

- Make sure you're using the same account the passkey was registered with
- Check if you're on the correct website (check the URL)
- Try using a different browser or device

### "Authenticator not registered"

- The passkey may have been deleted from your account
- Try registering a new passkey

### Fingerprint/Face not recognized

- Your device's biometric data may have changed
- Use your device PIN as a fallback
- Re-register the passkey if needed

### Hardware key not detected

- Check that the key is properly connected
- Try a different USB port
- Ensure your browser supports FIDO2/WebAuthn
- Some keys require NFC on mobile devices

## Security Best Practices

1. **Register at least two passkeys** — one as primary and one as backup
2. **Keep backup codes safe** — store them in a secure location
3. **Use a hardware key for maximum security** — physical possession required
4. **Don't delete your last passkey** — always keep a working login method
5. **Report suspicious activity** — contact admin if you notice unauthorized access

## Platform-Specific Notes

### macOS

- Safari fully supports passkeys
- Chrome and Firefox also supported
- Touch ID or system password required

### Windows

- Windows Hello supported (PIN, fingerprint, face)
- Microsoft Edge recommended for best support
- Chrome and Firefox also supported

### iOS/macOS Safari

- Face ID or Touch ID for authentication
- iCloud Keychain sync available
- Passkeys sync across Apple devices

### Android

- Google Pixel fingerprint/face unlock
- Chrome supported
- May sync via Google Password Manager

### Hardware Keys

- YubiKey 5 series recommended
- Store backup keys in a secure location
- Some services support only certain key models
