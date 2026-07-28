<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container;

use DI\ContainerBuilder;
use Phlix\Common\Container\DegradedBuild;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;

use function DI\value;

/**
 * {@see DegradedBuild} is the reporting seam for a provider that built something
 * in a degraded state.
 *
 * The value it has to deliver is narrow but load-bearing: the call site is, by
 * definition, one where the container is ALREADY misbehaving, so the helper must
 * not become the second thing that fails — and it must always carry enough
 * detail to tell a connection refusal apart from an auth failure.
 *
 * @covers \Phlix\Common\Container\DegradedBuild
 */
final class DegradedBuildTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    /**
     * @param list<array{message: string, context: array<string, mixed>}> $sink
     */
    private function recordingLogger(array &$sink): StructuredLogger
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->method('warning')->willReturnCallback(
            static function (string|\Stringable $message, array $context = []) use (&$sink): void {
                $sink[] = ['message' => (string) $message, 'context' => $context];
            }
        );

        return $logger;
    }

    private function containerWith(array $definitions): \DI\Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(false);
        if ($definitions !== []) {
            $builder->addDefinitions($definitions);
        }

        return $builder->build();
    }

    /**
     * The container's wired channel logger is preferred, so a degraded build is
     * reported down the same pipeline as everything else on that channel.
     */
    public function test_it_prefers_the_containers_wired_channel_logger(): void
    {
        $seen = [];
        $container = $this->containerWith(['logger.media' => value($this->recordingLogger($seen))]);

        DegradedBuild::warn($container, LogChannels::MEDIA, 'something degraded', new \RuntimeException('boom'));

        $this->assertCount(1, $seen);
        $this->assertSame('something degraded', $seen[0]['message']);
    }

    /**
     * CONSEQUENCE: the exception CLASS and MESSAGE are always recorded. Without
     * them the log cannot distinguish "connection refused" from "access denied",
     * which is exactly the discrimination an operator needs.
     */
    public function test_it_always_records_the_exception_class_and_message(): void
    {
        $seen = [];
        $container = $this->containerWith(['logger.media' => value($this->recordingLogger($seen))]);

        DegradedBuild::warn(
            $container,
            LogChannels::MEDIA,
            'degraded',
            new \RuntimeException('SQLSTATE[HY000] [1045] Access denied')
        );

        $this->assertSame(\RuntimeException::class, $seen[0]['context']['exception'] ?? null);
        $this->assertSame('SQLSTATE[HY000] [1045] Access denied', $seen[0]['context']['message'] ?? null);
    }

    /**
     * Caller context is merged in alongside the exception fields — that is how
     * the TMDB site reports `fallback_key_present`.
     */
    public function test_it_merges_caller_context(): void
    {
        $seen = [];
        $container = $this->containerWith(['logger.media' => value($this->recordingLogger($seen))]);

        DegradedBuild::warn(
            $container,
            LogChannels::MEDIA,
            'degraded',
            new \RuntimeException('boom'),
            ['fallback_key_present' => false]
        );

        $this->assertFalse($seen[0]['context']['fallback_key_present'] ?? null);
        $this->assertSame(\RuntimeException::class, $seen[0]['context']['exception'] ?? null);
    }

    /**
     * CONSEQUENCE: a container with NO logger binding must still report, not
     * throw. The whole point of the call site is that the container is already
     * failing; a logger lookup must not be the second failure.
     */
    public function test_a_container_without_a_logger_binding_does_not_throw(): void
    {
        $container = $this->containerWith([]);

        DegradedBuild::warn($container, LogChannels::MEDIA, 'degraded', new \RuntimeException('boom'));

        $this->addToAssertionCount(1);
    }

    /**
     * …and neither does a container whose logger entry THROWS on resolution.
     */
    public function test_a_container_whose_logger_entry_throws_does_not_throw(): void
    {
        $container = $this->containerWith([
            'logger.media' => \DI\factory(static function (): StructuredLogger {
                throw new \RuntimeException('logger unavailable');
            }),
        ]);

        DegradedBuild::warn($container, LogChannels::MEDIA, 'degraded', new \RuntimeException('boom'));

        $this->addToAssertionCount(1);
    }

    /**
     * A logger entry of the wrong type must not be used blindly.
     */
    public function test_a_non_logger_binding_is_ignored(): void
    {
        $container = $this->containerWith(['logger.media' => value('not a logger')]);

        DegradedBuild::warn($container, LogChannels::MEDIA, 'degraded', new \RuntimeException('boom'));

        $this->addToAssertionCount(1);
    }

    /**
     * The channel is honoured — DLNA degradation must not land on the media
     * channel, or the DLNA log stays empty while app.log gets the noise.
     */
    public function test_it_resolves_the_logger_for_the_requested_channel(): void
    {
        $media = [];
        $dlna = [];
        $container = $this->containerWith([
            'logger.media' => value($this->recordingLogger($media)),
            'logger.dlna' => value($this->recordingLogger($dlna)),
        ]);

        DegradedBuild::warn($container, LogChannels::DLNA, 'dlna degraded', new \RuntimeException('boom'));

        $this->assertCount(1, $dlna, 'The DLNA channel logger was not used.');
        $this->assertSame([], $media, 'The degradation landed on the wrong channel.');
    }
}
