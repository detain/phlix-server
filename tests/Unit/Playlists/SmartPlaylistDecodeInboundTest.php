<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Playlists;

use JsonException;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Playlists\SmartPlaylistController;
use Phlix\Server\Http\Request;
use Workerman\MySQL\Connection;

/**
 * DECODE-ADOPT (wave-5): the rules_json validation dance in
 * {@see SmartPlaylistController::create()} replaces its hand-rolled
 * `json_decode + json_last_error()` gate with the shared inbound seam
 * {@see \Phlix\Shared\Support\Json::decodeInbound()}. The controller is
 * UNROUTED (no live HTTP surface — pinned by SmartPlaylistRegistrationGuardTest),
 * so these pins govern the shape the registration will inherit, not a live wire.
 *
 * Pinned answer space (create(), rules_json as a STRING in the body):
 *   malformed '{bad'      -> 400 'The playlist rules are not valid JSON'  (identical before/after)
 *   over-depth (600 nest) -> 400 same envelope                            (identical before/after)
 *   scalar '5'/'true'     -> ACCEPTED, raw string persisted               (identical before/after)
 *   literal 'null'        -> 400                                          (RED-FIRST FLIP: the old
 *      gate keyed on json_last_error() !== NONE, and 'null' decodes with error NONE,
 *      so it was accepted and persisted raw; decodeInbound collapses it onto the
 *      malformed arm — a valid-but-useless rules document is now refused, disclosed flip)
 *
 * @package Phlix\Tests\Unit\Playlists
 */
final class SmartPlaylistDecodeInboundTest extends TestCase
{
    /** @var list<array<array-key, mixed>> */
    private array $inserted = [];

    /**
     * 600-deep nested array literal — beyond the seam's 512 default ceiling.
     */
    private static function deepJson(): string
    {
        return str_repeat('[', 600) . str_repeat(']', 600);
    }

    private function createHarness(): SmartPlaylistController
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param mixed $params
             * @return mixed
             */
            function (string $sql, $params = null) {
                if (str_contains($sql, 'INSERT INTO smart_playlists')) {
                    $this->inserted[] = array_values((array) $params);

                    return 1;
                }
                $this->fail('unexpected SQL reached by the create path: ' . $sql);
            },
        );
        $db->method('lastInsertId')->willReturn('7');

        return new SmartPlaylistController($db, $this->createMock(ItemRepository::class));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestWith(array $body): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->body = $body;

        return $request;
    }

    public function testCreateRejectsMalformedRulesJsonWithTheExistingEnvelope(): void
    {
        $controller = $this->createHarness();

        $response = $controller->create(
            $this->requestWith(['name' => 'n', 'library_id' => 'lib-1', 'rules_json' => '{bad']),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        $this->assertSame('The playlist rules are not valid JSON', $body['error'] ?? null);
        $this->assertSame([], $this->inserted, 'a refused rules document must never reach the insert');
    }

    public function testCreateRejectsOverDepthRulesJsonWithTheExistingEnvelope(): void
    {
        $controller = $this->createHarness();

        $response = $controller->create(
            $this->requestWith(['name' => 'n', 'library_id' => 'lib-1', 'rules_json' => self::deepJson()]),
            [],
        );

        // Before: json_decode default ceiling collapsed depth onto last_error -> 400.
        // After:  decodeInbound REARMS the structural class as JsonException, which
        // the site maps back onto ITS OWN bad-document answer: identical bytes.
        $this->assertSame(400, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        $this->assertSame('The playlist rules are not valid JSON', $body['error'] ?? null);
        $this->assertSame([], $this->inserted);
    }

    public function testCreateStillAcceptsScalarValidRulesStringsPersistingThemRaw(): void
    {
        // The old gate only refused last_error!==NONE, so '5' passed and the raw
        // string was persisted. decodeInbound('5') returns int 5 (non-null) —
        // the string passthrough below the gate is untouched. The intentional
        // leniency survives byte-identically (no silent harmonize).
        $controller = $this->createHarness();

        $response = $controller->create(
            $this->requestWith(['name' => 'n', 'library_id' => 'lib-1', 'rules_json' => '5']),
            [],
        );

        $this->assertSame(201, $response->statusCode);
        $this->assertCount(1, $this->inserted);
    }

    public function testCreateRefusesTheNullLiteralRulesDocument(): void
    {
        // RED-FIRST (flipped by the migration): today the last_error gate lets the
        // literal string 'null' through and it is persisted raw as the rules
        // document. The shared seam cannot distinguish 'null' from malformed
        // without re-minting a hand-rolled gate, and a null rules document is not
        // a usable playlist definition anyway — the site's bad-document answer
        // (400) now governs the whole collapse class.
        $controller = $this->createHarness();

        $response = $controller->create(
            $this->requestWith(['name' => 'n', 'library_id' => 'lib-1', 'rules_json' => 'null']),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        $this->assertSame('The playlist rules are not valid JSON', $body['error'] ?? null);
        $this->assertSame([], $this->inserted);
    }

    /**
     * Engine-truth pin behind the flip above: the OLD gate's answer to 'null'
     * was accept, because json_last_error() reads NONE for the literal.
     * If this ever reddens, PHP changed decode semantics — recheck the flip row.
     */
    public function testTheLegacyGateAcceptedNullLiteralBecauseLastErrorStaysNone(): void
    {
        json_decode('null');

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertNull(json_decode('null', true));

        json_decode(self::deepJson());
        $this->assertNotSame(JSON_ERROR_NONE, json_last_error(), 'depth DID trip the old gate too');
    }

    public function testTheSeamNeverEscapesJsonExceptionFromTheDepthArmUncaught(): void
    {
        // Characterization of the seam itself at the site's ceiling.
        $this->assertNull(\Phlix\Shared\Support\Json::decodeInbound('{bad'));
        $this->assertNull(\Phlix\Shared\Support\Json::decodeInbound('null'));
        $this->assertSame(5, \Phlix\Shared\Support\Json::decodeInbound('5'));
        $this->expectException(JsonException::class);
        \Phlix\Shared\Support\Json::decodeInbound(self::deepJson());
    }
}
