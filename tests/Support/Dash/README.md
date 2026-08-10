# DASH-MPD schema fixtures (S58)

Three XSDs, vendored so the MPD acceptance check is **hermetic** — a validator
that silently degrades to "couldn't fetch the schema, call it valid" is
worthless, and a validator that needs the network is a test whose verdict
depends on w3.org's mood.

| File | Provenance | sha256 (as fetched) |
| --- | --- | --- |
| `DASH-MPD.xsd` | <https://standards.iso.org/ittf/PubliclyAvailableStandards/MPEG-DASH_schema_files/DASH-MPD.xsd> (fetched 2026-08-10, HTTP 200, 20 160 bytes) | `cf002d9212e7cb66e46c1e44d50978221ddae2e8a46365c978b67f1e84055479` |
| `xlink.xsd` | <https://www.w3.org/1999/xlink.xsd> (fetched 2026-08-10, HTTP 200, 8 322 bytes) | `c3dbbaa28b884377ecd4a6c49d1f12566d8227cb0dce8ced10c0f66f8fa266e3` |
| `xml.xsd` | <https://www.w3.org/2001/xml.xsd> (fetched 2026-08-10, HTTP 200, 8 836 bytes) | `61960fb3131e38022caad5360e2f33a3382578ab3c80cd58bd74320ede61b20c` |

## The one edit

`xlink.xsd` line 27 originally read

```xml
<xs:import namespace="http://www.w3.org/XML/1998/namespace" schemaLocation="http://www.w3.org/2001/xml.xsd"/>
```

and now reads `schemaLocation="xml.xsd"`. **That is the only byte changed in any
of the three files.** `DASH-MPD.xsd`'s own `<xs:import … schemaLocation="xlink.xsd"/>`
was already relative and is untouched; libxml resolves both relative to the
importing schema's path, which is why all three must stay in this directory.

## Why the edit was necessary — measured, not assumed

With the original absolute `schemaLocation`, libxml fetches
`http://www.w3.org/2001/xml.xsd` over HTTP on **every** validation. During the
S58 mutation run that was enough for w3.org to start answering **HTTP 429 Too
Many Requests**, after which `DOMDocument::schemaValidate()` reported *every*
document — including known-good ones — as invalid:

```
PHP Warning: DOMDocument::schemaValidate(http://www.w3.org/2001/xml.xsd):
             Failed to open stream: HTTP request failed! HTTP/1.1 429 Too Many Requests
INVALID: failed to load external entity "http://www.w3.org/2001/xml.xsd"
```

A gate whose verdict depends on a third party's rate limiter is not a gate.

## The belt as well as the braces

`Phlix\Tests\Support\Dash\MpdSchema` additionally installs a
`libxml_set_external_entity_loader()` that resolves ONLY files in this directory
and returns null for anything else, so no schema reference can reach the network
even if a future schema edit reintroduces an absolute URL. Measured, with the
proxy pointed at a dead port: a good manifest still validates, a bad one is
still rejected. Measured, with `xml.xsd` temporarily removed: **every** document
becomes *"Failed to load external entity because the resolver function returned
null"* — a missing dependency fails the run, it never passes it.
