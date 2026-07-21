<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use Phlix\Dlna\DlnaDevice;
use Phlix\Dlna\DlnaRoutes;
use PHPUnit\Framework\TestCase;

/**
 * The device description must only advertise endpoints the server serves.
 *
 * ## The bug this pins
 *
 * A UPnP control point fetches the device description and then POSTs its
 * Browse request to whatever `controlURL` that document names. The description
 * advertised `/ctl/ContentDirectory`; the server registered
 * `/dlna/content_directory`. **Every browse would have 404'd.** The paths were
 * written twice, in `DlnaDevice::initializeServices()` and in
 * `Application::loadCdsRoutes()`, and the two copies simply disagreed.
 *
 * It stayed invisible because the CDS could not be resolved at all until 1.3.0
 * (`DlnaServer` had no DI registration), so nothing ever got far enough to be
 * broken by it.
 *
 * Both sides now read {@see DlnaRoutes}. These tests assert the description
 * agrees with it, so a future edit to one side alone turns the suite red.
 */
final class DlnaDescriptionRoutesTest extends TestCase
{
    private function serverDescriptionXml(): string
    {
        $device = new DlnaDevice(
            udn: 'uuid:phlix-server-test',
            deviceType: DlnaDevice::TYPE_SERVER,
            friendlyName: 'Phlix Test Server',
            baseUrl: '192.168.1.50',
            port: 8096,
        );

        return $device->toDeviceDescriptionXml();
    }

    /**
     * @return list<string>
     */
    private function tagValues(string $xml, string $tag): array
    {
        preg_match_all('#<' . $tag . '>(.*?)</' . $tag . '>#s', $xml, $m);

        return $m[1];
    }

    /**
     * CONSEQUENCE: every advertised controlURL is a path the server registers.
     *
     * Mutation-verified: restoring `'controlUrl' => '/ctl/ContentDirectory'`
     * fails this test.
     */
    public function test_every_advertised_control_url_is_a_real_route(): void
    {
        $controlUrls = $this->tagValues($this->serverDescriptionXml(), 'controlURL');

        self::assertNotEmpty($controlUrls, 'The description must advertise at least one controlURL.');

        foreach ($controlUrls as $url) {
            self::assertContains(
                $url,
                DlnaRoutes::all(),
                sprintf('Description advertises controlURL "%s", which the server does not serve.', $url)
            );
        }
    }

    /**
     * CONSEQUENCE: ContentDirectory's Browse endpoint specifically is correct.
     *
     * The generic test above would still pass if ContentDirectory pointed at
     * some OTHER real route, so the service a client actually browses through
     * is asserted by name.
     */
    public function test_content_directory_advertises_its_own_control_endpoint(): void
    {
        self::assertStringContainsString(
            '<controlURL>' . DlnaRoutes::CONTENT_DIRECTORY_CONTROL . '</controlURL>',
            $this->serverDescriptionXml(),
        );
    }

    /**
     * CONSEQUENCE: every advertised SCPDURL is non-empty AND a real route.
     *
     * The ContentDirectory entry spelled the key `SCPDUrl` while the renderer
     * used `SCPDURL`, and `toDeviceDescriptionXml()` reads `SCPDURL` with a
     * `?? ''` fallback — so the one service a client needs advertised an EMPTY
     * `<SCPDURL>`, silently. The emptiness assertion is the one that catches a
     * key-casing typo; the membership assertion catches a wrong path.
     *
     * Mutation-verified: renaming the key back to 'SCPDUrl' fails this.
     */
    public function test_every_advertised_scpd_url_is_present_and_real(): void
    {
        $scpdUrls = $this->tagValues($this->serverDescriptionXml(), 'SCPDURL');

        self::assertNotEmpty($scpdUrls, 'The description must advertise SCPD URLs.');

        foreach ($scpdUrls as $url) {
            self::assertNotSame(
                '',
                $url,
                'An empty <SCPDURL> means a service key was misspelled; clients cannot fetch its description.'
            );
            self::assertContains(
                $url,
                DlnaRoutes::all(),
                sprintf('Description advertises SCPDURL "%s", which the server does not serve.', $url)
            );
        }
    }

    /**
     * CONSEQUENCE: eventing is advertised as absent, not as a dead path.
     *
     * GENA eventing is not implemented. Advertising `/evt/ContentDirectory`
     * invites every control point to SUBSCRIBE against a route that does not
     * exist; UPnP provides an empty `eventSubURL` to mean "no events", which is
     * the honest signal.
     */
    public function test_eventing_is_advertised_as_unavailable(): void
    {
        foreach ($this->tagValues($this->serverDescriptionXml(), 'eventSubURL') as $url) {
            self::assertSame(
                '',
                $url,
                'Eventing is not implemented, so eventSubURL must be empty rather than a dead path.'
            );
        }
    }
}
