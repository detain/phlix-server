<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Network\StunClient;
use ReflectionMethod;

/**
 * SRV-41 / SRV-S23: STUN transaction IDs must come from the CSPRNG
 * (random_bytes), never the seedable mt_rand (mt_rand is jitter-only).
 */
final class StunTransactionIdCsprngTest extends TestCase
{
    private function generate(StunClient $c): string
    {
        $m = new ReflectionMethod(StunClient::class, 'generateTransactionId');
        $m->setAccessible(true);
        $id = $m->invoke($c);
        self::assertIsString($id);
        return $id;
    }

    public function testTransactionIdIsTwelveBytes(): void
    {
        self::assertSame(12, strlen($this->generate(new StunClient())));
    }

    public function testTransactionIdIgnoresTheMtRandSeed(): void
    {
        $c = new StunClient();
        mt_srand(12345);
        $a = $this->generate($c);
        mt_srand(12345);
        $b = $this->generate($c);
        mt_srand();
        self::assertNotSame($a, $b, 'transaction IDs must not be reproducible from the mt_rand seed');
    }

    public function testSourceUsesRandomBytesAndNotMtRand(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(StunClient::class))->getFileName());
        self::assertStringContainsString('random_bytes(', $src);
        self::assertStringNotContainsString('mt_rand(', $src);
    }
}
