<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Ldap;

use Phlix\Plugins\Ldap\LdapConnection;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the LDAP user-search filter escapes user-supplied input so
 * injection payloads cannot break out of the filter.
 *
 * See post-O.7 wave 1 security audit, finding D.3.
 */
final class LdapFilterEscapeTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        LdapConnection::clearCache();
    }

    private function makeConnection(string $userFilter = '(uid={{username}})'): LdapConnection
    {
        return new LdapConnection(
            host: 'ldap.example.com',
            port: 389,
            ssl: false,
            baseDn: 'dc=example,dc=com',
            bindDn: null,
            bindPw: null,
            userFilter: $userFilter,
        );
    }

    public function test_plain_username_is_unchanged(): void
    {
        $conn = $this->makeConnection();

        self::assertSame('(uid=alice)', $conn->buildUserFilter('alice'));
    }

    public function test_injection_payload_with_star_and_parens_is_escaped(): void
    {
        $conn = $this->makeConnection();

        // Classic LDAP filter injection: `*)(uid=*` would expand the search
        // and bypass authentication if substituted naively.
        $filter = $conn->buildUserFilter('*)(uid=*');

        // Result must contain the escaped sequences and must NOT contain
        // unescaped meta-characters from the injected payload.
        self::assertStringContainsString('\2a', $filter);
        self::assertStringContainsString('\28', $filter);
        self::assertStringContainsString('\29', $filter);
        self::assertSame('(uid=\2a\29\28uid=\2a)', $filter);
    }

    public function test_backslash_and_null_byte_are_escaped(): void
    {
        $conn = $this->makeConnection();

        $filter = $conn->buildUserFilter("a\\b\x00c");

        // \\ → \5c, NUL → \00
        self::assertStringContainsString('\5c', $filter);
        self::assertStringContainsString('\00', $filter);
        self::assertStringNotContainsString("\x00", $filter);
    }

    public function test_active_directory_style_filter_substitutes_only_placeholder(): void
    {
        $conn = $this->makeConnection('(&(objectClass=user)(sAMAccountName={{username}}))');

        $filter = $conn->buildUserFilter('eve)(memberOf=Domain Admins');

        // The trailing literal `))` from the AD filter template must survive
        // intact, while the injected parens inside the username are escaped.
        self::assertSame(
            '(&(objectClass=user)(sAMAccountName=eve\29\28memberOf=Domain Admins))',
            $filter
        );
    }
}
