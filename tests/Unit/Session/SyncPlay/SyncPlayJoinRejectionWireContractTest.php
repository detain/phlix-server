<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Server\Http\Controllers\SyncPlayController;
use Phlix\Server\Http\Request;
use Phlix\Tests\Support\SyncPlay\InMemorySyncPlaySnapshotService;

/**
 * SH-041 R2 — the SyncPlay join-refusal prose IS a wire contract, not free copy.
 *
 * The vendored @phlix/ui consumer matches the REST 400 body with strict
 * equality — phlix-ui src/api/syncplay.ts, isSyncPlayInvalidPassword() tests
 * `body.error === 'Invalid password'` (no period), and SyncPlayModal.vue arms
 * its password re-prompt/focus branch ONLY on that match. The b1d67a25
 * sentence-punctuation sweep dotted this arm and silently deadened the
 * consumer branch on every deployed server (the DD register
 * .reviews/2026-11-10/REVIEW-w22L2server.md, Critical). This suite pins the
 * exact bytes so no future copy sweep can repeat the class: the estate
 * consumer-match audit that closed this wave (round-2 register, per-string
 * disposition table) found this rail to be the sole runtime string matcher in
 * the estate — every other client keys on error_code / localized catalogs.
 *
 * Both transports ride ONE literal: the REST arm passes the manager return
 * through verbatim (SyncPlayController::joinGroup, 400 body), and the WS frame
 * message argument is the same $result['error'] (structural leg below) while
 * consumers on that path key on the frame's error_code. The error_code stays
 * the stable machine token; only the prose is byte-pinned here, envelope-safe,
 * zero wire codes.
 *
 * Deliberate non-scope: the ADMIN rail's 'Invalid password.' arms
 * (AdminUserController, pinned dotted by AdminUserControllerTest) carry no
 * estate consumer matcher and keep the swept sentence form.
 */
final class SyncPlayJoinRejectionWireContractTest extends TestCase
{
    /**
     * The byte-exact prose @phlix/ui strict-equality matches.
     * DO NOT ADD SENTENCE PUNCTUATION — the un-dotted shape is the contract.
     */
    private const WIRE_MATCHED_JOIN_REFUSAL = 'Invalid password';

    public function test_the_manager_join_refusal_error_is_the_exact_wire_matched_literal(): void
    {
        $manager = new SyncPlayManager();
        $created = $manager->createGroup('Test Group', 'secret');
        /** @var array{group: array{group_id: string}} $created */
        $groupId = $created['group']['group_id'];

        $result = $manager->joinGroup($groupId, 'member_2', 'User 2', 'wrong');

        $this->assertFalse($result['success']);
        $this->assertSame(
            self::WIRE_MATCHED_JOIN_REFUSAL,
            $result['error'],
            'the join-refusal prose is matched by strict equality in the vendored ui consumer — punctuation is part of the contract'
        );
        $this->assertSame(
            'syncplay.invalid_password',
            $result['error_code'],
            'the machine token stays the stable family identifier'
        );
    }

    public function test_the_rest_400_body_carries_the_exact_wire_matched_literal_and_nothing_else(): void
    {
        $manager = new SyncPlayManager();
        $controller = new SyncPlayController($manager, new InMemorySyncPlaySnapshotService());
        $created = $manager->createGroup('Test Group', 'secret');
        /** @var array{group: array{group_id: string}} $created */
        $groupId = $created['group']['group_id'];

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/syncplay/groups/' . $groupId . '/join';
        $request->userId = 'jwt-member';
        $request->body = ['memberName' => 'Member', 'password' => 'wrong'];

        $response = $controller->joinGroup($request, ['id' => $groupId]);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertSame(
            ['error'],
            array_keys($body),
            'the join-refusal 400 envelope carries the error key only — error_code never crosses the REST wire'
        );
        $this->assertSame(
            self::WIRE_MATCHED_JOIN_REFUSAL,
            $body['error'],
            'the REST passthrough must stay byte-identical to the manager arm the ui consumer matches'
        );
    }

    public function test_the_ws_join_frame_rides_the_same_manager_prose(): void
    {
        // Structural leg (ControllerCopySweep idiom): the WS join handler sends
        // the refusal with $result['error'] as its message argument — one
        // literal feeds both transports, so pinning the manager arm pins the
        // frame prose too. A refactor that re-hardcodes frame copy elsewhere
        // reddens this slice.
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Session/SyncPlay/SyncPlayManager.php');

        $this->assertStringContainsString(
            '$this->sendError($connection, $errorCode, $result[\'error\']);',
            $source,
            'the WS join refusal frame must keep riding the shared manager prose (no separate literal)'
        );
        $this->assertStringNotContainsString(
            "'error' => 'Invalid password.',",
            $this->managerSourceSlice(),
            'the dotted form must not return to the join-refusal arm (b1d67a25 regression class)'
        );
    }

    private function managerSourceSlice(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Session/SyncPlay/SyncPlayManager.php');
        $start = strpos($source, 'syncplay.invalid_password');
        $this->assertIsInt($start, 'the invalid-password arm must exist in the manager');

        return substr($source, max(0, $start - 260), 320);
    }
}
