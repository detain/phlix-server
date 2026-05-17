<?php

namespace Phlex\Tests\Unit\Media\Markers\Fingerprinting;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Markers\Fingerprinting\ChromaPrintFactory;
use Phlex\Media\Markers\Fingerprinting\ChromaPrintFfi;
use Phlex\Media\Markers\Fingerprinting\ChromaPrintInterface;
use Phlex\Media\Markers\Fingerprinting\ChromaPrintShelled;

class ChromaPrintFactoryTest extends TestCase
{
    public function testBuildPrefersFfiWhenAvailable(): void
    {
        $fpcalcPath = '/usr/local/bin/fpcalc';

        $impl = ChromaPrintFactory::build($fpcalcPath);

        if ($impl instanceof ChromaPrintFfi) {
            $this->assertInstanceOf(ChromaPrintFfi::class, $impl);
        } else {
            $this->assertInstanceOf(ChromaPrintShelled::class, $impl);
        }
    }

    public function testBuildFallsBackToShelled(): void
    {
        $fpcalcPath = '/nonexistent/fpcalc';

        $impl = ChromaPrintFactory::build($fpcalcPath);

        $this->assertInstanceOf(ChromaPrintShelled::class, $impl);
    }

    public function testBuildReturnsChromaPrintInterface(): void
    {
        $impl = ChromaPrintFactory::build('/usr/local/bin/fpcalc');

        $this->assertInstanceOf(ChromaPrintInterface::class, $impl);
    }
}
