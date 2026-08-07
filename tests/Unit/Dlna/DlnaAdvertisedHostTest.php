<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use Phlix\Config\EffectiveConfig;
use Phlix\Dlna\DlnaAdvertisedHost;
use Phlix\Dlna\SsdpAdvertiser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * {@see DlnaAdvertisedHost} — the ONE place `dlna.advertise_host` is read.
 *
 * The integration question (all three advertised URLs naming the same host)
 * lives in {@see DlnaResUrlIsRoutableTest}; this file covers the resolver's own
 * behaviour, including the operator mistakes it is here to absorb.
 */
final class DlnaAdvertisedHostTest extends TestCase
{
    private string $configDir = '';

    protected function tearDown(): void
    {
        EffectiveConfig::reset();

        if ($this->configDir !== '') {
            foreach (glob($this->configDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($this->configDir)) {
                rmdir($this->configDir);
            }
            $parent = dirname($this->configDir);
            if (is_dir($parent)) {
                rmdir($parent);
            }
            $this->configDir = '';
        }

        parent::tearDown();
    }

    /**
     * Bootstrap the config overlay against a throwaway `config/dlna.php`.
     *
     * @param array<string, mixed> $dlna
     */
    private function bootstrapDlnaConfig(array $dlna): void
    {
        $this->configDir = sys_get_temp_dir() . '/phlix_advhost_' . uniqid('', true) . '/config';
        mkdir($this->configDir, 0o775, true);
        file_put_contents(
            $this->configDir . '/dlna.php',
            '<?php return ' . var_export($dlna, true) . ";\n"
        );

        EffectiveConfig::bootstrap(null, null, $this->configDir);
    }

    // -----------------------------------------------------------------
    // sanitize(): the operator-mistake absorber
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sanitizeProvider(): array
    {
        return [
            'bare ip'                 => ['192.168.1.10', '192.168.1.10'],
            'host name'               => ['media.lan', 'media.lan'],
            'host and port'           => ['192.168.1.10:8096', '192.168.1.10:8096'],
            'http scheme stripped'    => ['http://192.168.1.10', '192.168.1.10'],
            'https scheme stripped'   => ['HTTPS://media.lan', 'media.lan'],
            'trailing slash stripped' => ['http://192.168.1.10/', '192.168.1.10'],
            'pasted description url'  => ['http://192.168.1.10:8096/dlna/description.xml', '192.168.1.10:8096'],
            'surrounding whitespace'  => ['  192.168.1.10  ', '192.168.1.10'],
            'empty'                   => ['', ''],
            'whitespace only'         => ['   ', ''],
            'scheme only'             => ['http://', ''],
        ];
    }

    /**
     * @dataProvider sanitizeProvider
     */
    public function test_sanitize(string $input, string $expected): void
    {
        self::assertSame($expected, DlnaAdvertisedHost::sanitize($input));
    }

    /**
     * The specific trap `config/dlna.php` warns about: a scheme in the value
     * would compose into `http://http://…`.
     */
    public function test_a_url_shaped_setting_never_produces_a_double_scheme(): void
    {
        $this->bootstrapDlnaConfig(['advertise_host' => 'http://192.168.1.10/']);

        self::assertSame('http://192.168.1.10:8096', DlnaAdvertisedHost::baseUrl(8096));
    }

    // -----------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------

    public function test_a_configured_host_is_used_verbatim(): void
    {
        $this->bootstrapDlnaConfig(['advertise_host' => '10.0.0.7']);

        self::assertSame('10.0.0.7', DlnaAdvertisedHost::host());
        self::assertSame('http://10.0.0.7:8096', DlnaAdvertisedHost::baseUrl(8096));
    }

    /**
     * An empty setting — the shipped default — falls back to detection.
     *
     * Asserted on the SHAPE of the answer, not against
     * `SsdpAdvertiser::detectLocalIp()`'s return value. That helper opens a real
     * socket to 8.8.8.8:53 with a one-second timeout and answers `127.0.0.1`
     * when it times out, so calling it twice in one test is a coin flip: an
     * earlier draft of this file compared the two and failed with
     * `'69.10.33.243' !== '127.0.0.1'`. What matters here is that the empty
     * setting produced a usable address rather than an empty host.
     */
    public function test_an_empty_setting_falls_back_to_auto_detection(): void
    {
        $this->bootstrapDlnaConfig(['advertise_host' => '']);

        self::assertMatchesRegularExpression(
            '/^\d{1,3}(?:\.\d{1,3}){3}$/',
            DlnaAdvertisedHost::host(),
            'An empty advertise_host must fall back to a detected IPv4 address.'
        );
    }

    /**
     * A non-string setting (a hand-edited JSON override, say) is not trusted
     * into the URL — it falls back rather than composing `http://1:8096`.
     */
    public function test_a_non_string_setting_falls_back(): void
    {
        $this->bootstrapDlnaConfig(['advertise_host' => 'media.lan']);

        foreach ([true, null, ['a'], 42] as $value) {
            $resolved = DlnaAdvertisedHost::fromValue($value);

            self::assertMatchesRegularExpression('/^\d{1,3}(?:\.\d{1,3}){3}$/', $resolved);
            self::assertNotSame('media.lan', $resolved);
        }

        // CONTROL: a STRING value of the same setting IS honoured, so the
        // fallbacks above are a property of the value's type, not of the
        // resolver ignoring its argument.
        self::assertSame('media.lan', DlnaAdvertisedHost::fromValue('media.lan'));
    }

    // -----------------------------------------------------------------
    // The advertiser actually reads it now
    // -----------------------------------------------------------------

    /**
     * THE FIX. `SsdpAdvertiser::getIpAddress()` honours `dlna.advertise_host`.
     *
     * Before S53 it did not: `start.php` passes `null` for the IP, so the
     * method fell through to `detectLocalIp()` and the `LOCATION` header named
     * the auto-detected interface while the device description it pointed at
     * named the configured one.
     *
     * Built with `newInstanceWithoutConstructor()` because `SsdpAdvertiser`
     * extends `Workerman\Worker`, whose constructor registers the instance in
     * the process-static `Worker::$workers`.
     */
    public function test_the_ssdp_advertiser_honours_the_configured_host(): void
    {
        $this->bootstrapDlnaConfig(['advertise_host' => '203.0.113.9']);

        $advertiser = (new ReflectionClass(SsdpAdvertiser::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(SsdpAdvertiser::class, 'ipAddress'))->setValue($advertiser, null);
        (new ReflectionProperty(SsdpAdvertiser::class, 'port'))->setValue($advertiser, 8096);

        self::assertSame('203.0.113.9', $advertiser->getIpAddress());
        self::assertSame(
            'http://203.0.113.9:8096/dlna/description.xml',
            $advertiser->getLocationUrl()
        );
    }

    /**
     * An EXPLICIT constructor argument still wins over the setting — the
     * fallback is what changed, not the precedence.
     */
    public function test_an_explicit_ip_argument_still_wins_over_the_setting(): void
    {
        $this->bootstrapDlnaConfig(['advertise_host' => '203.0.113.9']);

        $advertiser = (new ReflectionClass(SsdpAdvertiser::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(SsdpAdvertiser::class, 'ipAddress'))->setValue($advertiser, '198.51.100.4');
        (new ReflectionProperty(SsdpAdvertiser::class, 'port'))->setValue($advertiser, 8096);

        self::assertSame('198.51.100.4', $advertiser->getIpAddress());
    }
}
