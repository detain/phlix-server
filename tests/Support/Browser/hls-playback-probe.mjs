/**
 * S57 — does hls.js, in a real browser, PLAY the playlists this server writes?
 *
 * Serves a job directory over HTTP, loads it into hls.js inside a headless
 * Chrome, plays, and prints one JSON object describing what happened. It asserts
 * nothing: the PHP test that invokes it owns the verdict, so a change to the
 * pass bar lives beside the other assertions rather than in here.
 *
 * Dependency-free ON PURPOSE. No playwright, no puppeteer, no ws package:
 * Chrome is driven straight over the DevTools protocol using Node's built-in
 * global `WebSocket` (Node >= 22) and `fetch`. Adding an npm toolchain to
 * phlix-server for one check would be a bigger change than the step it verifies.
 *
 *   node hls-playback-probe.mjs --dir <jobdir> --hlsjs <hls.js> --chrome <bin>
 *                               [--playlist master.m3u8] [--seconds 3]
 *                               [--timeout 30000] [--upstream <base url>]
 *                               [--csp <header value>] [--script-nonce <nonce>]
 *
 * Exit code is 0 whenever the probe RAN (even if playback failed) and non-zero
 * only when it could not run at all — so a failed playback is a test failure
 * with a readable report, not an opaque crash.
 *
 * ## `--upstream` (S315)
 *
 * Without it, media bytes are read from `--dir` by this script: a FAKE server, which
 * is what S57's header says it is and what stopped S57 from being evidence about
 * `HlsController`. With it, every media request is PROXIED to
 * `<upstream>/<filename>` — in practice the real `/hls/{job_id}/` route standing up
 * on a port (`tests/Support/Browser/hls-controller-server.php`) — and the upstream's
 * status and `content-type` are passed through verbatim, so a wrong content type or
 * a 404 from the controller is visible to the browser rather than laundered here.
 *
 * The page itself keeps being served from this origin, so there is no CORS surface
 * to configure and no chance of a preflight failure being read as a playback
 * failure. `--dir` stays required either way: the probe never reads it in upstream
 * mode, and the PHP test uses it as the denominator (it counts what is on disk
 * before and after).
 *
 * ## `--csp` / `--script-nonce` (S60)
 *
 * Without `--csp` the page is served with NO Content-Security-Policy at all, so no
 * policy is enforced and nothing about CSP can be concluded from a pass — which is
 * why S315 declined to assert it. With `--csp <value>` the header is emitted on
 * `/__page.html` verbatim and Chrome enforces it against everything the page then
 * does: loading `/__hls.js` (`script-src`), fetching playlists and segments
 * (`connect-src`), spinning up hls.js's `blob:` transmux worker (`worker-src`) and
 * attaching the MSE `blob:` object URL to the `<video>` element (`media-src`).
 *
 * The value is passed IN rather than built here, so the caller can hand over the
 * exact bytes production serves. `--script-nonce` is stamped on the page's own
 * inline `<script>`: the SPA's real policy is `script-src 'self' 'nonce-…'`, under
 * which an un-nonced inline script does not execute at all — so passing the header
 * without the matching nonce would fail for a reason having nothing to do with
 * media. The two are passed SEPARATELY (the caller reads the nonce out of the SPA's
 * HTML body, not out of its CSP header), which makes a header/body nonce mismatch
 * visible here rather than silently papered over.
 *
 * Violations are reported: the page listens for `securitypolicyviolation` and every
 * event lands in `cspViolations` with its `effectiveDirective` and `blockedURI`.
 * That gives the PHP test a denominator — 0 on a policy that permits playback, and
 * a NAMED directive on one that does not.
 */

import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { readFile, mkdtemp, rm } from 'node:fs/promises';

import { tmpdir } from 'node:os';
import { join, extname, basename, resolve } from 'node:path';

const MIME = {
  '.m3u8': 'application/vnd.apple.mpegurl',
  '.m4s': 'video/mp4',
  '.mp4': 'video/mp4',
  '.ts': 'video/mp2t',
  '.vtt': 'text/vtt',
  '.js': 'application/javascript',
  '.html': 'text/html; charset=utf-8',
};

function arg(name, fallback = null) {
  const i = process.argv.indexOf(`--${name}`);
  return i === -1 ? fallback : process.argv[i + 1];
}

const dir = resolve(arg('dir', ''));
const hlsjs = resolve(arg('hlsjs', ''));
const chromeBin = arg('chrome', '/usr/bin/google-chrome-stable');
const playlist = arg('playlist', 'master.m3u8');
const seconds = Number(arg('seconds', '3'));
const timeoutMs = Number(arg('timeout', '30000'));
// S315. Null = read the bytes off disk (the original, fake-server behaviour).
const upstream = arg('upstream', null);
// S60. Null = serve the page with NO policy, i.e. enforce nothing (pre-S60
// behaviour, and what every case that does not ask about CSP still gets).
const csp = arg('csp', null);
const scriptNonce = arg('script-nonce', null);

if (!dir || !hlsjs) {
  console.error('usage: --dir <jobdir> --hlsjs <hls.js path> [--chrome bin]');
  process.exit(2);
}

const NONCE_ATTR = scriptNonce ? ` nonce="${scriptNonce.replace(/"/g, '')}"` : '';

const PAGE = (src, target) => `<!doctype html><meta charset="utf-8">
<video id="v" muted playsinline></video>
<script src="/__hls.js"></script>
<script${NONCE_ATTR}>
window.__probe = {
  done: false, ok: false, reason: null, hlsSupported: null,
  errors: [], levels: [], fragments: [], initSegments: [],
  currentTime: 0, duration: null, videoWidth: 0, videoHeight: 0,
  bufferedEnd: 0, decodedFrames: 0, droppedFrames: 0,
  cspViolations: [],
};
const p = window.__probe;
// S60. Every directive the browser actually REFUSED, named. A policy that
// permits playback produces an empty list; one that does not names the
// directive that stopped it, which is the difference between "the CSP is fine"
// and "nothing was enforced".
document.addEventListener('securitypolicyviolation', (e) => {
  p.cspViolations.push({
    effectiveDirective: e.effectiveDirective || e.violatedDirective || null,
    blockedURI: e.blockedURI || null,
  });
});
const v = document.getElementById('v');
function finish(ok, reason) { if (p.done) return; snapshot(); p.ok = ok; p.reason = reason; p.done = true; }
function snapshot() {
  p.currentTime = v.currentTime;
  p.duration = Number.isFinite(v.duration) ? v.duration : null;
  p.videoWidth = v.videoWidth; p.videoHeight = v.videoHeight;
  p.bufferedEnd = v.buffered.length ? v.buffered.end(v.buffered.length - 1) : 0;
  const q = v.getVideoPlaybackQuality ? v.getVideoPlaybackQuality() : null;
  if (q) { p.decodedFrames = q.totalVideoFrames - q.droppedVideoFrames; p.droppedFrames = q.droppedVideoFrames; }
}
try {
  p.hlsSupported = !!(window.Hls && window.Hls.isSupported && window.Hls.isSupported());
  if (!p.hlsSupported) { finish(false, 'hls.js reports MSE unsupported in this browser'); }
  else {
    const hls = new window.Hls({ debug: false });
    hls.on(window.Hls.Events.ERROR, (_e, d) => {
      p.errors.push({ type: d.type, details: d.details, fatal: !!d.fatal, reason: d.reason || null,
                      url: d.frag && d.frag.url ? d.frag.url.split('/').pop() : (d.url ? d.url.split('/').pop() : null),
                      response: d.response ? d.response.code : null });
      if (d.fatal) { finish(false, 'fatal hls.js error: ' + d.type + '/' + d.details); }
    });
    hls.on(window.Hls.Events.MANIFEST_PARSED, (_e, d) => {
      p.levels = d.levels.map((l) => ({ bitrate: l.bitrate, width: l.width, height: l.height,
                                        codecs: (l.videoCodec || '') + ',' + (l.audioCodec || ''),
                                        uri: (l.url && l.url[0] ? l.url[0].split('/').pop() : null) }));
      v.play().catch((e) => finish(false, 'play() rejected: ' + e.message));
    });
    hls.on(window.Hls.Events.FRAG_LOADED, (_e, d) => {
      const name = d.frag.url.split('/').pop();
      if (d.frag.sn === 'initSegment') { p.initSegments.push(name); } else { p.fragments.push(name); }
    });
    hls.loadSource(${JSON.stringify(src)});
    hls.attachMedia(v);
    const iv = setInterval(() => {
      snapshot();
      if (v.currentTime >= ${target}) { clearInterval(iv); finish(true, 'played past the target'); }
    }, 100);
  }
} catch (e) { finish(false, 'threw: ' + (e && e.message)); }
</script>`;

/**
 * Every file request the browser made, with the status it got. This is
 * SERVER-SIDE truth: it does not depend on which hls.js event happens to carry
 * an initialisation segment in a given release, so "did the player fetch what
 * EXT-X-MAP named?" is answered by the wire rather than by the library's API.
 */
const requests = [];

const server = createServer(async (req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  try {
    if (url.pathname === '/__page.html') {
      const body = PAGE(`/${playlist}`, seconds);
      const headers = { 'content-type': MIME['.html'] };
      // S60. Emitted VERBATIM — the caller hands over the exact bytes the SPA
      // shell serves, so what the browser enforces here is what it enforces
      // there. Absent by default, which is the pre-S60 behaviour.
      if (csp) headers['content-security-policy'] = csp;
      res.writeHead(200, headers);
      res.end(body);
      return;
    }
    if (url.pathname === '/__hls.js') {
      res.writeHead(200, { 'content-type': MIME['.js'] });
      res.end(await readFile(hlsjs));
      return;
    }
    const name = basename(url.pathname);
    if (name !== url.pathname.slice(1)) {
      res.writeHead(400).end('bad path');
      return;
    }
    // Chrome asks for /favicon.ico on every navigation. That is browser chrome, not
    // part of the presentation, so it is answered here and NOT recorded: proxying it
    // would plant a guaranteed 404 in the controller's own request census and make
    // "did the serve path refuse anything?" unanswerable (S315).
    if (name === 'favicon.ico') {
      res.writeHead(204).end();
      return;
    }
    if (upstream) {
      // S315 — the bytes come from the real serve path over a real socket. The
      // status and content-type are the UPSTREAM's: laundering either would hide
      // exactly the failures this mode exists to expose (a 404 from the segment
      // router, a mis-typed segment). No timeout is imposed — the controller
      // legitimately blocks while ffmpeg produces an on-demand segment, and
      // cutting that short here would read as "the controller stalled".
      const headers = {};
      if (req.headers.range) headers.range = req.headers.range;
      const up = await fetch(`${upstream}/${name}`, { headers });
      const body = Buffer.from(await up.arrayBuffer());
      const contentType = up.headers.get('content-type') || 'application/octet-stream';
      requests.push({ name, status: up.status, bytes: body.length, contentType, upstream: true });
      res.writeHead(up.status, {
        'content-type': contentType,
        'content-length': body.length,
        'access-control-allow-origin': '*',
      });
      res.end(body);
      return;
    }
    // Existence is checked BEFORE the header goes out. Streaming first and
    // patching the status on error is what makes a missing file arrive as an
    // empty 200 — which hls.js reports as `no EXTM3U delimiter`, i.e. a
    // playlist-format failure for what is really a missing file.
    const file = join(dir, name);
    const body = await readFile(file).catch(() => null);
    requests.push({ name, status: body === null ? 404 : 200, bytes: body === null ? 0 : body.length });
    if (body === null) {
      res.writeHead(404, { 'content-type': 'text/plain' });
      res.end('not found: ' + name);
      return;
    }
    res.writeHead(200, {
      'content-type': MIME[extname(name)] || 'application/octet-stream',
      'content-length': body.length,
      'access-control-allow-origin': '*',
    });
    res.end(body);
  } catch (e) {
    // Recorded, never silent: an upstream that refused the connection would
    // otherwise leave hls.js reporting a plain 404 and the request census showing
    // nothing at all, which reads as "the player never asked".
    requests.push({ name: basename(url.pathname), status: 0, bytes: 0, error: String(e && e.message) });
    if (!res.headersSent) res.writeHead(502);
    res.end();
  }
});

async function cdp(ws, method, params = {}) {
  const id = cdp.n = (cdp.n || 0) + 1;
  return new Promise((ok, bad) => {
    const onMsg = (ev) => {
      const m = JSON.parse(ev.data);
      if (m.id !== id) return;
      ws.removeEventListener('message', onMsg);
      m.error ? bad(new Error(m.error.message)) : ok(m.result);
    };
    ws.addEventListener('message', onMsg);
    ws.send(JSON.stringify({ id, method, params }));
  });
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let chrome = null;
let profile = null;
let exitCode = 0;
try {
  await new Promise((ok) => server.listen(0, '127.0.0.1', ok));
  const port = server.address().port;
  profile = await mkdtemp(join(tmpdir(), 'phlix-hls-probe-'));

  chrome = spawn(chromeBin, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    '--disable-dev-shm-usage', '--autoplay-policy=no-user-gesture-required',
    '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank',
  ], { stdio: ['ignore', 'pipe', 'pipe'] });

  let stderr = '';
  const devtools = await new Promise((ok, bad) => {
    const t = setTimeout(() => bad(new Error('chrome did not report a DevTools endpoint:\n' + stderr)), 20000);
    chrome.stderr.on('data', (b) => {
      stderr += b.toString();
      const m = stderr.match(/ws:\/\/127\.0\.0\.1:(\d+)\//);
      if (m) { clearTimeout(t); ok(Number(m[1])); }
    });
    chrome.on('exit', (c) => { clearTimeout(t); bad(new Error('chrome exited ' + c + '\n' + stderr)); });
  });

  let targets = [];
  for (let i = 0; i < 50 && targets.length === 0; i++) {
    const list = await (await fetch(`http://127.0.0.1:${devtools}/json/list`)).json();
    targets = list.filter((t) => t.type === 'page');
    if (targets.length === 0) await sleep(100);
  }
  if (targets.length === 0) throw new Error('no page target');

  const ws = new WebSocket(targets[0].webSocketDebuggerUrl);
  await new Promise((ok, bad) => {
    ws.addEventListener('open', ok, { once: true });
    ws.addEventListener('error', () => bad(new Error('devtools websocket failed')), { once: true });
  });

  await cdp(ws, 'Page.enable');
  await cdp(ws, 'Runtime.enable');
  await cdp(ws, 'Page.navigate', { url: `http://127.0.0.1:${port}/__page.html` });

  const deadline = Date.now() + timeoutMs;
  let probe = null;
  while (Date.now() < deadline) {
    await sleep(200);
    const r = await cdp(ws, 'Runtime.evaluate', {
      expression: 'JSON.stringify(window.__probe || null)',
      returnByValue: true,
    });
    const raw = r.result && r.result.value;
    if (typeof raw === 'string' && raw !== 'null') {
      probe = JSON.parse(raw);
      if (probe.done) break;
    }
  }
  if (probe === null) throw new Error('the page never installed window.__probe');
  if (!probe.done) { probe.ok = false; probe.reason = 'timed out after ' + timeoutMs + 'ms'; }
  probe.playlist = playlist;
  // Which server answered. Emitted so the PHP test can REFUSE a report produced in
  // the wrong mode: a controller-backed case that silently fell back to reading the
  // directory would otherwise pass while proving nothing (S315).
  probe.upstream = upstream;
  // S60. Which policy was enforced, echoed back for the same reason `upstream`
  // is: a case that asked for a CSP and silently got none would otherwise pass
  // while proving nothing about CSP at all.
  probe.csp = csp;
  probe.scriptNonce = scriptNonce;
  probe.requests = requests;
  console.log(JSON.stringify(probe, null, 2));
  ws.close();
} catch (e) {
  console.error(String(e && e.stack ? e.stack : e));
  exitCode = 1;
} finally {
  if (chrome) chrome.kill('SIGKILL');
  server.close();
  if (profile) await rm(profile, { recursive: true, force: true }).catch(() => {});
}
process.exit(exitCode);
