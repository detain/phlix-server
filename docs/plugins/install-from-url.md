# Install a plugin from a URL

> **Status:** Step A.5 ships the "install from URL" flow. The
> curated catalog (in-product list of trusted plugins) arrives with the
> hub in Phase C — see [`install-from-catalog.md`](install-from-catalog.md).

This is the lowest-friction install path for Phlex plugins today. You
need:

1. The plugin's public `plugin.json` URL (HTTPS) — or a local
   `file://` URL for testing.
2. An admin account on the running Phlex server (the **first** user
   that registered after Step A.5 was applied is auto-promoted to
   admin; `users.is_admin = 1`).
3. A logged-in browser session OR an access-token Bearer header.

## From the web UI

1. Browse to `/admin/plugins`.
2. Paste the `plugin.json` URL into **Install from URL**.
3. Click **Install**.

The server downloads, validates, runs `composer install --no-dev`
inside the plugin's directory, and stores the manifest in the
`plugins` table. The plugin lands **disabled** by default — flip
the toggle in the table to enable it.

### Try it with the reference plugin

The first plugin you should install on a fresh server is the
**reference plugin**, a tiny `metadata-provider` that returns
`{"title": "Hello, World"}` for one well-known fixture path. It's
unsigned by design and has no external dependencies, so it's safe to
install on any environment.

Paste this URL into the **Install from URL** form and click
**Install**:

```
https://raw.githubusercontent.com/detain/phlex-plugin-example/main/plugin.json
```

After install, flip the toggle for `phlex-plugin-example` in the
table to enable it. The plugin's source lives at
[`detain/phlex-plugin-example`](https://github.com/detain/phlex-plugin-example);
fork it as the starting point for your own plugin.

## From the command line

The same operations are reachable via the JSON API. Substitute your
JWT for `$TOKEN`:

```bash
TOKEN="…your admin bearer token…"

# 1. Install
curl -sS -X POST https://phlex.example.com/api/v1/admin/plugins/install \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"url": "https://example.com/my-plugin/plugin.json"}'

# 2. Enable
curl -sS -X POST https://phlex.example.com/api/v1/admin/plugins/phlex-plugin-demo/enable \
     -H "Authorization: Bearer $TOKEN"

# 3. List
curl -sS https://phlex.example.com/api/v1/admin/plugins \
     -H "Authorization: Bearer $TOKEN"
```

## What can go wrong

| Symptom                                       | Cause                                                                                 |
| --------------------------------------------- | ------------------------------------------------------------------------------------- |
| `401 Unauthorized` / `auth.required`          | No bearer token or token expired. Log in again.                                       |
| `403 Forbidden` / `auth.not_admin`            | Your user does not have `users.is_admin = 1`. Ask the existing admin to promote you.  |
| `400` / `plugin.url.required`                 | The form field was empty.                                                             |
| `400` / `plugin.url.invalid_scheme`           | Use `https://` (or `file://` for local fixtures). `http://` is refused.               |
| `422` / `plugin.install.failed` + `fields[]`  | The manifest failed schema validation. Inspect each `fields[].message`.               |
| `422` / `plugin.enable.failed`                | Manifest installed cleanly but `onEnable()` raised. Check the plugins log channel.    |
| `404` / `plugin.not_found`                    | You enabled/disabled/uninstalled a name that isn't installed. Check spelling.         |

## Security notes

- **HTTPS only by default.** The controller refuses `http://` even
  when the operator allowed it elsewhere via
  `PHLEX_PLUGINS_ALLOW_HTTP=1`.
- **Signatures are honoured.** If the manifest declares a `sha256:…`
  signature, the install fails unless that signature appears in the
  trusted-key allowlist (see [`trusted-plugin-list.md`](trusted-plugin-list.md)).
  Unsigned plugins install with a warning in the `plugins` log
  channel.
- **CSRF is not required.** The API is Bearer-token authenticated.
  Browsers do not auto-attach Authorization headers across origins,
  so the request can't be forged from another site.
- **Every install / enable / disable / uninstall is audit-logged.**
  Entries land in the `AUTH` log channel with the actor user id and
  the action name (`plugin.install.ui`, etc.). See
  `docs/dev/architecture-server.md` for log paths.
