<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlex\Hub\ClaimInitiateResult;

class ClaimInitiateResultTest extends TestCase
{
    public function test_constructor_and_properties(): void
    {
        $result = new ClaimInitiateResult(
            claimCode: 'ABCD-1234',
            expiresIn: 600,
            claimId: 'claim-uuid',
            hubBaseUrl: 'https://hub.example.com',
        );

        $this->assertEquals('ABCD-1234', $result->claimCode);
        $this->assertEquals(600, $result->expiresIn);
        $this->assertEquals('claim-uuid', $result->claimId);
        $this->assertEquals('https://hub.example.com', $result->hubBaseUrl);
    }
}
