<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use Phlix\Plugins\Util\RecursiveDelete;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Version;
use Phlix\Config\EffectiveConfig;
use Phlix\Dlna\SsdpAdvertiser;
use Phlix\Dlna\SsdpSearchResponder;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Events\EventInterface;
use Workerman\Timer;
use Workerman\Worker;

/**
 * The periodic SSDP NOTIFY: its bytes, and that it still fires.
 *
 * ## Three jobs
 *
 * 1. **The header gap S51 closed.** The alive NOTIFY emitted only
 *    `HOST/NT/NTS/LOCATION/USN`. `CACHE-CONTROL` and `SERVER` are REQUIRED by
 *    UPnP DA 1.0 §1.2.2 and were both absent — and without a `max-age` a
 *    conformant control point has no lifetime to hold the advertisement
 *    against. Each new header is asserted SEPARATELY, so adding one of the two
 *    cannot pass.
 * 2. **The explicit "do not break the existing NOTIFY" acceptance criterion.**
 *    Every header the message carried BEFORE is re-asserted with its original
 *    value on the `uuid:…` target datagram, and the 30-second re-announce
 *    timer is shown to still be armed at its original interval and to still
 *    emit NOTIFYs when it fires.
 * 3. **The S297 target-set unification.** Since S297 the NOTIFY emits ONE
 *    datagram per advertised target, from the SAME enumeration the search
 *    responder matches against ({@see SsdpSearchResponder::advertisedTargets()}).
 *    {@see self::test_the_notify_enumerates_the_same_target_set_as_the_search_responder()}
 *    is the divergence-proof: a target added to one path but not the other
 *    reddens it, because the two sets are compared element for element.
 *
 * ## How the bytes are captured
 *
 * The advertiser writes with `fwrite()` to whatever stream is in its `socket`
 * property, so the property is pointed at a `php://temp` handle by reflection
 * and the messages are read straight back. That is the real `sendNotify()`
 * producing real bytes — no formatter is re-implemented here.
 */
final class SsdpNotifyHeadersTest extends TestCase
{
    /** @var list<string> minted config roots removed in tearDown (S439 zero-residue). */
    private array $mintedConfigRoots = [];

    /** @var array<int, Worker> */
    private array $savedWorkers = [];

    private ?EventInterface $savedTimerEvent = null;

    protected function setUp(): void
    {
        parent::setUp();
        EffectiveConfig::reset();
        $this->savedWorkers = Worker::getAllWorkers();

        $prop = new ReflectionProperty(Timer::class, 'event');
        $prop->setAccessible(true);
        /** @var EventInterface|null $value */
        $value = $prop->getValue();
        $this->savedTimerEvent = $value;
    }

    protected function tearDown(): void
    {
        $prop = new ReflectionProperty(Timer::class, 'event');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->savedTimerEvent);

        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);

        EffectiveConfig::reset();
        foreach ($this->mintedConfigRoots as $root) {
            RecursiveDelete::remove($root);
        }
        $this->mintedConfigRoots = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // The bytes
    // ------------------------------------------------------------------

    /**
     * REGRESSION GUARD: every header the NOTIFY carried before S51 is still
     * there, with its original value, on the `uuid:…` target datagram.
     *
     * This is the "existing periodic NOTIFY unaffected" acceptance criterion,
     * asserted field by field rather than by eyeballing a diff. Since S297 the
     * NOTIFY is one datagram per advertised target, so the assertions target
     * the datagram whose `NT` is the device UUID — the one whose bytes S51's
     * acceptance criterion was written against.
     */
    public function test_the_alive_notify_keeps_every_header_it_already_had(): void
    {
        $usnDatagram = $this->usnTargetDatagram($this->captureNotify('sendAlive'));

        self::assertStringStartsWith("NOTIFY * HTTP/1.1\r\n", $usnDatagram);

        $headers = $this->parseHeaders($usnDatagram);

        self::assertSame('239.255.255.250:1900', $headers['HOST'] ?? null);
        self::assertSame('uuid:PHLIXSERVER', $headers['NT'] ?? null);
        self::assertSame('ssdp:alive', $headers['NTS'] ?? null);
        self::assertSame('http://10.0.0.1:8096/dlna/description.xml', $headers['LOCATION'] ?? null);
        self::assertSame('uuid:PHLIXSERVER', $headers['USN'] ?? null);
        // The datagram is a complete message: the USN header is its last line
        // (the trailing \r\n\r\n terminator is stripped by the capture split).
        self::assertStringEndsWith('USN: uuid:PHLIXSERVER', $usnDatagram);
    }

    /**
     * SPEC: the alive NOTIFY now carries `CACHE-CONTROL`.
     *
     * Separate test from `SERVER` on purpose. A single "has both new headers"
     * assertion is satisfied by an implementation that added whichever one the
     * author happened to think of first, because the first failing assertion
     * masks the second.
     */
    public function test_the_alive_notify_carries_cache_control(): void
    {
        $headers = $this->parseHeaders($this->captureNotify('sendAlive')[0]);

        self::assertSame(
            'max-age=1800',
            $headers['CACHE-CONTROL'] ?? null,
            'CACHE-CONTROL is REQUIRED on an ssdp:alive; without a max-age the advertisement has no lifetime.'
        );
    }

    /**
     * SPEC: the alive NOTIFY now carries `SERVER`.
     *
     * @see self::test_the_alive_notify_carries_cache_control() for why these are
     *      two tests and not one.
     */
    public function test_the_alive_notify_carries_server(): void
    {
        $headers = $this->parseHeaders($this->captureNotify('sendAlive')[0]);

        self::assertMatchesRegularExpression(
            '#^\S+/\S+ UPnP/1\.0 Phlix/' . preg_quote(Version::STRING, '#') . '$#',
            $headers['SERVER'] ?? '',
            'SERVER is REQUIRED and must be OS/version UPnP/1.0 product/version.'
        );
    }

    /**
     * CONSEQUENCE: the NOTIFY and the M-SEARCH reply agree on the two headers
     * they share.
     *
     * A control point that finds this device by searching and then hears its
     * announcement must be told the same lifetime and the same server identity.
     * Divergence here is the classic "one device, two entries" bug, and it is
     * exactly what independent hand-written header lists produce over time.
     */
    public function test_the_notify_and_the_search_response_agree(): void
    {
        $notify = $this->parseHeaders($this->captureNotify('sendAlive')[0]);
        $search = $this->parseHeaders(SsdpSearchResponder::buildResponse(
            SsdpSearchResponder::ST_ROOT_DEVICE,
            SsdpAdvertiser::USN,
            'http://10.0.0.1:8096/dlna/description.xml'
        ));

        self::assertSame($search['CACHE-CONTROL'] ?? null, $notify['CACHE-CONTROL'] ?? null);
        self::assertSame($search['SERVER'] ?? null, $notify['SERVER'] ?? null);
        self::assertSame($search['LOCATION'] ?? null, $notify['LOCATION'] ?? null);
    }

    /**
     * S297 AC: NOTIFY and the search responder enumerate the SAME target set,
     * from a SINGLE source.
     *
     * ## Why this test exists (S345 rule 3 — the "nothing matched" defence
     * needs its own guard)
     *
     * The defect was two hand-maintained lists: the responder answered five
     * targets, the NOTIFY announced one. A fix that only closes one path reads
     * as a pass, so this test compares the two paths element for element:
     *
     *  - every NOTIFY datagram's `NT` must be one of the responder's
     *    advertised targets, and the SET of them must equal that enumeration
     *    exactly — a target added to `advertisedTargets()` without a NOTIFY
     *    datagram reddens (set mismatch), and a hand-edited NOTIFY list that
     *    drifts from the enumeration reddens (unknown `NT`).
     *  - each datagram's `USN` must be the USN the RESPONDER would pair with
     *    that target (`usnFor()`), so a control point that M-SEARCHes a target
     *    after hearing it announced gets the same identity back.
     *  - the datagrams appear in the responder's reply order (root device
     *    first), which is also the order a control point conventionally expects.
     *
     * Mutation-verified: reverting sendNotify() to a single `uuid:…` NOTIFY
     * fails this test on the set comparison.
     */
    public function test_the_notify_enumerates_the_same_target_set_as_the_search_responder(): void
    {
        $datagrams = $this->captureNotify('sendAlive');
        $expectedTargets = SsdpSearchResponder::advertisedTargets(SsdpAdvertiser::USN);

        self::assertSame(
            count($expectedTargets),
            count($datagrams),
            'One NOTIFY datagram per advertised target — the passive and active halves must agree '
            . 'about how many things this device is.'
        );

        $announcedTargets = [];
        foreach ($datagrams as $index => $datagram) {
            self::assertStringStartsWith("NOTIFY * HTTP/1.1\r\n", $datagram);

            $headers = $this->parseHeaders($datagram);
            $nt = $headers['NT'] ?? null;
            self::assertIsString($nt, "Datagram {$index} must carry an NT header.");
            $announcedTargets[] = $nt;

            self::assertSame(
                SsdpSearchResponder::usnFor(SsdpAdvertiser::USN, $nt),
                $headers['USN'] ?? null,
                "Datagram {$index} (NT: {$nt}) must pair the USN exactly as the search responder does."
            );

            self::assertSame(
                $expectedTargets[$index],
                $nt,
                "Datagram {$index} must announce target {$expectedTargets[$index]} in the responder's reply order."
            );
        }

        self::assertSame(
            $expectedTargets,
            $announcedTargets,
            'The NOTIFY target set must EQUAL the responder target set, not merely overlap.'
        );
    }

    /**
     * The byebye keeps its own subtype on every target and gains the same
     * headers.
     */
    public function test_the_byebye_notify_is_still_a_byebye(): void
    {
        $datagrams = $this->captureNotify('sendByebye');

        self::assertSame(
            count(SsdpSearchResponder::advertisedTargets(SsdpAdvertiser::USN)),
            count($datagrams),
            'Byebye must mirror the alive set: every target announced alive is announced gone.'
        );

        foreach ($datagrams as $datagram) {
            $headers = $this->parseHeaders($datagram);
            self::assertSame('ssdp:byebye', $headers['NTS'] ?? null);
            self::assertSame('max-age=1800', $headers['CACHE-CONTROL'] ?? null);
        }

        $usnDatagram = $this->usnTargetDatagram($datagrams);
        $headers = $this->parseHeaders($usnDatagram);
        self::assertSame('uuid:PHLIXSERVER', $headers['USN'] ?? null);
    }

    // ------------------------------------------------------------------
    // That it still fires
    // ------------------------------------------------------------------

    /**
     * REGRESSION GUARD: the re-announce timer is still armed at 30 s, is
     * PERSISTENT, and its callback still emits an alive NOTIFY.
     *
     * "A timer id greater than zero" would pass against a one-shot timer, a
     * 30-hour interval, or a callback that does nothing. So the event loop is
     * replaced with a recorder: the interval and the persistence flag are read
     * off the real `Timer::add()` call, and then the recorded callback — the one
     * production actually armed — is invoked and its bytes inspected.
     */
    public function test_the_thirty_second_notify_timer_is_still_armed_and_still_notifies(): void
    {
        $recorded = [];

        $event = $this->createMock(EventInterface::class);
        $event->method('repeat')->willReturnCallback(
            static function (float $interval, callable $func, array $args = []) use (&$recorded): int {
                $recorded[] = ['persistent' => true, 'interval' => $interval, 'func' => $func];
                return count($recorded);
            }
        );
        $event->method('delay')->willReturnCallback(
            static function (float $interval, callable $func, array $args = []) use (&$recorded): int {
                $recorded[] = ['persistent' => false, 'interval' => $interval, 'func' => $func];
                return count($recorded);
            }
        );

        $prop = new ReflectionProperty(Timer::class, 'event');
        $prop->setAccessible(true);
        $prop->setValue(null, $event);

        $this->bootstrapDlna(['enabled' => true, 'cds_enabled' => true]);

        $worker = new SsdpAdvertiser('10.0.0.1', 8096);
        $start = $worker->onWorkerStart;
        self::assertIsCallable($start);
        $start($worker);

        self::assertCount(1, $recorded, 'onWorkerStart must arm exactly one timer.');
        self::assertTrue($recorded[0]['persistent'], 'The re-announce timer must be PERSISTENT, not one-shot.');
        self::assertSame(
            (float) SsdpAdvertiser::BROADCAST_INTERVAL_SECONDS,
            $recorded[0]['interval'],
            'The re-announce interval must still be 30 seconds.'
        );

        // Point the advertiser at a capture stream, then fire the timer's own
        // callback and read what it wrote.
        $capture = $this->attachCaptureSocket($worker);
        ($recorded[0]['func'])();
        $written = $this->readCapture($capture);

        self::assertStringStartsWith("NOTIFY * HTTP/1.1\r\n", $written, 'The timer must still emit a NOTIFY.');
        self::assertStringContainsString("NTS: ssdp:alive\r\n", $written);
        self::assertStringContainsString("CACHE-CONTROL: max-age=1800\r\n", $written);
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    /**
     * Run one of the private send methods against a capture stream and return
     * the exact bytes it wrote, split into individual datagrams.
     *
     * Since S297 a NOTIFY pass is one datagram per advertised target
     * (`fwrite()` per target), so the capture stream holds several
     * `\r\n\r\n`-terminated messages concatenated.
     *
     * @return list<string>
     */
    private function captureNotify(string $method): array
    {
        $worker = new SsdpAdvertiser('10.0.0.1', 8096);
        $capture = $this->attachCaptureSocket($worker);

        $send = new ReflectionMethod(SsdpAdvertiser::class, $method);
        $send->setAccessible(true);
        $send->invoke($worker);

        $written = $this->readCapture($capture);
        $datagrams = explode("\r\n\r\n", $written);

        // The final explode element is the empty string after the last
        // terminator; drop it. A datagram is never empty.
        return array_values(array_filter($datagrams, static fn (string $d): bool => $d !== ''));
    }

    /**
     * The datagram whose `NT` is the device UUID — the target the NOTIFY
     * announced before S297, whose bytes S51's acceptance criterion was
     * written against.
     *
     * @param list<string> $datagrams
     */
    private function usnTargetDatagram(array $datagrams): string
    {
        foreach ($datagrams as $datagram) {
            $headers = $this->parseHeaders($datagram);
            if (($headers['NT'] ?? null) === SsdpAdvertiser::USN) {
                return $datagram;
            }
        }

        self::fail('No NOTIFY datagram announces the device UUID target ' . SsdpAdvertiser::USN . '.');
    }

    /**
     * @return resource
     */
    private function attachCaptureSocket(SsdpAdvertiser $worker): mixed
    {
        $capture = fopen('php://temp', 'r+');
        self::assertIsResource($capture);

        $socket = new ReflectionProperty(SsdpAdvertiser::class, 'socket');
        $socket->setAccessible(true);
        $socket->setValue($worker, $capture);

        return $capture;
    }

    /**
     * @param resource $capture
     */
    private function readCapture(mixed $capture): string
    {
        rewind($capture);
        $written = stream_get_contents($capture);
        self::assertIsString($written);

        return $written;
    }

    /**
     * @param array<string, mixed> $dlna
     */
    private function bootstrapDlna(array $dlna): void
    {
        $dir = sys_get_temp_dir() . '/phlix_s51_notify_' . uniqid('', true) . '/config';
        mkdir($dir, 0o777, true);
        $this->mintedConfigRoots[] = dirname($dir);
        file_put_contents($dir . '/dlna.php', '<?php return ' . var_export($dlna, true) . ";\n");
        EffectiveConfig::bootstrap(null, null, $dir);
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $message): array
    {
        $out = [];
        $lines = explode("\r\n", trim($message));
        array_shift($lines);

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            self::assertNotFalse($colon, "Malformed header line: {$line}");
            $out[strtoupper(substr($line, 0, (int) $colon))] = trim(substr($line, (int) $colon + 1));
        }

        return $out;
    }
}
