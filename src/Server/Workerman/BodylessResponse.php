<?php

/**
 * Phlix media server component: Server.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Workerman;

use Stringable;
use Workerman\Protocols\Http\Response as WorkermanResponse;

use function array_keys;
use function is_array;
use function is_scalar;
use function is_string;
use function strpbrk;
use function strtolower;

/**
 * A Workerman response whose caller-supplied `Content-Length` is AUTHORITATIVE
 * even though the body is empty — i.e. a correct `HEAD` reply.
 *
 * ## The defect this exists to prevent
 *
 * `Workerman\Protocols\Http\Response::__toString()`
 * (`vendor/workerman/workerman/src/Protocols/Http/Response.php:580-583`) appends
 * its own `Content-Length: strlen($body)` **unconditionally** — after emitting
 * every header the caller set, and regardless of whether the caller already set
 * one. It is the right default for a normal buffered reply, because `$body` IS
 * the entity. It is wrong for a `HEAD`, where RFC 9110 §9.3.2 requires the
 * `Content-Length` the equivalent `GET` would have returned while forbidding a
 * body. Setting the real size and returning an empty body therefore puts BOTH
 * values on the wire:
 *
 * ```
 * HTTP/1.1 200 OK
 * Content-Type: video/x-matroska
 * Accept-Ranges: bytes
 * Content-Length: 4242          <- the real size, from the caller
 * Connection: keep-alive
 * Content-Length: 0             <- appended by Workerman, and it comes LAST
 * ```
 *
 * RFC 9110 §8.6 makes a message carrying conflicting `Content-Length` field
 * values invalid: recipients must treat it as unrecoverable, hardened proxies
 * (HAProxy) reject it as a request-smuggling defence, and clients disagree about
 * which value wins. For DLNA that is fatal in practice rather than in theory —
 * renderers probe a resource with `HEAD` *before* they open it, so the reply that
 * is supposed to advertise the size is the one that breaks.
 *
 * ## Working with the framework rather than against it
 *
 * Workerman offers no flag for "these headers are final": the only two escapes in
 * `__toString()` are a file-backed response (which sends the body, so it cannot
 * serve a `HEAD`) and `Transfer-Encoding` (which RFC 9112 §6.1 forbids alongside
 * `Content-Length`, so it would swap one invalid message for another). Narrowing
 * `__toString()` in a subclass is therefore the intended extension point.
 *
 * The narrowing is deliberately as small as it can be, and it is narrowed twice
 * over:
 *
 *  1. {@see \Phlix\Server\Http\Response::toWorkermanResponse()} selects this class
 *     **only** for a `HEAD` reply (`Response::$headOnly`) — never for a GET that
 *     merely happens to have an empty body, because treating a stale non-zero
 *     `Content-Length` as authoritative on a GET would be a keep-alive framing
 *     desync rather than a fix (see that method's docblock);
 *  2. {@see self::contentLengthIsAuthoritative()} then delegates straight back to
 *     `parent::__toString()` unless the response is the exact shape the parent
 *     renders invalidly, so a `HEAD` that declares no length at all still gets the
 *     framework's `Content-Length: 0` exactly as before.
 *
 * Between them, a 204, a 304, a redirect, a 416 and every ordinary GET are
 * byte-identical to the parent encoder, and only a caller-sized bodyless `HEAD`
 * reply is rendered here.
 *
 * ## Resident-memory / event-loop notes
 *
 * Pure header rendering: no I/O, no state, no statics. A `HEAD` served this way
 * never opens the file it describes.
 *
 * @package Phlix\Server\Workerman
 * @since 1.7.0
 */
class BodylessResponse extends WorkermanResponse
{
    /**
     * Render the response, suppressing Workerman's generated `Content-Length`.
     *
     * Mirrors the parent's header emission for every value shape
     * {@see \Phlix\Server\Http\Response::header()} can produce — same order, same
     * CR/LF/colon sanitisation, same `Connection: keep-alive` and `Content-Type`
     * defaults — and then terminates the head. It never appends a `Content-Length`
     * and never writes a body.
     *
     * Anything that is NOT a bodyless, caller-sized reply is delegated to the
     * parent unchanged, so this class is safe to use as a drop-in.
     */
    public function __toString(): string
    {
        if (!$this->contentLengthIsAuthoritative()) {
            return parent::__toString();
        }

        $reason = $this->reason ?: (self::PHRASES[$this->status] ?? '');
        $head = "HTTP/{$this->version} {$this->status} {$reason}\r\n";

        foreach ($this->headers as $name => $value) {
            $fieldName = (string) $name;
            // Skip unsafe header names (parity with the parent).
            if (strpbrk($fieldName, ":\r\n") !== false) {
                continue;
            }

            if (is_array($value)) {
                /** @var mixed $item */
                foreach ($value as $item) {
                    $rendered = self::renderValue($item);
                    if ($rendered === null) {
                        continue;
                    }
                    $head .= "{$fieldName}: {$rendered}\r\n";
                }
                continue;
            }

            $rendered = self::renderValue($value);
            if ($rendered === null) {
                continue;
            }
            $head .= "{$fieldName}: {$rendered}\r\n";
        }

        if (!$this->hasHeader('Connection')) {
            $head .= "Connection: keep-alive\r\n";
        }
        if (!$this->hasHeader('Content-Type')) {
            $head .= "Content-Type: text/html;charset=utf-8\r\n";
        }

        // No generated Content-Length, and no body: the caller's field value is
        // the size of the entity a GET would have returned.
        return $head . "\r\n";
    }

    /**
     * Whether the caller's `Content-Length` must be preserved verbatim.
     *
     * True only for the exact shape the parent renders invalidly: no file body,
     * an empty in-memory body, an explicit `Content-Length`, and no
     * `Transfer-Encoding` (which the parent already honours by skipping its own
     * `Content-Length`). Server-Sent Events are excluded because the parent
     * deliberately returns a head-only string for them already.
     *
     * This is the INNER of two guards. The outer one is the `headOnly` selector in
     * {@see \Phlix\Server\Http\Response::toWorkermanResponse()}; this one keeps the
     * class a safe drop-in even when it is constructed directly, as
     * {@see \Phlix\Server\Workerman\HttpHandler::serveMediaStream()} does — it
     * returns Workerman responses rather than Phlix ones, so it has no `headOnly`
     * flag to set and names this class explicitly instead.
     */
    private function contentLengthIsAuthoritative(): bool
    {
        if ($this->file !== null || $this->body !== '' || $this->headers === []) {
            return false;
        }
        if (!$this->hasHeader('Content-Length') || $this->hasHeader('Transfer-Encoding')) {
            return false;
        }

        return self::renderValue($this->headers['Content-Type'] ?? null) !== 'text/event-stream';
    }

    /**
     * Case-insensitive header presence test.
     *
     * The parent's own `isset($headers['Connection'])` checks are case-SENSITIVE;
     * matching case-insensitively here can only ever suppress a DUPLICATE field,
     * never introduce one, so it is the safe direction to diverge in.
     *
     * @param string $name Field name to look for.
     */
    private function hasHeader(string $name): bool
    {
        $needle = strtolower($name);
        foreach (array_keys($this->headers) as $candidate) {
            if (strtolower((string) $candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render one header value, or null when it cannot safely be emitted.
     *
     * Values containing CR/LF are dropped exactly as the parent drops them —
     * that is the header-injection guard, and it must not be weakened here.
     *
     * @param mixed $value Raw header value from {@see self::$headers}.
     */
    private static function renderValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $rendered = $value;
        } elseif (is_scalar($value)) {
            $rendered = (string) $value;
        } elseif ($value instanceof Stringable) {
            $rendered = (string) $value;
        } else {
            return null;
        }

        return strpbrk($rendered, "\r\n") === false ? $rendered : null;
    }
}
