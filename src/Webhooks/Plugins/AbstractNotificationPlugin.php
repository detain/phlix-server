<?php

/**
 * Phlix media server component: Plugins.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Webhooks\Plugins;

use InvalidArgumentException;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Webhooks\WebhookEvent;
use Phlix\Webhooks\WebhookPluginInterface;

/**
 * Abstract base class for notification plugins.
 *
 * Provides common formatting helpers and enforces the contract
 * that subclasses implement buildPayload().
 *
 * ## The egress law (family-wide)
 *
 * Every outbound URL a plugin is about to POST to passes through
 * {@see guardEgressUrl()} at the moment of use — the same
 * {@see SsrfGuard} gate the webhook surfaces enforce at create and
 * re-enforce on every delivery (SRV-M20). A config file that once
 * named a public endpoint and was since edited (or shipped) to point
 * at loopback, link-local metadata, or a private range must be
 * refused at send time, not trusted because it was acceptable when
 * written. Refusal shape: one logged warning, zero bytes out, send()
 * returns false.
 *
 * ## The redirect law (family-wide, review server__secaudit U1)
 *
 * The guard checks the configured URL only; PHP's http wrapper would
 * otherwise follow up to 20 redirect hops of whatever answer that
 * endpoint gives, and a public endpoint answering
 * `302 Location: http://169.254.169.254/…` smuggles bytes past a
 * check that passed at hop one. The family therefore denies redirects
 * at the transport: every guarded egress context is built through
 * {@see buildEgressHttpOptions()} with `follow_location=0` and
 * `max_redirects=0`, so the wrapper never requests a redirect target.
 * A 3xx terminal answer is then refused by {@see egressSucceeded()}
 * with one logged warning and send() false — the same shape as a
 * guard refusal, and the only 3xx the plugin can ever observe is the
 * one it declined to follow.
 *
 * ## Check-time depth (honest residual)
 *
 * {@see SsrfGuard::assertPublicUrl()} resolves the host at CHECK time
 * while the transport re-resolves independently at CONNECT time; there
 * is no IP pinning between the two. What the moment-of-use gate closes
 * is edited-in or persistently poisoned configuration (and, via the
 * redirect law above, the multi-hop smuggling variant); a DNS answer
 * that flips inside the sub-second window between the guard's lookup
 * and the socket's connect is the same bounded TOCTOU residual the
 * webhook surfaces carry — one connection, no redirect hop.
 */
abstract class AbstractNotificationPlugin implements WebhookPluginInterface
{
    /**
     * Moment-of-use SSRF gate for a fully-composed egress URL.
     *
     * The family's single-source predicate: subclasses call this with
     * the exact URL string that is about to reach file_get_contents()
     * and skip the transport entirely when it returns false. Reuses
     * the MqttPlugin guard idiom (SRV-M20 sibling) so a poisoned
     * destination — edited-in config, a resolver that persistently
     * answers a denied range at check time, or a link-local literal —
     * never leaves the host. The guard resolves the host itself at
     * check time (see "Check-time depth" above for the connect-time
     * residual); IP literals need no DNS, so tests run network-free.
     *
     * @param string       $url   fully-composed http(s) destination
     * @param WebhookEvent $event event being delivered (log context)
     *
     * @return bool true when the URL passed the guard and egress is allowed
     */
    protected function guardEgressUrl(string $url, WebhookEvent $event): bool
    {
        try {
            SsrfGuard::assertPublicUrl($url);
        } catch (InvalidArgumentException $exception) {
            /* The URL may embed a credential (Slack/Discord webhook paths,
             * the Telegram bot token in the path). The guard's refusal
             * message quotes the URL it examined — redact it from the log
             * line so the reason (scheme/host verdict) survives without the
             * secret. */
            LoggerFactory::get(LogChannels::APPLICATION)->warning(
                ucfirst(static::getName()) . ' notification blocked by SSRF guard',
                [
                    'event_type' => $event->eventType,
                    'reason' => str_replace($url, '<destination-redacted>', $exception->getMessage()),
                ]
            );
            return false;
        }

        return true;
    }

    /**
     * The non-negotiable keys of every guarded egress HTTP context.
     *
     * `follow_location=0` + `max_redirects=0` deny the wrapper's own
     * redirect walking (default: follow up to 20 hops with no per-hop
     * re-check — the same 302→metadata exfiltration class the estate
     * legislated against in WebhookHttpClient R20-01 and
     * ProviderUrlAllowlist). `timeout`/`ignore_errors` are the family
     * shape every send site already used; centralizing them keeps the
     * law in one file. Union-operator precedence (law array on the
     * left) means a site cannot override a law key.
     *
     * @var array<string, mixed>
     */
    private const EGRESS_TRANSPORT_LAW = [
        'follow_location' => 0,
        'max_redirects' => 0,
        'timeout' => 10,
        'ignore_errors' => true,
    ];

    /**
     * The family's single-source HTTP transport option block.
     *
     * Sites pass only what varies (method, header, content); the
     * redirect denial and the shared timeouts come from
     * {@see self::EGRESS_TRANSPORT_LAW} and cannot be overridden.
     *
     * @param array<string, mixed> $options site-specific http options
     *
     * @return array<string, mixed> complete http context options
     */
    protected function buildEgressHttpOptions(array $options): array
    {
        return self::EGRESS_TRANSPORT_LAW + $options;
    }

    /**
     * Parses the terminal status code from the wrapper's response
     * headers.
     *
     * PHP populates `$http_response_header` in the LOCAL scope of the
     * function that called `file_get_contents()` — a `global` read of
     * it from a helper always saw nothing (the pre-U1 per-site
     * private copies did exactly that, so every send reported failure
     * even on a real 200). The value must therefore be passed in by
     * the call site.
     *
     * @param string $statusLine the call site's `$http_response_header[0]`
     *                            ('' when no response was received at all)
     *
     * @return int|null status code of the first status line, null when
     *                  no response was received at all
     */
    protected function terminalResponseCode(string $statusLine): ?int
    {
        if ($statusLine !== '' && preg_match('/HTTP\/\d+\.?\d*\s+(\d+)/', $statusLine, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Post-flight evaluation of one guarded egress attempt.
     *
     * Success means exactly one thing: the terminal status is 200 and
     * bytes came back. Because {@see self::EGRESS_TRANSPORT_LAW}
     * denies the wrapper's redirect walking, the only 3xx a plugin can
     * ever observe is a redirect the endpoint tried to hand it — the
     * moment-of-use guard cannot see a hop the caller never asked for,
     * so the refusal happens HERE: one logged warning (naming no URL —
     * the redirect's Location may carry credentials just like the
     * configured path does), send() false, zero bytes to the target.
     *
     * @param WebhookEvent $event      event being delivered
     * @param string|false $response   body returned by file_get_contents()
     * @param string       $statusLine the call site's $http_response_header[0]
     *                                 ('' when the wrapper received nothing)
     *
     * @return bool true only for a 200 answer from the guarded URL
     */
    protected function egressSucceeded(WebhookEvent $event, string|false $response, string $statusLine): bool
    {
        $code = $this->terminalResponseCode($statusLine);

        if ($code !== null && $code >= 300 && $code < 400) {
            LoggerFactory::get(LogChannels::APPLICATION)->warning(
                ucfirst(static::getName()) . ' notification refused: HTTP redirect not followed',
                ['event_type' => $event->eventType]
            );

            return false;
        }

        return $response !== false && $code === 200;
    }

    /**
     * Formats the event as a human-readable message string.
     */
    protected function formatMessage(WebhookEvent $event): string
    {
        $type = $event->eventType;
        $payload = $event->payload;

        $title = $this->getTitleFromPayload($payload);
        $description = $this->getDescriptionFromPayload($payload);

        $message = ucfirst(str_replace('.', ' ', $type));
        if ($title !== '') {
            $message .= ": {$title}";
        }
        if ($description !== '' && $description !== $title) {
            $message .= " — {$description}";
        }

        return $message;
    }

    /**
     * Returns an RGB integer color based on event type.
     */
    protected function getEmbedColor(WebhookEvent $event): int
    {
        return match ($event->eventType) {
            'playback.started' => 0x2ECC71,   // Green
            'playback.ended' => 0x3498DB,      // Blue
            'library.updated' => 0x9B59B6,     // Purple
            'download.complete' => 0xE67E22,  // Orange
            'recording.started' => 0xE74C3C,  // Red
            'recording.stopped' => 0x95A5A6,  // Gray
            'alert' => 0xE74C3C,              // Red
            default => 0x34495E,               // Dark gray
        };
    }

    /**
     * Extracts a title from the event payload.
     *
     * @param array<string, mixed> $payload
     */
    protected function getTitleFromPayload(array $payload): string
    {
        return $this->stringFromMixed($payload['title'] ?? $payload['name'] ?? $payload['media_title'] ?? '');
    }

    /**
     * Extracts a description from the event payload.
     *
     * @param array<string, mixed> $payload
     */
    protected function getDescriptionFromPayload(array $payload): string
    {
        return $this->stringFromMixed(
            $payload['description'] ?? $payload['summary'] ?? $payload['message'] ?? ''
        );
    }

    /**
     * Extracts a media thumbnail URL from the event payload.
     *
     * @param array<string, mixed> $payload
     */
    protected function getThumbnailFromPayload(array $payload): string
    {
        return $this->stringFromMixed($payload['thumbnail'] ?? $payload['poster'] ?? '');
    }

    /**
     * @param mixed $value
     */
    protected function stringFromMixed(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return strval($value);
        }
        return '';
    }

    /**
     * Default path to the system CA bundle used for outbound TLS
     * verification when no override is provided in configuration.
     */
    public const DEFAULT_CA_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';

    /**
     * Build a stream context SSL block with `verify_peer` and
     * `verify_peer_name` enabled.
     *
     * Notification plugins call out to third-party HTTPS endpoints
     * (Discord, Slack, Telegram, …) and MUST verify the peer certificate
     * chain. The CA bundle path is overridable via the notifications
     * config (`<provider>.ca_bundle` or top-level `ca_bundle`) so admins
     * can point Phlix at a private CA. Defaults to the standard Debian
     * system bundle.
     *
     * @param array<string, mixed> $providerConfig Per-provider config slice
     *
     * @return array<string, mixed> ssl context options
     */
    protected function buildSslContextOptions(array $providerConfig = []): array
    {
        $caBundle = $this->stringFromMixed(
            $providerConfig['ca_bundle']
            ?? $this->resolveDefaultCaBundle()
        );

        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'cafile' => $caBundle,
            'SNI_enabled' => true,
        ];
    }

    /**
     * Resolve the default CA bundle path, preferring an admin override
     * from the global `notifications.php` config file when present.
     */
    private function resolveDefaultCaBundle(): string
    {
        $configPath = defined('PHLIX_CONFIG_PATH') ? PHLIX_CONFIG_PATH : __DIR__ . '/../../../config';
        $configFile = $configPath . '/notifications.php';
        if (file_exists($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;
            if (is_string($config['ca_bundle'] ?? null) && $config['ca_bundle'] !== '') {
                return $config['ca_bundle'];
            }
        }
        return self::DEFAULT_CA_BUNDLE;
    }

    /**
     * Builds the platform-specific payload for the given event.
     *
     * @return array<string, mixed>
     */
    abstract protected function buildPayload(WebhookEvent $event): array;
}
