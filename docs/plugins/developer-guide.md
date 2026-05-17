# Phlex plugin developer guide

> **Status:** stub. The full developer guide arrives in **A.7**.
> For now this page collects the bits that already exist.

## What lives here today (A.3)

- The **plugin manifest specification** —
  [`manifest.md`](manifest.md) and
  [`manifest.schema.json`](manifest.schema.json). These define the
  shape of a `plugin.json` file. The PHP value object that parses
  them is `Phlex\Plugins\Manifest`.

## What is coming next

| Step | Adds |
| --- | --- |
| A.4 | Plugin loader, lifecycle contract, event-alias resolution, signature verification stub. |
| A.5 | Admin UI for installing, enabling, disabling, and configuring plugins. |
| A.6 | First reference plugin (`phlex-plugin-lastfm`) to exercise the whole stack. |
| A.7 | This guide is rewritten into a full author-onboarding document. |

## Where to start

If you are building a plugin **today**, the only thing you can do
authoritatively is author a manifest. Copy a fixture:

- `tests/Fixtures/Plugins/valid-lastfm.json` — minimal scrobbler.
- `tests/Fixtures/Plugins/valid-oidc.json` — minimal auth provider with
  secret settings.

Then read [`manifest.md`](manifest.md) for what each field means.

## Where to file issues

Open a GitHub issue against
[`detain/phlex`](https://github.com/detain/phlex) with the label
`area:plugins` once your manifest is parsing cleanly but you cannot
load the plugin — that almost certainly means the loader (A.4) is the
missing piece.
