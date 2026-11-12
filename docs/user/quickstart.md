# Phlix quickstart — from empty server to first watch party

One page, in order: install → admin account → first library → first play →
the household → first watch party. Reference detail lives in the
[README](../../README.md); this page is the journey.

Want click-by-click detail for a step? The phlix-docs site
carries the step-by-step guides: [install on Linux](https://detain.github.io/phlix-docs/install/linux)
or [Docker](https://detain.github.io/phlix-docs/install/docker) (step 1),
[first run](https://detain.github.io/phlix-docs/first-run) (steps 2-4),
[libraries](https://detain.github.io/phlix-docs/libraries) (step 3) and
[adding people](https://detain.github.io/phlix-docs/adding-people) (step 5).

## 1. Install

Pick ONE path; each ends with a running server whose portal listens on
`http://<your-host>:8096/app` (the watch-party WebSocket uses `:8097`):

- **One-liner (fresh Ubuntu/Debian host).** `curl | sudo bash` of
  `scripts/install.sh` does packages, database, systemd service and (optionally)
  an HAProxy + Let's Encrypt front — the README's
  [Installation](../../README.md#installation) section carries the exact line
  and its flags.
- **Docker.** Images are first-class: [`docker/README.md`](../../docker/README.md).
- **From source.** The README's
  [Manual install](../../README.md#manual-install-from-source) section — read
  its Prerequisites block first; it names the apt packages the other two paths
  do for you.

There is no `.env` in the PHP-runtime sense: Phlix has no dotenv loader, and no
code reads a `.env` file. (The one example file that exists,
`docker/examples/.env.example`, is consumed by docker compose's own variable
interpolation in the `docker/` examples — never by the server process.)
Environment variables reach the server through the systemd
unit's `EnvironmentFile=/etc/phlix/env` (or your shell when you run
`start.php` by hand); manually-run scripts pull the same file in via
`scripts/bootstrap_env.php`, which never overrides a variable already set.

## 2. Create the admin account

Open the portal, choose **Create an account** (the sign-in page's link; the form
itself is headed **Create your account**), and fill it in. The **first**
account on an empty server always becomes the active admin (and is admitted
regardless of the signup gate). Every later self-service registration goes to
the approval queue (see step 5) — the shipped default is `signup_mode: approval`,
so strangers cannot slip in just because the port is reachable.

## 3. Add your first library

Sign in → **Admin → Libraries** → add a library: give it a name and point it at
the folder that holds your media (movies and series go together; the scanner
sorts them). Kick off the scan and wait for it to finish before browsing —
until it does, the grids are legitimately empty. Home art and metadata are
fetched afterwards from TMDB/TVDB (and any provider plugins you enable);
`ffmpeg` on the box is what makes trick previews and subtitle extraction work.

Fetching that art needs a (free) TMDB API key: pass `--tmdb-api-key` to the
installer, or save it later in **Admin → Settings → Metadata** — without a
key, only local NFO metadata and artwork work. A key saved through the admin
UI is read when the workers start, so restart the server to apply it (the
settings screen flags such keys as needing a restart).

## 4. Play your first title

Once the scan finishes, open any title from **Browse** and press **Play** — no
transcode pass or pre-generation step is required first. The first play of an
exotic-codec file can take noticeably longer than the rest: segments are encoded
on demand, so the player is waiting on the encoder for its first moments while
cached later segments stream instantly. (If something does not play, the
README's [Troubleshooting](../../README.md#troubleshooting) "first steps" list —
another browser, sign-out/sign-in, same-network check — is what resolves most
household playback complaints.)

## 5. Add the household

Each person signs up at `/app` (the **Create an account** link under the sign-in
form) and lands in the **pending** queue;
you approve (or reject) them from **Admin → Users** — the queue is also reachable
from the CLI (`user:list --status=pending`, `user:approve`). Then per account:

- **Profiles** (Settings → Profiles): up to five per account, each with its own
  watch history, rating cap (G/PG/PG-13/R/NC-17/X/UNRATED), genre allow/block
  lists and an optional 4- or 6-digit PIN. This is where a shared living-room
  login becomes a kid-safe profile.
- **Access schedules**: per-profile allowed-time windows for who may watch when
  (admin API surface `/api/v1/profiles/{profileId}/schedules`).

What a child profile actually sees: titles above its rating cap and blocked
genres are filtered out of every rail and search result — the cap is enforced
server-side at the library and stream gates, not by hiding UI.

**Forgot a password?** There is no self-service reset and no email flow. An
admin can start the process from **Admin → Users** (the forced-change button),
but the recovery completes with one CLI line on the server:
`php bin/phlix user:reset-password <name>` — it prints a strong new password
(or accepts `--password=`), revokes that account's live sessions, and clears
the hold. Hand the member the printed password; they should then pick their own
under Settings. The same command is the escape hatch if the forced-change hold
is ever left mid-flight (README → *Bad days: recovery from the CLI*).

### Signed-in devices

Every device a member signs in on is its own session. Signing one device out
(`DELETE /api/v1/auth/sessions/{id}`, listed by `GET /api/v1/auth/sessions`)
ends that device only and invalidates every token copy that came from it. If a
copied refresh token is ever replayed, the server revokes that whole session
rather than guessing which copy is the thief's. Details for operators:
[Session-family revocation](../session-family-revocation.md).

## 6. Start your first watch party

The walkthrough that names the buttons: README →
[Starting a watch party (portal walkthrough)](../../README.md#starting-a-watch-party-portal-walkthrough).
Short version: open a movie in the player → **SyncPlay** → **Create room**,
then send everyone the **room id** (plus the password, if you set one). The
party's host controls play/pause/seek for everyone.

## Remote access: the hub

A **hub** ([`phlix-hub`](https://github.com/detain/phlix-hub)) is a small
separate service you run — typically on a machine that is always reachable
(a VPS, or your router-adjacent box with a port open) — that your Phlix
server(s) dial **out** to. Two problems it solves:

- **One login.** Link your server account to your hub account once, and your
  hub credential signs you in across the Phlix services you run, instead of a
  separate password per server.
- **Reach your server from outside.** The server opens an outbound relay
  tunnel to the hub, so you can stream from your own media at the cottage —
  **no port forwarding, no DMZ, nothing opened on your home router.**

The server-side half is small: point `PHLIX_HUB_URL` at your hub (the installer
flag `--hub-url` does it for you) and complete pairing with
`scripts/pair-with-hub.php`. What the hub itself must do — keys, the master
listener, admin of peers — is the hub repo's story, not this one.

Running the server behind HTTPS without a hub works too — that is a plain
reverse-proxy question, covered by
[`reverse-proxy/README.md`](../../reverse-proxy/README.md).

## Words you'll meet

| Term | Meaning here |
|------|--------------|
| **watch party / room / group** | One noun, three vocabularies: user copy says *room* ('Create room' / 'Join room' / 'Room ID'), the REST/WS payloads say *group* and *room id*, and older server-internal prose says *room* — the README's Naming Layers passage is the authoritative translation table. Same thing: a synced-playback party you join by id. |
| **SyncPlay** | The watch-party feature's name in the wire protocol and in the portal UI. |
| **hub / relay** | The optional companion service (above) and the outbound tunnel your server dials so remote viewers can reach it. |
| **profile** | Two kinds meet here: a *household profile* is the per-person viewer profile with rating caps and history (step 5); a *quality profile* is a playback-device preset (stream-limits API). The endpoints say which one they mean. |
| **JWT** | The signed bearer token the API hands you at login (`Authorization: Bearer …`) and the WebSocket carries. |
| **signed URL** | A media/thumbnail URL carrying an expiring signature, so short-lived requests (segments, subtitles) can be fetched by dumb `<img>`/`<video>` machinery without a header. |
| **HLS** | HTTP Live Streaming: the server chops media into segments + a playlist so browsers and TVs can stream it adaptively. |
| **DLNA / OPDS** | LAN media-server compatibility (TVs, consoles) and the catalog-browsing endpoint respectively. |

## Where to go next

- SyncPlay mechanics & wire details: README → [SyncPlay — group watching](../../README.md#syncplay--group-watching)
- Backups, themes, integrations, troubleshooting: README's feature sections and [Troubleshooting](../../README.md#troubleshooting)
- Deeper guides live on the external [phlix-docs](https://detain.github.io/phlix-docs/) site the README links out to.
