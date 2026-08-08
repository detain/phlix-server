<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

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
 * ## Two jobs
 *
 * 1. **The header gap S51 closed.** The alive NOTIFY emitted only
 *    `HOST/NT/NTS/LOCATION/USN`. `CACHE-CONTROL` and `SERVER` are REQUIRED by
 *    UPnP DA 1.0 §1.2.2 and were both absent — and without a `max-age` a
 *    conformant control point has no lifetime to hold the advertisement
 *    against. Each new header is asserted SEPARATELY, so adding one of the two
 *    cannot pass.
 * 2. **The explicit "do not break the existing NOTIFY" acceptance criterion.**
 *    Every header the message carried BEFORE is re-asserted with its original
 *    value, and the 30-second re-announce timer is shown to still be armed at
 *    its original interval and to still emit a NOTIFY when it fires.
 *
 * ## How the bytes are captured
 *
 * The advertiser writes with `fwrite()` to whatever stream is in its `socket`
 * property, so the property is pointed at a `php://temp` handle by reflection
 * and the message is read straight back. That is the real `sendNotify()`
 * producing real bytes — no formatter is re-implemented here.
 */
final class SsdpNotifyHeadersTest extends TestCase
{
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
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // The bytes
    // ------------------------------------------------------------------

    /**
     * REGRESSION GUARD: every header the NOTIFY carried before S51 is still
     * there, with its original value.
     *
     * This is the "existing periodic NOTIFY unaffected" acceptance criterion,
     * asserted field by field rather than by eyeballing a diff.
     */
    public function test_the_alive_notify_keeps_every_header_it_already_had(): void
    {
        $message = $this->captureNotify('sendAlive');

        self::assertStringStartsWith("NOTIFY * HTTP/1.1\r\n", $message);

        $headers = $this->parseHeaders($message);

        self::assertSame('239.255.255.250:1900', $headers['HOST'] ?? null);
        self::assertSame('uuid:PHLIXSERVER', $headers['NT'] ?? null);
        self::assertSame('ssdp:alive', $headers['NTS'] ?? null);
        self::assertSame('http://10.0.0.1:8096/dlna/description.xml', $headers['LOCATION'] ?? null);
        self::assertSame('uuid:PHLIXSERVER', $headers['USN'] ?? null);
        self::assertStringEndsWith("\r\n\r\n", $message);
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
        $headers = $this->parseHeaders($this->captureNotify('sendAlive'));

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
        $headers = $this->parseHeaders($this->captureNotify('sendAlive'));

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
        $notify = $this->parseHeaders($this->captureNotify('sendAlive'));
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
     * The byebye keeps its own subtype and gains the same headers.
     */
    public function test_the_byebye_notify_is_still_a_byebye(): void
    {
        $headers = $this->parseHeaders($this->captureNotify('sendByebye'));

        self::assertSame('ssdp:byebye', $headers['NTS'] ?? null);
        self::assertSame('uuid:PHLIXSERVER', $headers['USN'] ?? null);
        self::assertSame('max-age=1800', $headers['CACHE-CONTROL'] ?? null);
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
     * the exact bytes it wrote.
     */
    private function captureNotify(string $method): string
    {
        $worker = new SsdpAdvertiser('10.0.0.1', 8096);
        $capture = $this->attachCaptureSocket($worker);

        $send = new ReflectionMethod(SsdpAdvertiser::class, $method);
        $send->setAccessible(true);
        $send->invoke($worker);

        return $this->readCapture($capture);
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
