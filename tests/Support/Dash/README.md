# DASH-MPD schema fixtures (S58)

Two XSDs, vendored so the MPD acceptance check is **hermetic** — a validator that
silently degrades to "couldn't fetch the schema, call it valid" is worthless, and
a validator that needs the network is a test that skips on a runner and reads as
a pass.

| File | Provenance | sha256 |
| --- | --- | --- |
| `DASH-MPD.xsd` | <https://standards.iso.org/ittf/PubliclyAvailableStandards/MPEG-DASH_schema_files/DASH-MPD.xsd> (fetched 2026-08-10, HTTP 200, 20 160 bytes) | `cf002d9212e7cb66e46c1e44d50978221ddae2e8a46365c978b67f1e84055479` |
| `xlink.xsd` | <https://www.w3.org/1999/xlink.xsd> (fetched 2026-08-10, HTTP 200, 8 322 bytes) | `c3dbbaa28b884377ecd4a6c49d1f12566d8227cb0dce8ced10c0f66f8fa266e3` |

Both are **byte-for-byte as fetched** — nothing was edited, including
`DASH-MPD.xsd`'s `<xs:import … schemaLocation="xlink.xsd"/>`, which libxml
resolves relative to the importing schema's own path. That is why the two files
must stay in the same directory.

Measured, so it is not an assumption: deleting `xlink.xsd` makes
`DOMDocument::schemaValidate()` return **false** with
*"failed to load external entity … xlink.xsd"* — i.e. a missing dependency
fails the run rather than passing it.

Consumed by `Phlix\Tests\Support\Dash\MpdSchema`.
