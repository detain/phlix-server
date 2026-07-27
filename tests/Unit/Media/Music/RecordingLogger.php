<?php

/**
 * Phlix media server test double: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Psr\Log\AbstractLogger;

/**
 * Captures warnings so a truncation can be asserted rather than assumed, and every
 * record with its CONTEXT so a completion summary can be asserted key by key.
 *
 * @internal
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_scalar($level) ? (string) $level : '?',
            'message' => (string) $message,
            'context' => $context,
        ];

        if ($level === 'warning') {
            $this->warnings[] = (string) $message;
        }
    }

    /**
     * The context of the last record whose message contains `$needle`.
     *
     * @param string $needle Message substring.
     * @return array<string, mixed>|null
     */
    public function contextOf(string $needle): ?array
    {
        foreach (array_reverse($this->records) as $record) {
            if (str_contains($record['message'], $needle)) {
                return $record['context'];
            }
        }

        return null;
    }
}
