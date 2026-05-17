# Trusted plugin signature allowlist

> **Status:** stub. Step A.5 ships the admin UI on top of the loader,
> but the canonical trusted-key allowlist lives in
> `PHLEX_EXPANSION_PLAN.md` §10 risk #4 and is finalised in Phase C
> alongside the hub.

## How the trust model works today

1. Plugin authors sign their `plugin.json` by writing a `sha256:<hex>`
   string into the `signature` field. The signing procedure is
   documented in `docs/plugins/developer-guide.md`.
2. The Phlex server's
   {@see \Phlex\Plugins\Signature\SignatureVerifier} ships with an
   empty allowlist. Operators populate the list at construction time
   via the container binding in
   `Phlex\Common\Container\Providers\PluginsProvider` (the
   `SignatureVerifier::class` definition).
3. When a plugin is installed:
   - **Signed + on allowlist** → install proceeds.
   - **Signed + not on allowlist** → install fails fast.
   - **Unsigned, allowlist not enforced** → install proceeds with a
     warning in the `plugins` log channel.
   - **Unsigned, `PHLEX_PLUGINS_REQUIRE_SIGNATURE=1`** → install fails.

## Adding your own trusted keys

Until the operator-friendly UI ships, the allowlist is configured in
code by adding a custom container override in your project's
bootstrap:

```php
$builder->addDefinitions([
    \Phlex\Plugins\Signature\SignatureVerifier::class => DI\factory(
        static fn (): \Phlex\Plugins\Signature\SignatureVerifier =>
            new \Phlex\Plugins\Signature\SignatureVerifier(
                trustedDigests: [
                    'sha256:abc123…', // phlex-plugin-lastfm@1.0.0
                ],
                requireSignature: false,
            ),
    ),
]);
```

## What ships in Phase C

- A curated allowlist published by the hub, signed with a long-lived
  Phlex maintainers' key.
- An operator UI under `/admin/plugins/trust` for inspecting,
  pinning, and revoking specific plugin signatures.
- Automatic pin renewal when a known plugin publishes a new version
  with the same author key.
