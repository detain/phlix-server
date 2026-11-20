<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Uuid;
use Phlix\Shared\Database\Uuid as SharedUuid;

final class UuidTest extends TestCase
{
    public function testV4ReturnsString(): void
    {
        $uuid = Uuid::v4();

        $this->assertNotEmpty($uuid);
    }

    public function testV4ReturnsCorrectFormat(): void
    {
        $uuid = Uuid::v4();

        // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testV4Returns36Characters(): void
    {
        $uuid = Uuid::v4();

        $this->assertSame(36, strlen($uuid));
    }

    public function testV4ReturnsDifferentValuesOnMultipleCalls(): void
    {
        $uuid1 = Uuid::v4();
        $uuid2 = Uuid::v4();
        $uuid3 = Uuid::v4();

        $this->assertNotSame($uuid1, $uuid2);
        $this->assertNotSame($uuid2, $uuid3);
        $this->assertNotSame($uuid1, $uuid3);
    }

    public function testV4VersionDigitIs4(): void
    {
        $uuid = Uuid::v4();

        // Position 14 is the version digit (after 3 groups + hyphen = 4th group starts at 15)
        // Format: 8-4-4-4-12, version is in position 14 (0-indexed: 13)
        $this->assertSame('4', $uuid[14]);
    }

    public function testV4VariantDigitsAre89ab(): void
    {
        $uuid = Uuid::v4();

        // Variant digit is at position 19 (after 3 groups + 4 chars + hyphen = 4+4+4+1=19)
        $variantDigit = $uuid[19];
        $validVariants = ['8', '9', 'a', 'b'];

        $this->assertContains($variantDigit, $validVariants);
    }

    public function testV4FormatMatchesHistoricalPerClassImplementation(): void
    {
        // Reproduce the exact format used by the historical per-class implementations
        $expected = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );

        // Uuid::v4() produces the same format (just with different random values)
        $actual = Uuid::v4();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $actual,
        );
        $this->assertSame(36, strlen($expected));
        $this->assertSame(36, strlen($actual));
    }

    /**
     * isV4() is the acceptance contract the transcode segment routes lean on
     * (2026-11-03 review server__secaudit, Critical cure): every id the mint
     * produces must pass, and every traversal/garbage/foreign-shape must not.
     */
    public function testIsV4AcceptsEverythingTheMintProduces(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue(Uuid::isV4(Uuid::v4()));
        }
    }

    public function testIsV4RefusesEveryShapeThatIsNotACanonicalV4(): void
    {
        $rejects = [
            '',
            '..',
            '../..',
            '../../etc/passwd',
            '%2e%2e%2f%2e%2e%2fetc%2fpasswd',
            '550e8400-e29b-41d4-a716-446655440000/',
            '550e8400-e29b-41d4-a716-446655440000/../x',
            '550E8400-E29B-41D4-A716-446655440000',
            '550e8400e29b41d4a716446655440000',
            '550e8400-e29b-41d4-a716-44665544000',
            '550e8400-e29b-41d4-a716-4466554400000',
            '550e8400-e29b-51d4-a716-446655440000',
            'zt0e8400-e29b-41d4-a716-446655440000',
        ];
        foreach ($rejects as $value) {
            $this->assertFalse(Uuid::isV4($value), $value);
        }
    }

    public function testIsV4AcceptsTheCanonicalExampleId(): void
    {
        $this->assertTrue(Uuid::isV4('550e8400-e29b-41d4-a716-446655440000'));
    }

    /**
     * Since srv-W3b the class above is a thin delegate onto the shared kernel
     * (Phlix\Shared\Database\Uuid) kept as the host-provided external contract
     * for phlix-plugin-trakt. These pins prove the shell and the kernel are
     * one behavior: either side's rule drifting reddens here, and a RE-INLINED
     * shell body additionally reddens UuidOrderSeedFuzzTest (the S443 entropy
     * law) and the SharedDelegationCensus carrier row for Common/Uuid.php.
     */
    public function testShellAndKernelMintShapesTheOtherSideAccepts(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $fromShell = Uuid::v4();
            $this->assertTrue(SharedUuid::isV4($fromShell), 'kernel rejected shell mint ' . $fromShell);
            $fromKernel = SharedUuid::v4();
            $this->assertTrue(Uuid::isV4($fromKernel), 'shell rejected kernel mint ' . $fromKernel);
        }
    }

    /**
     * The isV4() accept/reject SETS agree across the two doors over every
     * vector — including the 2026-11-03 secaudit cure corpus the transcode
     * segment routes lean on — and the shell's verdict equals the kernel's
     * public V4_PATTERN, the single shape law (duplicated regexes are how
     * shape laws drift).
     */
    public function testIsV4AcceptSetMatchesTheKernelPatternOverTheFullCorpus(): void
    {
        $vectors = [
            '550e8400-e29b-41d4-a716-446655440000',
            '00000000-0000-4000-8000-000000000000',
            '550e8400-e29b-41d4-b716-446655440000',
            '',
            '..',
            '../..',
            '../../etc/passwd',
            '%2e%2e%2f%2e%2e%2fetc%2fpasswd',
            '550e8400-e29b-41d4-a716-446655440000/',
            '550e8400-e29b-41d4-a716-446655440000/../x',
            '550E8400-E29B-41D4-A716-446655440000',
            '550e8400e29b41d4a716446655440000',
            '550e8400-e29b-41d4-a716-44665544000',
            '550e8400-e29b-41d4-a716-4466554400000',
            '550e8400-e29b-51d4-a716-446655440000',
            'zt0e8400-e29b-41d4-a716-446655440000',
            "550e8400-e29b-41d4-a716-446655440000\n",
            '{550e8400-e29b-41d4-a716-446655440000}',
        ];
        foreach ($vectors as $value) {
            $this->assertSame(
                SharedUuid::isV4($value),
                Uuid::isV4($value),
                'accept-set disagreement on ' . var_export($value, true)
            );
            $this->assertSame(
                preg_match(SharedUuid::V4_PATTERN, $value) === 1,
                Uuid::isV4($value),
                'shell diverges from the kernel pattern on ' . var_export($value, true)
            );
        }
    }
}
