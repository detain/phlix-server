<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Writer;

/**
 * Production {@see ExternalCommandRunnerInterface}: blocking `exec()` of one
 * already-escaped command line (S89).
 *
 * Blocking is deliberate and documented: the only caller is
 * {@see EmbeddedMetadataWriter}, which only ever runs inside the managed
 * `metadata-write` fork where blocking filesystem and process I/O is the
 * established contract (S87 AC 2, {@see MetadataWriteWorker} class docblock) —
 * never on the scan path or an HTTP worker, so no coroutine yield is needed.
 *
 * stderr is separated from stdout via a private redirect file rather than a
 * `2>&1` merge: ffmpeg writes its banner and progress to stderr, and mixing it
 * into stdout would garble anything a future consumer parses there.
 *
 * @since S89
 */
final class ExecExternalCommandRunner implements ExternalCommandRunnerInterface
{
    /**
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function run(string $commandLine): array
    {
        $stderrTemp = tempnam(sys_get_temp_dir(), 'phlix_execcmd_');
        if ($stderrTemp === false) {
            // No temp file => no way to separate stderr; fail LOUD (fail-fast
            // law) instead of silently merging streams and returning a shape
            // the caller cannot distinguish from a real ffmpeg diagnostic.
            return ['exitCode' => -1, 'stdout' => '', 'stderr' => 'tempnam() failed for stderr capture'];
        }

        try {
            /** @var list<string> $outputLines */
            $outputLines = [];
            $exitCode = 0;

            // The caller escapes every argument; ONLY the redirect target here
            // is ours, so escapeshellarg() on it keeps the line injection-free
            // regardless of what $commandLine contains.
            @exec($commandLine . ' 2>' . escapeshellarg($stderrTemp), $outputLines, $exitCode);

            return [
                'exitCode' => $exitCode,
                'stdout' => implode("\n", $outputLines),
                'stderr' => (string) @file_get_contents($stderrTemp),
            ];
        } finally {
            @unlink($stderrTemp);
        }
    }
}
