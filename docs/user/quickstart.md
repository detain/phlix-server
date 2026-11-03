# Phlix quickstart — from empty server to first watch party

One page, in order: install → admin account → first library → the household →
first watch party. Reference detail lives in the
[README](../../README.md); this page is the journey.

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

## 2. Create the admin account

Open the portal, choose **Register**, and create your account. The **first**
account on an empty server always becomes the active admin (and is admitted
regardless of the signup gate). Every later self-service registration goes to
the approval queue (see step 4) — the shipped default is `signup_mode: approval`,
so strangers cannot slip in just because the port is reachable.

## 3. Add your first library

Sign in → **Admin → Libraries** → add a library: give it a name and point it at
the folder that holds your media (movies and series go together; the scanner
sorts them). Kick off the scan and wait for it to finish before browsing —
until it does, the grids are legitimately empty. Home art and metadata are
fetched afterwards from TMDB/TVDB (and any provider plugins you enable);
`ffmpeg` on the box is what makes trick previews and subtitle extraction work.

## 4. Add the household

Each person registers at `/app` (`Register`) and lands in the **pending** queue;
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

## 5. Start your first watch party

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
| **watch party / room / group** | One noun, three vocabularies: user copy says *watch party*, the REST/WS payloads say *group* and *room id*, internals say group. Same thing: a synced-playback party you join by id. |
| **SyncPlay** | The watch-party feature's name in the wire protocol and in the portal UI. |
| **hub / relay** | The optional companion service (above) and the outbound tunnel your server dials so remote viewers can reach it. |
| **profile** | Two kinds meet here: a *household profile* is the per-person viewer profile with rating caps and history (step 4); a *quality profile* is a playback-device preset (stream-limits API). The endpoints say which one they mean. |
| **JWT** | The signed bearer token the API hands you at login (`Authorization: Bearer …`) and the WebSocket carries. |
| **signed URL** | A media/thumbnail URL carrying an expiring signature, so short-lived requests (segments, subtitles) can be fetched by dumb `<img>`/`<video>` machinery without a header. |
| **HLS** | HTTP Live Streaming: the server chops media into segments + a playlist so browsers and TVs can stream it adaptively. |
| **DLNA / OPDS** | LAN media-server compatibility (TVs, consoles) and the catalog-browsing endpoint respectively. |

## Where to go next

- SyncPlay mechanics & wire details: README → [SyncPlay — group watching](../../README.md#syncplay--group-watching)
- Backups, themes, integrations, troubleshooting: README's feature sections and [Troubleshooting](../../README.md#troubleshooting)
- Deeper guides live on the external [phlix-docs](https://detain.github.io/phlix-docs/) site the README links out to.
