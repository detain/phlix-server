<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use InvalidArgumentException;
use Phlix\Auth\UserRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Review server__copywriter SRV-m42: developer jargon leaked into
 * member-facing error copy. Every string pinned here reaches the wire as
 * the whole of a flat {error} envelope (AuthController/register-login/
 * refresh catches, WebPortalRouter's InvalidArgumentException belt) or as
 * the prose slot of a SyncPlay refusal whose localized clients read the
 * stable error_code instead — the code is contract, the copy is ours.
 *
 * The cure rule the review set: a validation message must say what the
 * person can do, in the house voice — never name a type-system predicate
 * ("must be a string"), a JWT claim ("subject"), or restate a constant in
 * doubled words ("Maximum … limit").
 *
 * Legs 1-3 are functional (real repository arms, zero queries reached);
 * legs 4-6 are source pins, because reaching the refresh-token arm would
 * require forging a signature-valid token with no subject claim, and the
 * group-cap arm requires fifty live groups — the copy is a literal either
 * way, and a byte-pin catches every re-wording in review.
 *
 * @package Phlix\Tests\Unit\Auth
 */
final class UserFacingErrorCopyTest extends TestCase
{
    private const SRC_DIR = __DIR__ . '/../../../src';

    /** Jargon phrases the cure removed — none may come back. */
    private const RETIRED_PHRASES = [
        'password must be a string',
        'theme must be a known theme id string',
        'Refresh token missing subject',
        'Maximum group limit reached',
    ];

    /**
     * The files SRV-m42 audited. The other "must be a string" arms in the
     * estate (AdminProfileController, ProfilesController, QuickConnectController)
     * are NOT part of this row's scope — see the FIX-SRV-M43.md register
     * pointer for the family wave.
     */
    private const AUDITED_FILES = [
        'Auth/UserRepository.php',
        'Auth/AuthManager.php',
        'Session/SyncPlay/SyncPlayManager.php',
    ];

    public function test_create_rejects_a_non_string_password_in_plain_words(): void
    {
        $repo = new UserRepository($this->neverQueryingDb());

        try {
            $repo->create(['username' => 'alice', 'password' => 123]);
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'The password must be text.',
                $e->getMessage(),
                'create(): the type-rejection arm must read as guidance, not as a gettype() verdict (SRV-m42).'
            );
            return;
        }
        self::fail('create() must reject a non-string password.');
    }

    public function test_update_rejects_a_non_string_password_in_plain_words(): void
    {
        $repo = new UserRepository($this->neverQueryingDb());

        try {
            $repo->update('user-1', ['password' => 123]);
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'The password must be text.',
                $e->getMessage(),
                'update(): the password arm shares create()\'s sentence — one choke, one voice (SRV-m42).'
            );
            return;
        }
        self::fail('update() must reject a non-string password.');
    }

    public function test_blank_theme_answers_with_the_known_list(): void
    {
        $repo = new UserRepository($this->neverQueryingDb());

        try {
            $repo->updateSettings('user-1', ['theme' => '']);
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'The theme must be one of: nocturne, daylight, midnight.',
                $e->getMessage(),
                'validateThemeId(): the empty/type arm must name the choices (the shape its '
                . '"Unknown theme" sibling two lines below already had — SRV-m42).'
            );
            return;
        }
        self::fail('updateSettings() must reject a blank theme.');
    }

    public function test_refresh_token_without_subject_speaks_english(): void
    {
        $source = $this->source('Auth/AuthManager.php');

        self::assertStringContainsString(
            "'Refresh token is missing its user id'",
            $source,
            'the missing-sub claim arm must name the concept a member can grasp — the user id, '
            . 'not the JWT claim (SRV-m42).'
        );
    }

    public function test_group_limit_prose_names_the_limit_in_a_sentence(): void
    {
        $source = $this->source('Session/SyncPlay/SyncPlayManager.php');

        self::assertStringContainsString(
            "'This server can\\'t create more watch groups right now.'",
            $source,
            'the group-cap refusal must read as a sentence; "Maximum group limit reached" doubled '
            . '"maximum…limit" and left "group" ambiguous (SRV-m42, review wording adopted).'
        );

        self::assertStringContainsString(
            "'error_code' => 'syncplay.group_limit_reached'",
            $source,
            'the dotted code beside the prose is wire contract — only the copy may move.'
        );
    }

    public function test_no_audited_file_revives_a_retired_jargon_phrase(): void
    {
        foreach (self::AUDITED_FILES as $relative) {
            $source = $this->source($relative);
            foreach (self::RETIRED_PHRASES as $phrase) {
                self::assertStringNotContainsString(
                    $phrase,
                    $source,
                    $relative . ' must not carry "' . $phrase . '" — retired by SRV-m42.'
                );
            }
        }
    }

    private function source(string $relative): string
    {
        $path = self::SRC_DIR . '/' . $relative;
        $contents = file_get_contents($path);
        self::assertIsString($contents, 'audited source ' . $relative . ' must be readable.');
        return $contents;
    }

    /** A Connection that must never be queried: every arm validated before SQL. */
    private function neverQueryingDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        return $db;
    }
}
