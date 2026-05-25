<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Chromecast;

use PHPUnit\Framework\TestCase;
use Phlix\Chromecast\RemoteCastClient;

/**
 * Verifies that RemoteCastClient fails loudly instead of silently faking
 * success. Casting over the relay tunnel depends on a hub feature that does
 * not exist yet, so every public command must throw rather than report a
 * fake-OK result.
 */
class RemoteCastClientTest extends TestCase
{
    /**
     * RelayConsumer is `final` (cannot be mocked) and the relay is never used
     * by these honest-failure paths, so we build the client without invoking
     * its constructor.
     */
    private function makeClient(): RemoteCastClient
    {
        $ref = new \ReflectionClass(RemoteCastClient::class);

        /** @var RemoteCastClient $client */
        $client = $ref->newInstanceWithoutConstructor();

        // The public methods read $this->deviceId before delegating to the
        // (throwing) relay command, so the typed property must be initialised.
        $deviceIdProp = $ref->getProperty('deviceId');
        $deviceIdProp->setAccessible(true);
        $deviceIdProp->setValue($client, 'test-device');

        return $client;
    }

    public function testLaunchAppThrowsNotOperational(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hub relay tunnel');

        $this->makeClient()->launchApp();
    }

    public function testLoadMediaThrowsNotOperational(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not yet operational');

        $this->makeClient()->loadMedia('http://example.test/media.mp4', 'video/mp4');
    }

    public function testPlayThrowsNotOperational(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->makeClient()->play();
    }

    public function testPauseThrowsNotOperational(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->makeClient()->pause();
    }

    public function testStopThrowsNotOperational(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->makeClient()->stop();
    }

    public function testSeekThrowsNotOperational(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->makeClient()->seek(5000);
    }
}
