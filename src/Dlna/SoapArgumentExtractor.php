<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Dlna;

use SimpleXMLElement;

/**
 * SoapArgumentExtractor — namespace-aware, direct-child SOAP argument extraction.
 *
 * Shared by every UPnP/DLNA SOAP control path so they cannot diverge:
 *  - {@see \Phlix\Server\Http\Controllers\Dlna\DlnaContentDirectoryController}
 *    — **the live one**: `POST /dlna/content_directory`, registered in
 *    `Application::loadCdsRoutes()`. This is the path a real control point
 *    takes, and the only one whose coverage means anything in production; and
 *  - {@see DlnaServer::processSoapRequest()} — ⚠ **no production caller**
 *    (S218). Kept as a shared implementation so the two cannot drift, but see
 *    that method's docblock before writing a test against it: a test that
 *    exercises it proves nothing about the served endpoint.
 *
 * The core guarantee: an argument value is only ever read from a DIRECT CHILD
 * of the SOAP action element (which is itself located as a direct child of the
 * SOAP `Body`). Scoping to direct children — rather than the previous loose
 * "first text node for each local-name ANYWHERE in the document" walk — stops
 * embedded DIDL-Lite metadata (e.g. a nested same-named `<InstanceID>` /
 * `<ObjectID>` buried inside `<Result>` or `<CurrentURIMetaData>`) from bleeding
 * into a top-level argument.
 *
 * XML is parsed with external-entity substitution left OFF (no `LIBXML_NOENT`)
 * and `LIBXML_NONET`, so a hostile control point cannot turn the parser into an
 * XXE / SSRF vector.
 *
 * @since 1.2.4
 */
final class SoapArgumentExtractor
{
    /**
     * Parse a SOAP body into a SimpleXML root element, XXE-safely.
     *
     * External entities are never substituted (no `LIBXML_NOENT`) and network
     * access during parsing is forbidden (`LIBXML_NONET`), closing the XXE
     * vector regardless of what a control point sends.
     *
     * @param string $body Raw SOAP XML body.
     * @return SimpleXMLElement|null Root element, or null when the body is not
     *                               well-formed XML.
     *
     * @since 1.2.4
     */
    public static function loadBody(string $body): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $doc === false ? null : $doc;
    }

    /**
     * The SOAP action element sitting directly under the SOAP `Body`.
     *
     * The `Body` is matched by LOCAL name, so `<s:Body>`, `<SOAP-ENV:Body>` and
     * a bare `<Body>` are all handled; the FIRST element child of that Body (in
     * document order) is returned whatever prefix — or none — the action itself
     * carries. Returns null when there is no Body child, preserving the "reject"
     * behavior of callers that require a full envelope.
     *
     * @param string $body Raw SOAP XML body.
     * @return SimpleXMLElement|null The action element, or null.
     *
     * @since 1.2.4
     */
    public static function firstBodyChild(string $body): ?SimpleXMLElement
    {
        $doc = self::loadBody($body);
        if ($doc === null) {
            return null;
        }

        $matches = $doc->xpath("//*[local-name()='Body']/*");
        if (is_array($matches) && $matches !== []) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Locate a SOAP action element by LOCAL name, namespace-agnostically.
     *
     * Prefers the action element sitting directly under the SOAP `Body` (any
     * prefix), then falls back to a bare action element posted without the
     * envelope wrapper (some minimalist control points do this). Returns null
     * when the body is not well-formed or the action element is absent.
     *
     * @param string $body Raw SOAP XML body.
     * @param string $action The action's local name (e.g. `Browse`).
     * @return SimpleXMLElement|null The action element, or null.
     *
     * @since 1.2.4
     */
    public static function findActionElement(string $body, string $action): ?SimpleXMLElement
    {
        $doc = self::loadBody($body);
        if ($doc === null) {
            return null;
        }

        $literal = self::xpathLiteral($action);

        // Action element as a direct child of the SOAP Body (any prefix).
        $matches = $doc->xpath("//*[local-name()='Body']/*[local-name()={$literal}]");
        if (is_array($matches) && $matches !== []) {
            return $matches[0];
        }

        // The document root itself is the bare action element.
        if ($doc->getName() === $action) {
            return $doc;
        }

        // A bare action element that is not the root but carries no Body wrapper.
        $bare = $doc->xpath("//*[local-name()={$literal}]");
        if (is_array($bare) && $bare !== []) {
            return $bare[0];
        }

        return null;
    }

    /**
     * Read a single argument value from an action element's DIRECT children.
     *
     * Matches by LOCAL name so the child may be unprefixed, carry the action's
     * default namespace, or use any prefix. Returns null when the element is
     * absent and '' when it is present but empty (`<SortCriteria/>`), so the
     * caller can distinguish "omitted" from "explicitly empty".
     *
     * @param SimpleXMLElement $actionElement The SOAP action element.
     * @param string $name The argument's local name.
     * @return string|null The value, or null when absent.
     *
     * @since 1.2.4
     */
    public static function extractArgument(SimpleXMLElement $actionElement, string $name): ?string
    {
        $literal = self::xpathLiteral($name);
        $matches = $actionElement->xpath("*[local-name()={$literal}]");

        if (is_array($matches) && $matches !== []) {
            return (string) $matches[0];
        }

        return null;
    }

    /**
     * Every DIRECT-child argument of an action element, as `localName => value`.
     *
     * Only direct children are read (XPath `*` selects child elements in ANY
     * namespace), and each value is the child's OWN text — an element that merely
     * wraps further elements (embedded DIDL-Lite) contributes no text and is
     * therefore skipped, which is exactly what prevents metadata bleed. Empty
     * values are dropped so a handler's own default applies, and the first
     * occurrence of a repeated local name wins.
     *
     * @param SimpleXMLElement $actionElement The SOAP action element.
     * @return array<string, string> Argument name => value map.
     *
     * @since 1.2.4
     */
    public static function directChildArguments(SimpleXMLElement $actionElement): array
    {
        $arguments = [];
        $children = $actionElement->xpath('*');
        if (!is_array($children)) {
            return $arguments;
        }

        foreach ($children as $child) {
            $name = $child->getName();
            if ($name === '' || isset($arguments[$name])) {
                continue;
            }
            $value = trim((string) $child);
            if ($value === '') {
                continue;
            }
            $arguments[$name] = $value;
        }

        return $arguments;
    }

    /**
     * Quote a string as an XPath 1.0 literal, safe against embedded quotes.
     *
     * Argument/action names come from fixed allow-lists in the callers, but
     * quoting defensively keeps extraction correct if that ever changes and
     * documents the intent.
     *
     * @param string $value The raw value to quote.
     * @return string An XPath 1.0 expression that evaluates to $value.
     *
     * @since 1.2.4
     */
    public static function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        // Contains both quote kinds: assemble with concat().
        $parts = [];
        foreach (explode("'", $value) as $i => $segment) {
            if ($i > 0) {
                $parts[] = '"\'"';
            }
            if ($segment !== '') {
                $parts[] = "'" . $segment . "'";
            }
        }

        return 'concat(' . implode(', ', $parts) . ')';
    }
}
