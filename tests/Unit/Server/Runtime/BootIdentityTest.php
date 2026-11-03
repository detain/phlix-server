<?php

/**
 * Phlix media server test: boot identity stamp (deferred item #15).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Runtime;

use Phlix\Server\Runtime\BootIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Pins the BootIdentity contract the degraded-rail log lines depend on:
 * stamp-once immutability (a re-stamp can never silently re-key log
 * correlation), token shape, the honest unstamped rendering, and the
 * describe() line format the bridge sidecar + start.php warnings embed.
 */
final class BootIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        BootIdentity::resetForTesting();
    }

    protected function tearDown(): void
    {
        BootIdentity::resetForTesting();
    }

    public function test_describe_is_honest_before_any_stamp(): void
    {
        $this->assertNull(BootIdentity::current());
        $this->assertSame('instance=unstamped pid=0 started_at=none', BootIdentity::describe());
    }

    public function test_stamp_generates_a_16_char_hex_instance_token(): void
    {
        $identity = BootIdentity::stamp();

        $this->assertSame(1, preg_match('/^[0-9a-f]{16}$/', $identity->instanceToken));
        $this->assertSame($identity, BootIdentity::current());
    }

    public function test_first_stamp_wins_and_later_stamps_cannot_rekey(): void
    {
        $first = BootIdentity::stamp('aaaa1111bbbb2222');
        $second = BootIdentity::stamp('cccc3333dddd4444');

        $this->assertSame($first, $second);
        $this->assertSame('aaaa1111bbbb2222', BootIdentity::current()?->instanceToken);
    }

    public function test_describe_carries_token_pid_and_started_at(): void
    {
        BootIdentity::stamp('feedfacecafebeef');

        $line = BootIdentity::describe();

        $this->assertMatchesRegularExpression(
            '/^instance=feedfacecafebeef pid=\d+ started_at=\d{10}$/',
            $line
        );
    }
}
