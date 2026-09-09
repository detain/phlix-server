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
 * Minimal seam for running an external binary (ffmpeg) from
 * {@see EmbeddedMetadataWriter} (S89).
 *
 * Why a seam at all: the writer's load-bearing promise is atomic-rename
 * discipline — a remux interrupted at ANY point (power loss, OOM kill, codec
 * refusal mid-write) must leave the ORIGINAL media file byte-identical. That
 * property lives in this class's caller, not in ffmpeg, and the only honest way
 * to test every interruption exit path (including the SIGKILL-shaped one the
 * real binary cannot be reliably timed to produce) is to inject the process
 * runner and drive it through each outcome. The production implementation is a
 * blocking `exec()` wrapper — legitimate here because {@see MetadataWriteWorker}
 * documents that writers run blocking filesystem I/O of unknown cost in their
 * own managed fork (S87 AC 2), never on the scan path or an HTTP worker.
 *
 * Command-line assembly (binary path, input, output, every metadata value) is
 * the CALLER's job — it must escape everything with escapeshellarg() before
 * handing a line over. This interface never takes structured arguments and
 * rebuilds a shell line: what the caller quotes is exactly what runs.
 *
 * @since S89
 */
interface ExternalCommandRunnerInterface
{
    /**
     * Run one fully built, shell-escaped command line to completion.
     *
     * @param string $commandLine Complete command line (already escaped by the caller).
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     *         `exitCode` is the process exit status. Two kill shapes exist and
     *         the caller must treat BOTH as failure: real shells report
     *         signal-death as `128 + signum` (what {@see ExecExternalCommandRunner}
     *         surfaces — measured), while an injected runner may report the
     *         negative `-signum` shape directly; {@see EmbeddedMetadataWriter}
     *         fails on any non-zero value, whichever shape carries it.
     *         `stdout`/`stderr` are whatever the process emitted.
     */
    public function run(string $commandLine): array;
}
