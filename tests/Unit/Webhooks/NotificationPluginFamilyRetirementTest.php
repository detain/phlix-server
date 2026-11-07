<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;

/**
 * Review server__qa Major — the webhook notification plugin family was dead
 * code on a permanent vacation: eight classes under `Phlix\Webhooks\Plugins`
 * (an abstract base plus seven channel plugins) were never instantiated by
 * anything. The live delivery path is `WebhookDispatcher` + `WebhookService`
 * + subscription URLs; the per-channel surface lived on without a single
 * caller, and the first security question it raises on a future read is
 * whether its un-tested egress is still honest.
 *
 * Ruling: retire the family outright rather than carry it. These pins hold
 * the retirement the way the estate holds every other retired subsystem — a
 * grep tripwire that reddens the moment the code (or a fresh copy of the
 * config file that only it read) tries to come back, so nobody has to
 * re-derive from the CHANGELOG which deletion was deliberate.
 *
 * The SsrfGuard `surfaces` docblock still lists the live webhook legs: that
 * law is unchanged. What dies here is only the family that never ran.
 */
final class NotificationPluginFamilyRetirementTest extends TestCase
{
    /** @var list<string> the eight retired class names, in family order */
    private const RETIRED_CLASSES = [
        'Phlix\\Webhooks\\Plugins\\AbstractNotificationPlugin',
        'Phlix\\Webhooks\\Plugins\\ApprisePlugin',
        'Phlix\\Webhooks\\Plugins\\DiscordPlugin',
        'Phlix\\Webhooks\\Plugins\\MqttPlugin',
        'Phlix\\Webhooks\\Plugins\\NtfyPlugin',
        'Phlix\\Webhooks\\Plugins\\PushoverPlugin',
        'Phlix\\Webhooks\\Plugins\\SlackPlugin',
        'Phlix\\Webhooks\\Plugins\\TelegramPlugin',
    ];

    /**
     * The family may not come back under its own name, wherever it lands:
     * a PSR-4 slot assertion per retired class (nobody re-claims the path the
     * autoloader resolved), plus a first-party class-DEFINITION scan that
     * catches a re-dropped declaration under a different namespace — a slot
     * check alone would miss that. (A `class_exists()` formulation was the
     * first draft and is now permanently wrong here: phpstan level 4 folds
     * the literal to a constant-false and the check would be theater.)
     */
    public function testEveryRetiredFamilyClassIsGone(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (self::RETIRED_CLASSES as $class) {
            $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen('Phlix\\'))) . '.php';
            $this->assertFileDoesNotExist(
                $path,
                "the retired family's autoload slot for {$class} must stay empty",
            );
        }

        $definition = '/\bclass\s+(?:AbstractNotification|Apprise|Discord|Mqtt|Ntfy|Pushover|Slack|Telegram)Plugin\b/';
        $offenders = [];
        $scanned = 0;

        $walk = static function (string $dir) use (&$walk, &$offenders, &$scanned, $root, $definition): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (is_dir($path)) {
                    $walk($path);
                } elseif (str_ends_with($entry, '.php')) {
                    $scanned++;
                    if (preg_match($definition, (string) file_get_contents($path)) === 1) {
                        $offenders[] = substr($path, strlen($root) + 1);
                    }
                }
            }
        };

        foreach (['src', 'config', 'bin'] as $scanDir) {
            $walk($root . '/' . $scanDir);
        }
        $scanned++;
        if (preg_match($definition, (string) file_get_contents($root . '/start.php')) === 1) {
            $offenders[] = 'start.php';
        }

        $this->assertGreaterThan(0, $scanned, 'parser teeth: the definition scan must actually see first-party files');
        $this->assertSame(
            [],
            $offenders,
            'no first-party file may re-declare a retired notification plugin class: ' . implode(', ', $offenders),
        );
    }

    public function testTheFamilyDirectoryHoldsNoPhpFiles(): void
    {
        $dir = dirname(__DIR__, 3) . '/src/Webhooks/Plugins';

        if (!is_dir($dir)) {
            // The whole directory is gone — the strictest possible outcome.
            $this->addToAssertionCount(1);
            return;
        }

        $strays = array_values(array_filter(
            scandir($dir) ?: [],
            static fn (string $entry): bool => str_ends_with($entry, '.php')
        ));
        $this->assertSame(
            [],
            $strays,
            'the retired notification-plugin directory must not hold PHP files again; '
            . 'per-channel notification bridges return only through a reviewed contract',
        );
    }

    public function testTheFamilyOnlyConfigFileIsGone(): void
    {
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 3) . '/config/notifications.php',
            'config/notifications.php was read exclusively by the retired family — '
            . 'it must not come back as an orphan template',
        );
    }

    public function testNoFirstPartyCodeReferencesTheFamilyNamespace(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];

        $walk = static function (string $dir) use (&$walk, &$offenders, $root): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (is_dir($path)) {
                    if ($entry === 'vendor' || $entry === 'node_modules') {
                        continue;
                    }
                    $walk($path);
                } elseif (str_ends_with($entry, '.php') || $entry === 'start.php' || $entry === 'phlix') {
                    $text = (string) file_get_contents($path);
                    if (preg_match('/Webhooks\\\\Plugins\\\\(?:Abstract|Apprise|Discord|Mqtt|Ntfy|Pushover|Slack|Telegram)/', $text) === 1) {
                        $offenders[] = substr($path, strlen($root) + 1);
                    }
                }
            }
        };

        // src/ + config/ + bin/ carry first-party PHP (the test directory
        // legitimately keeps this file's own provenance prose); start.php is
        // the root daemon entry, checked explicitly.
        foreach (['src', 'config', 'bin'] as $scanDir) {
            $walk($root . '/' . $scanDir);
        }
        if (preg_match('/Webhooks\\\\Plugins\\\\(?:Abstract|Apprise|Discord|Mqtt|Ntfy|Pushover|Slack|Telegram)/', (string) file_get_contents($root . '/start.php')) === 1) {
            $offenders[] = 'start.php';
        }

        $this->assertSame(
            [],
            $offenders,
            'no first-party code may reference the retired notification-plugin family: ' . implode(', ', $offenders),
        );
    }
}
