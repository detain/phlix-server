<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Psr\Log\AbstractLogger;

/**
 * Records every log call, so a test can count how often each level was used: a refusal
 * should be warned once, a recovery should be logged at info, and a removal read at debug.
 */
final class ProbeCacheRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $entries = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->entries[] = ['level' => is_string($level) ? $level : '', 'message' => (string) $message];
    }

    /**
     * @return list<string> The messages logged at one level, in order.
     */
    public function messages(string $level): array
    {
        $messages = [];
        foreach ($this->entries as $entry) {
            if ($entry['level'] === $level) {
                $messages[] = $entry['message'];
            }
        }

        return $messages;
    }
}
