/**
 * RemoteAccessPage — admin "Remote Access" page with four collapsible sections:
 *   1. Hub Pairing — initiate/unenroll with the hub
 *   2. Subdomain — claim/release subdomain from the hub
 *   3. Relay Tunnel — enable/disable relay tunnel + ping
 *   4. Port Forward — enable/disable port forwarding + candidates
 *
 * Security:
 *  - No `dangerouslySetInnerHTML` — all server/API strings as text.
 *  - Admin gate handled server-side + `useAdminGuard` in App shell.
 *
 * Async/resident rules:
 *  - Status polling via GET status on mount.
 *  - useEffect cleans up on unmount.
 *
 * @since 2.3
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import {
  RemoteAccessApi,
  type HubStatus,
  type SubdomainStatus,
  type RelayStatus,
  type PortForwardStatus,
  type HostnameCandidate,
} from '../api/remoteAccess';
import { useToast } from '../components/Toast';

export interface RemoteAccessPageProps {
  client: ApiClient;
}

interface SectionProps {
  title: string;
  expanded: boolean | undefined;
  onToggle: () => void;
  statusSummary: string | null;
  children: React.ReactNode;
}

function Section({ title, expanded = false, onToggle, statusSummary, children }: SectionProps): JSX.Element {
  return (
    <section className="remote-access__section" aria-labelledby={`remote-access-${title.toLowerCase().replace(/\s+/g, '-')}-heading`}>
      <button
        type="button"
        className="remote-access__section-header"
        onClick={onToggle}
        aria-expanded={expanded}
        aria-controls={`remote-access-${title.toLowerCase().replace(/\s+/g, '-')}-body`}
      >
        <div className="remote-access__section-title-row">
          <h2 id={`remote-access-${title.toLowerCase().replace(/\s+/g, '-')}-heading`}>{title}</h2>
          <span className={`remote-access__chevron ${expanded ? 'remote-access__chevron--up' : ''}`} aria-hidden="true">
            &#9660;
          </span>
        </div>
        {statusSummary !== null && (
          <p className="remote-access__section-summary">{statusSummary}</p>
        )}
      </button>
      {expanded && (
        <div
          id={`remote-access-${title.toLowerCase().replace(/\s+/g, '-')}-body`}
          className="remote-access__section-body"
        >
          {children}
        </div>
      )}
    </section>
  );
}

export function RemoteAccessPage({ client }: RemoteAccessPageProps): JSX.Element {
  const apiRef = useRef(new RemoteAccessApi(client));
  // Destructure the stable `push` callback — the whole `useToast()`
  // context value is a fresh object reference on every toast queue change.
  const { push: pushToast } = useToast();

  // ─── Section expand/collapse state ──────────────────────────────────────────
  const [expanded, setExpanded] = useState<Record<string, boolean>>({
    hub: true,
    subdomain: false,
    relay: false,
    portforward: false,
  });

  const toggleSection = useCallback((section: string): void => {
    setExpanded(prev => ({ ...prev, [section]: !prev[section] }));
  }, []);

  // ─── Hub pairing state ─────────────────────────────────────────────────────
  const [hubStatus, setHubStatus] = useState<HubStatus | null>(null);
  const [hubLoading, setHubLoading] = useState(true);
  const [hubPairing, setHubPairing] = useState(false);
  const [hubUnenrolling, setHubUnenrolling] = useState(false);
  const [hubHeartbeat, setHubHeartbeat] = useState(false);

  // Pairing modal state
  const [showPairModal, setShowPairModal] = useState(false);
  const [pairingHubUrl, setPairingHubUrl] = useState('');
  const [pairingServerName, setPairingServerName] = useState('Phlix Server');
  const [pairingClaimCode, setPairingClaimCode] = useState<string | null>(null);
  const [pairingClaimId, setPairingClaimId] = useState<string | null>(null);
  const [pairingPolling, setPairingPolling] = useState(false);

  const loadHubStatus = useCallback(async (): Promise<void> => {
    try {
      const status = await apiRef.current.hubStatus();
      setHubStatus(status);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to load hub status.';
      pushToast(msg, 'error');
    } finally {
      setHubLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadHubStatus();
  }, [loadHubStatus]);

  const initiatePairing = useCallback(async (): Promise<void> => {
    if (hubPairing) return;
    setHubPairing(true);
    try {
      const result = await apiRef.current.hubPair(pairingHubUrl, pairingServerName);
      if (result.success) {
        setPairingClaimCode(result.claimCode ?? null);
        setPairingClaimId(result.claimId ?? null);
        pushToast('Pairing initiated. Complete the claim on the hub, then poll.', 'success');
      }
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to initiate pairing.';
      pushToast(msg, 'error');
    } finally {
      setHubPairing(false);
    }
  }, [hubPairing, pairingHubUrl, pairingServerName, pushToast]);

  const pollPairing = useCallback(async (): Promise<void> => {
    if (pairingClaimId === null || pairingHubUrl === '') return;
    if (pairingPolling) return;
    setPairingPolling(true);
    try {
      const result = await apiRef.current.hubPoll(pairingClaimId, pairingHubUrl);
      if (result.success && result.token) {
        // Complete the pairing
        await apiRef.current.hubComplete(
          result.token,
          '', // hubJwksUrl - would need to come from poll result
          result.serverId ?? '',
          pairingHubUrl,
        );
        pushToast('Hub paired successfully.', 'success');
        setShowPairModal(false);
        setPairingClaimCode(null);
        setPairingClaimId(null);
        await loadHubStatus();
      } else if (!result.success && result.message) {
        pushToast(result.message, 'error');
      }
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to poll pairing status.';
      pushToast(msg, 'error');
    } finally {
      setPairingPolling(false);
    }
  }, [pairingClaimId, pairingHubUrl, pairingPolling, pushToast, loadHubStatus]);

  const unenrollHub = useCallback(async (): Promise<void> => {
    if (hubUnenrolling) return;
    setHubUnenrolling(true);
    try {
      await apiRef.current.hubUnenroll();
      pushToast('Hub unenrolled.', 'success');
      await loadHubStatus();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to unenroll.';
      pushToast(msg, 'error');
    } finally {
      setHubUnenrolling(false);
    }
  }, [hubUnenrolling, pushToast, loadHubStatus]);

  const sendHeartbeat = useCallback(async (): Promise<void> => {
    if (hubHeartbeat) return;
    setHubHeartbeat(true);
    try {
      const result = await apiRef.current.hubHeartbeat();
      if (result.success) {
        pushToast('Heartbeat sent.', 'success');
      }
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to send heartbeat.';
      pushToast(msg, 'error');
    } finally {
      setHubHeartbeat(false);
    }
  }, [hubHeartbeat, pushToast]);

  // ─── Subdomain state ────────────────────────────────────────────────────────
  const [subdomainStatus, setSubdomainStatus] = useState<SubdomainStatus | null>(null);
  const [subdomainLoading, setSubdomainLoading] = useState(true);
  const [subdomainClaiming, setSubdomainClaiming] = useState(false);
  const [subdomainReleasing, setSubdomainReleasing] = useState(false);

  const loadSubdomainStatus = useCallback(async (): Promise<void> => {
    try {
      const status = await apiRef.current.subdomainStatus();
      setSubdomainStatus(status);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to load subdomain status.';
      pushToast(msg, 'error');
    } finally {
      setSubdomainLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadSubdomainStatus();
  }, [loadSubdomainStatus]);

  const claimSubdomain = useCallback(async (): Promise<void> => {
    if (subdomainClaiming) return;
    setSubdomainClaiming(true);
    try {
      await apiRef.current.subdomainClaim();
      pushToast('Subdomain claimed.', 'success');
      await loadSubdomainStatus();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to claim subdomain.';
      pushToast(msg, 'error');
    } finally {
      setSubdomainClaiming(false);
    }
  }, [subdomainClaiming, pushToast, loadSubdomainStatus]);

  const releaseSubdomain = useCallback(async (): Promise<void> => {
    if (subdomainReleasing) return;
    setSubdomainReleasing(true);
    try {
      await apiRef.current.subdomainRelease();
      pushToast('Subdomain released.', 'success');
      await loadSubdomainStatus();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to release subdomain.';
      pushToast(msg, 'error');
    } finally {
      setSubdomainReleasing(false);
    }
  }, [subdomainReleasing, pushToast, loadSubdomainStatus]);

  // ─── Relay state ───────────────────────────────────────────────────────────
  const [relayStatus, setRelayStatus] = useState<RelayStatus | null>(null);
  const [relayLoading, setRelayLoading] = useState(true);
  const [relayEnabling, setRelayEnabling] = useState(false);
  const [relayDisabling, setRelayDisabling] = useState(false);
  const [relayPinging, setRelayPinging] = useState(false);
  const [relayLatency, setRelayLatency] = useState<number | null>(null);

  const loadRelayStatus = useCallback(async (): Promise<void> => {
    try {
      const status = await apiRef.current.relayStatus();
      setRelayStatus(status);
      setRelayLatency(null); // Reset latency on status refresh
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to load relay status.';
      pushToast(msg, 'error');
    } finally {
      setRelayLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadRelayStatus();
  }, [loadRelayStatus]);

  const enableRelay = useCallback(async (): Promise<void> => {
    if (relayEnabling) return;
    setRelayEnabling(true);
    try {
      await apiRef.current.relayEnable();
      pushToast('Relay enabled.', 'success');
      await loadRelayStatus();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to enable relay.';
      pushToast(msg, 'error');
    } finally {
      setRelayEnabling(false);
    }
  }, [relayEnabling, pushToast, loadRelayStatus]);

  const disableRelay = useCallback(async (): Promise<void> => {
    if (relayDisabling) return;
    setRelayDisabling(true);
    try {
      await apiRef.current.relayDisable();
      pushToast('Relay disabled.', 'success');
      await loadRelayStatus();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to disable relay.';
      pushToast(msg, 'error');
    } finally {
      setRelayDisabling(false);
    }
  }, [relayDisabling, pushToast, loadRelayStatus]);

  const pingRelay = useCallback(async (): Promise<void> => {
    if (relayPinging) return;
    setRelayPinging(true);
    try {
      const result = await apiRef.current.relayPing();
      setRelayLatency(result.latencyMs);
      pushToast(`Relay latency: ${result.latencyMs}ms`, 'success');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to ping relay.';
      pushToast(msg, 'error');
    } finally {
      setRelayPinging(false);
    }
  }, [relayPinging, pushToast]);

  // ─── Port forward state ────────────────────────────────────────────────────
  const [portForwardStatus, setPortForwardStatus] = useState<PortForwardStatus | null>(null);
  const [portForwardLoading, setPortForwardLoading] = useState(true);
  const [portForwardEnabling, setPortForwardEnabling] = useState(false);
  const [portForwardDisabling, setPortForwardDisabling] = useState(false);
  const [candidates, setCandidates] = useState<HostnameCandidate[]>([]);

  const loadPortForwardStatus = useCallback(async (): Promise<void> => {
    try {
      const [status, candidatesResult] = await Promise.all([
        apiRef.current.portForwardStatus(),
        apiRef.current.portForwardCandidates(),
      ]);
      setPortForwardStatus(status);
      setCandidates(candidatesResult.candidates);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to load port-forward status.';
      pushToast(msg, 'error');
    } finally {
      setPortForwardLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadPortForwardStatus();
  }, [loadPortForwardStatus]);

  const enablePortForward = useCallback(async (): Promise<void> => {
    if (portForwardEnabling) return;
    setPortForwardEnabling(true);
    try {
      await apiRef.current.portForwardEnable();
      pushToast('Port forwarding enabled.', 'success');
      await loadPortForwardStatus();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to enable port forwarding.';
      pushToast(msg, 'error');
    } finally {
      setPortForwardEnabling(false);
    }
  }, [portForwardEnabling, pushToast, loadPortForwardStatus]);

  const disablePortForward = useCallback(async (): Promise<void> => {
    if (portForwardDisabling) return;
    setPortForwardDisabling(true);
    try {
      await apiRef.current.portForwardDisable();
      pushToast('Port forwarding disabled.', 'success');
      await loadPortForwardStatus();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to disable port forwarding.';
      pushToast(msg, 'error');
    } finally {
      setPortForwardDisabling(false);
    }
  }, [portForwardDisabling, pushToast, loadPortForwardStatus]);

  // ─── Render ────────────────────────────────────────────────────────────────
  const isRelayActionInProgress = relayEnabling || relayDisabling;
  const isPortForwardActionInProgress = portForwardEnabling || portForwardDisabling;

  return (
    <section className="page page--remote-access" aria-labelledby="remote-access-heading">
      <h1 id="remote-access-heading">Remote Access</h1>

      {/* ── Section 1: Hub Pairing ──────────────────────────────────────── */}
      <Section
        title="Hub Pairing"
        expanded={expanded.hub}
        onToggle={() => toggleSection('hub')}
        statusSummary={
          hubLoading
            ? 'Loading…'
            : hubStatus === null
              ? 'Unable to load'
              : hubStatus.paired
                ? `Paired${hubStatus.serverId ? ` (${hubStatus.serverId})` : ''}`
                : 'Not paired'
        }
      >
        <div className="remote-access__card">
          {hubLoading ? (
            <p role="status" aria-live="polite">Loading hub status…</p>
          ) : hubStatus === null ? (
            <p className="remote-access__empty">Unable to load hub status.</p>
          ) : (
            <>
              {hubStatus.paired && (
                <dl className="remote-access__dl">
                  {hubStatus.serverId && (
                    <>
                      <dt>Server ID</dt>
                      <dd>{hubStatus.serverId}</dd>
                    </>
                  )}
                  {hubStatus.hubUrl && (
                    <>
                      <dt>Hub URL</dt>
                      <dd>{hubStatus.hubUrl}</dd>
                    </>
                  )}
                  {hubStatus.enrolledAt && (
                    <>
                      <dt>Enrolled At</dt>
                      <dd>{new Date(hubStatus.enrolledAt).toLocaleString()}</dd>
                    </>
                  )}
                </dl>
              )}

              <div className="remote-access__card-actions">
                {!hubStatus.paired ? (
                  <button
                    type="button"
                    className="btn--primary"
                    onClick={() => setShowPairModal(true)}
                  >
                    Initiate Pairing
                  </button>
                ) : (
                  <>
                    <button
                      type="button"
                      className="btn--secondary"
                      onClick={() => void sendHeartbeat()}
                      disabled={hubHeartbeat}
                      aria-busy={hubHeartbeat}
                    >
                      {hubHeartbeat ? 'Sending…' : 'Send Heartbeat'}
                    </button>
                    <button
                      type="button"
                      className="btn--danger"
                      onClick={() => void unenrollHub()}
                      disabled={hubUnenrolling}
                      aria-busy={hubUnenrolling}
                    >
                      {hubUnenrolling ? 'Unenrolling…' : 'Unenroll'}
                    </button>
                  </>
                )}
              </div>
            </>
          )}
        </div>
      </Section>

      {/* ── Section 2: Subdomain ──────────────────────────────────────── */}
      <Section
        title="Subdomain"
        expanded={expanded.subdomain}
        onToggle={() => toggleSection('subdomain')}
        statusSummary={
          subdomainLoading
            ? 'Loading…'
            : subdomainStatus === null
              ? 'Unable to load'
              : subdomainStatus.claimed
                ? `Claimed${subdomainStatus.subdomain ? ` (${subdomainStatus.subdomain})` : ''}`
                : 'Not claimed'
        }
      >
        <div className="remote-access__card">
          {subdomainLoading ? (
            <p role="status" aria-live="polite">Loading subdomain status…</p>
          ) : subdomainStatus === null ? (
            <p className="remote-access__empty">Unable to load subdomain status.</p>
          ) : (
            <>
              {subdomainStatus.claimed && (
                <dl className="remote-access__dl">
                  {subdomainStatus.subdomain && (
                    <>
                      <dt>Subdomain</dt>
                      <dd>{subdomainStatus.subdomain}</dd>
                    </>
                  )}
                  {subdomainStatus.fqdn && (
                    <>
                      <dt>FQDN</dt>
                      <dd>{subdomainStatus.fqdn}</dd>
                    </>
                  )}
                </dl>
              )}

              <div className="remote-access__card-actions">
                {!subdomainStatus.claimed ? (
                  <button
                    type="button"
                    className="btn--primary"
                    onClick={() => void claimSubdomain()}
                    disabled={subdomainClaiming}
                    aria-busy={subdomainClaiming}
                  >
                    {subdomainClaiming ? 'Claiming…' : 'Claim Subdomain'}
                  </button>
                ) : (
                  <button
                    type="button"
                    className="btn--danger"
                    onClick={() => void releaseSubdomain()}
                    disabled={subdomainReleasing}
                    aria-busy={subdomainReleasing}
                  >
                    {subdomainReleasing ? 'Releasing…' : 'Release Subdomain'}
                  </button>
                )}
              </div>
            </>
          )}
        </div>
      </Section>

      {/* ── Section 3: Relay Tunnel ─────────────────────────────────────── */}
      <Section
        title="Relay Tunnel"
        expanded={expanded.relay}
        onToggle={() => toggleSection('relay')}
        statusSummary={
          relayLoading
            ? 'Loading…'
            : relayStatus === null
              ? 'Unable to load'
              : relayStatus.connected
                ? `Connected${relayLatency !== null ? ` (${relayLatency}ms latency)` : ''}`
                : 'Disconnected'
        }
      >
        <div className="remote-access__card">
          {relayLoading ? (
            <p role="status" aria-live="polite">Loading relay status…</p>
          ) : relayStatus === null ? (
            <p className="remote-access__empty">Unable to load relay status.</p>
          ) : (
            <>
              <dl className="remote-access__dl">
                <dt>Status</dt>
                <dd>{relayStatus.connected ? 'Connected' : 'Disconnected'}</dd>
                <dt>Active</dt>
                <dd>{relayStatus.active ? 'Yes' : 'No'}</dd>
                {relayLatency !== null && (
                  <>
                    <dt>Latency</dt>
                    <dd>{relayLatency}ms</dd>
                  </>
                )}
              </dl>

              <div className="remote-access__card-actions">
                <button
                  type="button"
                  className="btn--secondary"
                  onClick={() => void pingRelay()}
                  disabled={relayPinging || !relayStatus.connected}
                  aria-busy={relayPinging}
                >
                  {relayPinging ? 'Pinging…' : 'Ping'}
                </button>
                {!relayStatus.connected ? (
                  <button
                    type="button"
                    className="btn--primary"
                    onClick={() => void enableRelay()}
                    disabled={isRelayActionInProgress}
                    aria-busy={relayEnabling}
                  >
                    {relayEnabling ? 'Enabling…' : 'Enable'}
                  </button>
                ) : (
                  <button
                    type="button"
                    className="btn--danger"
                    onClick={() => void disableRelay()}
                    disabled={isRelayActionInProgress}
                    aria-busy={relayDisabling}
                  >
                    {relayDisabling ? 'Disabling…' : 'Disable'}
                  </button>
                )}
              </div>
            </>
          )}
        </div>
      </Section>

      {/* ── Section 4: Port Forward ───────────────────────────────────── */}
      <Section
        title="Port Forward"
        expanded={expanded.portforward}
        onToggle={() => toggleSection('portforward')}
        statusSummary={
          portForwardLoading
            ? 'Loading…'
            : portForwardStatus === null
              ? 'Unable to load'
              : portForwardStatus.enabled
                ? `Enabled${portForwardStatus.externalIp ? ` (${portForwardStatus.externalIp}:${portForwardStatus.externalPort})` : ''}`
                : 'Disabled'
        }
      >
        <div className="remote-access__card">
          {portForwardLoading ? (
            <p role="status" aria-live="polite">Loading port-forward status…</p>
          ) : portForwardStatus === null ? (
            <p className="remote-access__empty">Unable to load port-forward status.</p>
          ) : (
            <>
              <dl className="remote-access__dl">
                <dt>Enabled</dt>
                <dd>{portForwardStatus.enabled ? 'Yes' : 'No'}</dd>
                {portForwardStatus.method && (
                  <>
                    <dt>Method</dt>
                    <dd>{portForwardStatus.method}</dd>
                  </>
                )}
                {portForwardStatus.externalIp && (
                  <>
                    <dt>External IP</dt>
                    <dd>{portForwardStatus.externalIp}</dd>
                  </>
                )}
                {portForwardStatus.externalPort && (
                  <>
                    <dt>External Port</dt>
                    <dd>{portForwardStatus.externalPort}</dd>
                  </>
                )}
              </dl>

              {candidates.length > 0 && (
                <div className="remote-access__candidates">
                  <h3>Hostname Candidates</h3>
                  <ul className="remote-access__candidates-list">
                    {candidates.map((candidate, index) => (
                      <li key={index}>{candidate.hostname}</li>
                    ))}
                  </ul>
                </div>
              )}

              <div className="remote-access__card-actions">
                {!portForwardStatus.enabled ? (
                  <button
                    type="button"
                    className="btn--primary"
                    onClick={() => void enablePortForward()}
                    disabled={isPortForwardActionInProgress}
                    aria-busy={portForwardEnabling}
                  >
                    {portForwardEnabling ? 'Enabling…' : 'Enable'}
                  </button>
                ) : (
                  <button
                    type="button"
                    className="btn--danger"
                    onClick={() => void disablePortForward()}
                    disabled={isPortForwardActionInProgress}
                    aria-busy={portForwardDisabling}
                  >
                    {portForwardDisabling ? 'Disabling…' : 'Disable'}
                  </button>
                )}
              </div>
            </>
          )}
        </div>
      </Section>

      {/* ── Pairing Modal ──────────────────────────────────────────────── */}
      {showPairModal && (
        <div className="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="pairing-modal-title">
          <div className="modal">
            <div className="modal__header">
              <h2 id="pairing-modal-title" className="modal__title">Initiate Hub Pairing</h2>
              <button
                type="button"
                className="modal__close"
                onClick={() => setShowPairModal(false)}
                aria-label="Close modal"
              >
                &#x2715;
              </button>
            </div>
            <div className="modal__body">
              {pairingClaimCode ? (
                <>
                  <p>Enter this claim code on the hub:</p>
                  <p className="remote-access__claim-code">{pairingClaimCode}</p>
                  <div className="remote-access__card-actions">
                    <button
                      type="button"
                      className="btn--primary"
                      onClick={() => void pollPairing()}
                      disabled={pairingPolling}
                      aria-busy={pairingPolling}
                    >
                      {pairingPolling ? 'Polling…' : 'Poll for Completion'}
                    </button>
                  </div>
                </>
              ) : (
                <>
                  <div className="form__field">
                    <label htmlFor="pairing-hub-url">Hub URL</label>
                    <input
                      id="pairing-hub-url"
                      type="url"
                      className="form__input"
                      value={pairingHubUrl}
                      onChange={e => setPairingHubUrl(e.target.value)}
                      placeholder="https://hub.example.com"
                    />
                  </div>
                  <div className="form__field">
                    <label htmlFor="pairing-server-name">Server Name</label>
                    <input
                      id="pairing-server-name"
                      type="text"
                      className="form__input"
                      value={pairingServerName}
                      onChange={e => setPairingServerName(e.target.value)}
                      placeholder="Phlix Server"
                    />
                  </div>
                  <div className="remote-access__card-actions">
                    <button
                      type="button"
                      className="btn--primary"
                      onClick={() => void initiatePairing()}
                      disabled={hubPairing || pairingHubUrl === ''}
                      aria-busy={hubPairing}
                    >
                      {hubPairing ? 'Initiating…' : 'Initiate Pairing'}
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>
      )}
    </section>
  );
}
