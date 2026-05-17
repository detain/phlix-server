# Install a plugin from the curated catalog

> **Status:** planned. The in-product catalog — a curated, browsable
> list of trusted plugins fetched from the Phlex hub — is on the
> roadmap for **Phase L** (notifications, analytics, and ecosystem
> wiring) once the hub itself has shipped. The supervisor's plan does
> not currently pin a calendar date to the catalog; the
> [install-from-URL flow](install-from-url.md) is the supported install
> path **and will remain so** for the foreseeable future.

## What this page will eventually cover

When the catalog ships:

- Browsing trusted plugins from `/admin/plugins/catalog` with type
  filters (metadata provider, transcoder hook, scrobbler, …).
- One-click install through the same JSON API that powers
  install-from-URL (`POST /api/v1/admin/plugins/install`).
- Signature verification against the hub's published trusted-key
  list, so operators don't have to maintain their own allowlist for
  community plugins.
- Update notifications when a known plugin publishes a new version
  that satisfies the running server's `phlex_min_server_version`.

## What works today

Until the catalog lands, plugin authors share their install URL
directly and operators paste it into **Admin → Plugins → Install
from URL**. See [`install-from-url.md`](install-from-url.md) for the
end-to-end walkthrough, and
[`trusted-plugin-list.md`](trusted-plugin-list.md) for the operator's
trust model. The reference plugin
[`detain/phlex-plugin-example`](https://github.com/detain/phlex-plugin-example)
is the canonical install target for verifying the URL flow on a fresh
server.

## See also

- [`developer-guide.md`](developer-guide.md) §11 (publishing) for the
  plugin author's view of distribution today.
- [`PHLEX_EXPANSION_PLAN.md`](../../PHLEX_EXPANSION_PLAN.md) Phase C
  (hub) and Phase L (notifications / analytics / ecosystem wiring)
  for the long-term plan.
