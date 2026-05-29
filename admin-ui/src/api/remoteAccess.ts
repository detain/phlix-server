/**
 * RemoteAccessApi — typed wrapper over the admin remote access endpoints
 * for hub pairing, subdomain management, relay tunnel, and port-forward
 * configuration (`/api/v1/admin/remote/...`).
 *
 * @since 2.3
 */
import type { ApiClient } from './client';

// ─── Hub pairing types ────────────────────────────────────────────────────────

/**
 * Shape of the GET /api/v1/admin/remote/hub/status response.
 * @since 2.3
 */
export interface HubStatus {
  paired: boolean;
  serverId?: string;
  hubUrl?: string;
  enrolledAt?: string;
  lastHeartbeat?: string;
}

/**
 * Shape of the POST /api/v1/admin/remote/hub/pair response (initiate pairing).
 * @since 2.3
 */
export interface HubPairResponse {
  success: boolean;
  serverId: string;
  hubUrl: string;
  claimCode?: string;
  claimId?: string;
  expiresIn?: number;
}

/**
 * Shape of the POST /api/v1/admin/remote/hub/poll response.
 * @since 2.3
 */
export interface HubPollResponse {
  success: boolean;
  token?: string;
  serverId?: string;
  message?: string;
}

/**
 * Shape of the POST /api/v1/admin/remote/hub/heartbeat response.
 * @since 2.3
 */
export interface HubHeartbeatResponse {
  success: boolean;
  receivedAt: string;
}

// ─── Subdomain types ─────────────────────────────────────────────────────────

/**
 * Shape of the GET /api/v1/admin/remote/subdomain/status response.
 * @since 2.3
 */
export interface SubdomainStatus {
  claimed: boolean;
  subdomain?: string;
  fqdn?: string;
  certPath?: string;
  keyPath?: string;
}

/**
 * Shape of the POST /api/v1/admin/remote/subdomain/claim response.
 * @since 2.3
 */
export interface SubdomainClaimResponse {
  success: boolean;
  subdomain: string;
  fqdn: string;
}

// ─── Relay tunnel types ───────────────────────────────────────────────────────

/**
 * Shape of the GET /api/v1/admin/remote/relay/status response.
 * @since 2.3
 */
export interface RelayStatus {
  connected: boolean;
  active: boolean;
  endpoint?: string;
  establishedAt?: string;
}

/**
 * Shape of the POST /api/v1/admin/remote/relay/ping response.
 * @since 2.3
 */
export interface RelayPingResponse {
  success: boolean;
  latencyMs: number;
}

// ─── Port forward types ───────────────────────────────────────────────────────

/**
 * Shape of the GET /api/v1/admin/remote/portforward/status response.
 * @since 2.3
 */
export interface PortForwardStatus {
  enabled: boolean;
  method?: string;
  externalIp?: string;
  externalPort?: number;
  hostname?: string;
}

/**
 * Shape of a hostname candidate.
 * @since 2.3
 */
export interface HostnameCandidate {
  hostname: string;
  externalIp: string;
  port: number;
}

/**
 * Shape of the GET /api/v1/admin/remote/portforward/candidates response.
 * @since 2.3
 */
export interface PortForwardCandidatesResponse {
  candidates: HostnameCandidate[];
}

// ─── API class ────────────────────────────────────────────────────────────────

/**
 * Typed client for the admin remote access endpoints.
 *
 * @since 2.3
 */
export class RemoteAccessApi {
  constructor(private readonly client: ApiClient) {}

  // ─── Hub pairing ────────────────────────────────────────────────────────────

  /**
   * `GET /api/v1/admin/remote/hub/status` → current enrollment status.
   */
  async hubStatus(): Promise<HubStatus> {
    return this.client.get<HubStatus>('/api/v1/admin/remote/hub/status');
  }

  /**
   * `POST /api/v1/admin/remote/hub/pair` → initiate pairing.
   * @param hubUrl - The hub base URL.
   * @param serverName - Human-readable server name for the hub dashboard.
   */
  async hubPair(hubUrl: string, serverName: string): Promise<HubPairResponse> {
    return this.client.post<HubPairResponse>('/api/v1/admin/remote/hub/pair', {
      hubUrl,
      serverName,
    });
  }

  /**
   * `POST /api/v1/admin/remote/hub/poll` → poll for claim completion.
   * @param claimId - The claim ID from initiatePairing.
   * @param hubUrl - The hub base URL.
   */
  async hubPoll(claimId: string, hubUrl: string): Promise<HubPollResponse> {
    return this.client.post<HubPollResponse>('/api/v1/admin/remote/hub/poll', {
      claimId,
      hubUrl,
    });
  }

  /**
   * `POST /api/v1/admin/remote/hub/complete` → complete pairing by storing enrollment.
   * @param enrollmentJwt - JWT from the hub's claim response.
   * @param hubJwksUrl - URL of the hub's JWKS document.
   * @param serverId - Hub-assigned server UUID.
   * @param hubUrl - Hub's base URL.
   */
  async hubComplete(
    enrollmentJwt: string,
    hubJwksUrl: string,
    serverId: string,
    hubUrl: string,
  ): Promise<{ success: boolean }> {
    return this.client.post<{ success: boolean }>(
      '/api/v1/admin/remote/hub/complete',
      {
        enrollmentJwt,
        hubJwksUrl,
        serverId,
        hubUrl,
      },
    );
  }

  /**
   * `POST /api/v1/admin/remote/hub/unenroll` → unenroll from the hub.
   */
  async hubUnenroll(): Promise<{ success: boolean }> {
    return this.client.post<{ success: boolean }>('/api/v1/admin/remote/hub/unenroll');
  }

  /**
   * `POST /api/v1/admin/remote/hub/heartbeat` → send a heartbeat to the hub.
   */
  async hubHeartbeat(): Promise<HubHeartbeatResponse> {
    return this.client.post<HubHeartbeatResponse>('/api/v1/admin/remote/hub/heartbeat');
  }

  // ─── Subdomain ───────────────────────────────────────────────────────────────

  /**
   * `GET /api/v1/admin/remote/subdomain/status` → current subdomain status.
   */
  async subdomainStatus(): Promise<SubdomainStatus> {
    return this.client.get<SubdomainStatus>('/api/v1/admin/remote/subdomain/status');
  }

  /**
   * `POST /api/v1/admin/remote/subdomain/claim` → claim a subdomain from the hub.
   */
  async subdomainClaim(): Promise<SubdomainClaimResponse> {
    return this.client.post<SubdomainClaimResponse>('/api/v1/admin/remote/subdomain/claim');
  }

  /**
   * `POST /api/v1/admin/remote/subdomain/release` → release the claimed subdomain.
   */
  async subdomainRelease(): Promise<{ success: boolean }> {
    return this.client.post<{ success: boolean }>('/api/v1/admin/remote/subdomain/release');
  }

  // ─── Relay tunnel ───────────────────────────────────────────────────────────

  /**
   * `GET /api/v1/admin/remote/relay/status` → current relay tunnel status.
   */
  async relayStatus(): Promise<RelayStatus> {
    return this.client.get<RelayStatus>('/api/v1/admin/remote/relay/status');
  }

  /**
   * `POST /api/v1/admin/remote/relay/enable` → enable the relay tunnel.
   */
  async relayEnable(): Promise<{ success: boolean }> {
    return this.client.post<{ success: boolean }>('/api/v1/admin/remote/relay/enable');
  }

  /**
   * `POST /api/v1/admin/remote/relay/disable` → disable the relay tunnel.
   */
  async relayDisable(): Promise<{ success: boolean }> {
    return this.client.post<{ success: boolean }>('/api/v1/admin/remote/relay/disable');
  }

  /**
   * `POST /api/v1/admin/remote/relay/ping` → ping the relay tunnel.
   */
  async relayPing(): Promise<RelayPingResponse> {
    return this.client.post<RelayPingResponse>('/api/v1/admin/remote/relay/ping');
  }

  // ─── Port forward ───────────────────────────────────────────────────────────

  /**
   * `GET /api/v1/admin/remote/portforward/status` → current port-forward status.
   */
  async portForwardStatus(): Promise<PortForwardStatus> {
    return this.client.get<PortForwardStatus>('/api/v1/admin/remote/portforward/status');
  }

  /**
   * `POST /api/v1/admin/remote/portforward/enable` → enable port forwarding.
   */
  async portForwardEnable(): Promise<{ success: boolean }> {
    return this.client.post<{ success: boolean }>('/api/v1/admin/remote/portforward/enable');
  }

  /**
   * `POST /api/v1/admin/remote/portforward/disable` → disable port forwarding.
   */
  async portForwardDisable(): Promise<{ success: boolean }> {
    return this.client.post<{ success: boolean }>('/api/v1/admin/remote/portforward/disable');
  }

  /**
   * `GET /api/v1/admin/remote/portforward/candidates` → hostname candidates.
   */
  async portForwardCandidates(): Promise<PortForwardCandidatesResponse> {
    return this.client.get<PortForwardCandidatesResponse>(
      '/api/v1/admin/remote/portforward/candidates',
    );
  }
}
