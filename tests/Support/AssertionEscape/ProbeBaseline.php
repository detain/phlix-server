<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\AssertionEscape;

use JsonException;
use RuntimeException;

/**
 * S180 — the tracked baseline that makes `scripts/assertion-escape-audit.php --probe`
 * safe to run as a CI gate.
 *
 * ## Why a baseline is needed at all
 *
 * The prober has four verdicts (see that script's header). Two of them —
 * `VACUOUS` and `DEGRADED` — are defects and have always exited 1. The third,
 * `NOT-REACHED`, means *the probe decided nothing*: the tripwire line never
 * executed, so the site is UNDECIDED, not verified. Before S180 that count lived
 * only in prose, and prose drifted: three different numbers ("4 NOT-REACHED",
 * "3 NOT-REACHED", "2 NOT-REACHED") were in the repo at once, each correct for a
 * tree state nobody could reconstruct. A count nothing checks is folklore.
 *
 * This file turns it into a checked fact. Every undecided site is enumerated
 * WITH a reason, and the prober reconciles what it observed against this list:
 *
 *  - an observed `NOT-REACHED` that is NOT listed here  ⇒ exit 1. A new site
 *    became undecided and nobody said why. Fix the test so the assertion runs,
 *    or add it here with a reason — deliberately, in a reviewable diff.
 *  - a listed site that came back DECIDED (`GATES`)      ⇒ exit 1. The entry has
 *    rotted; delete it. This is the direction a plain "known-issues list" never
 *    catches, which is how such lists become permanent.
 *  - a listed site that no longer EXISTS                 ⇒ exit 1, same reason.
 *
 * ## `excluded` is a different thing from `notReached`, on purpose
 *
 * `notReached` sites ARE probed; the probe just decides nothing about them.
 * `excluded` sites are NOT probed at all, and the only reason that is ever
 * acceptable is that probing them would make the gate report a defect that is
 * not there. There is exactly one such site today and its reason is recorded in
 * the JSON next to it. An exclusion is also reconciled: if the excluded site
 * disappears, the entry must go.
 *
 * ## What this class deliberately does NOT do
 *
 * It does not, and must not, grow a way to silence `VACUOUS` or `DEGRADED`.
 * Those are the findings the gate exists for; an allow-list over them would
 * reproduce the defect S120 was raised for. See
 * {@see EscapeCollector}'s "why there is deliberately no opt-out".
 */
final class ProbeBaseline
{
    /**
     * Path of the JSON document, relative to the repository root.
     *
     * Pinned as a constant because two consumers resolve it — the prober script
     * and {@see \Phlix\Tests\Unit\Support\AssertionEscapeProbeBaselineTest} — and
     * a gate whose data file can be silently renamed is a gate that can be
     * silently emptied.
     */
    public const RELATIVE_PATH = 'tests/Support/AssertionEscape/probe-baseline.json';

    /**
     * @param list<array{file: string, method: string, reason: string, recorded: string}> $excluded
     * @param list<array{file: string, method: string, reason: string, recorded: string}> $notReached
     */
    private function __construct(
        private readonly array $excluded,
        private readonly array $notReached,
    ) {
    }

    /**
     * @throws RuntimeException when the document is missing, unparseable or
     *                          structurally wrong. NEVER a silent default: a gate
     *                          that cannot read its own baseline must not report
     *                          success (the shape `scripts/coverage-threshold-check.php`
     *                          exists to avoid).
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                'S180: the assertion-escape probe baseline is missing at ' . $path . '. '
                . 'It records every UNDECIDED probe site and its reason; without it the '
                . 'prober cannot tell a newly-undecided site from a known one. Restore it '
                . 'from git — do not make the prober continue without it.',
            );
        }

        $raw = (string) file_get_contents($path);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'S180: ' . $path . ' is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('S180: ' . $path . ' must decode to an object.');
        }

        return new self(
            self::readEntries($decoded, 'excluded', $path),
            self::readEntries($decoded, 'notReached', $path),
        );
    }

    /**
     * @param array<mixed> $decoded
     * @return list<array{file: string, method: string, reason: string, recorded: string}>
     */
    private static function readEntries(array $decoded, string $key, string $path): array
    {
        if (!array_key_exists($key, $decoded) || !is_array($decoded[$key])) {
            throw new RuntimeException(
                'S180: ' . $path . ' must contain a "' . $key . '" array (it may be empty).',
            );
        }

        $entries = [];

        /** @var mixed $entry */
        foreach ($decoded[$key] as $index => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException(
                    'S180: ' . $path . ' → ' . $key . '[' . (string) $index . '] must be an object.',
                );
            }

            $normalised = [];

            foreach (['file', 'method', 'reason', 'recorded'] as $field) {
                $value = $entry[$field] ?? null;

                if (!is_string($value) || trim($value) === '') {
                    throw new RuntimeException(
                        'S180: ' . $path . ' → ' . $key . '[' . (string) $index . '] needs a non-empty "'
                        . $field . '". An entry without a REASON is the folklore this file replaces.',
                    );
                }

                $normalised[$field] = $value;
            }

            $entries[] = [
                'file' => $normalised['file'],
                'method' => $normalised['method'],
                'reason' => $normalised['reason'],
                'recorded' => $normalised['recorded'],
            ];
        }

        return $entries;
    }

    /** @return list<array{file: string, method: string, reason: string, recorded: string}> */
    public function excluded(): array
    {
        return $this->excluded;
    }

    /** @return list<array{file: string, method: string, reason: string, recorded: string}> */
    public function notReached(): array
    {
        return $this->notReached;
    }

    /**
     * The recorded reason this site is not probed, or `null` when it is probed.
     *
     * @param string $file Repository-relative path, as the prober prints it.
     */
    public function exclusionReason(string $file, string $method): ?string
    {
        foreach ($this->excluded as $entry) {
            if ($entry['file'] === $file && $entry['method'] === $method) {
                return $entry['reason'];
            }
        }

        return null;
    }

    /**
     * Compare a probe run against this baseline.
     *
     * Sites are keyed on `file` + `method`, never on a line number: line numbers
     * move on every unrelated edit above them, and a baseline that must be
     * re-blessed after every refactor is a baseline that gets deleted.
     *
     * @param list<array{file: string, method: string, verdict: string}> $observations
     *        Every site the prober actually probed, with its verdict.
     * @param list<array{file: string, method: string}> $skipped
     *        Every site the prober enumerated and did NOT probe because this
     *        baseline excluded it.
     * @return list<string> Human-readable violations; empty means reconciled.
     */
    public function reconcile(array $observations, array $skipped): array
    {
        $violations = [];

        foreach ($observations as $observation) {
            if ($observation['verdict'] !== 'NOT-REACHED') {
                continue;
            }

            if ($this->findEntry($this->notReached, $observation['file'], $observation['method']) === null) {
                $violations[] = sprintf(
                    'UNRECORDED NOT-REACHED: %s (%s). The probe decided NOTHING about this site — '
                    . 'the tripwire never executed. Either make the assertion run, or add it to %s '
                    . '"notReached" with a reason, so the undecided count stays a checked fact.',
                    $observation['file'],
                    $observation['method'],
                    self::RELATIVE_PATH,
                );
            }
        }

        foreach ($this->notReached as $entry) {
            $observed = $this->findEntry($observations, $entry['file'], $entry['method']);

            if ($observed === null) {
                $violations[] = sprintf(
                    'STALE BASELINE ENTRY: %s (%s) is recorded as NOT-REACHED in %s but the prober '
                    . 'no longer enumerates it. Delete the entry.',
                    $entry['file'],
                    $entry['method'],
                    self::RELATIVE_PATH,
                );

                continue;
            }

            if (($observed['verdict'] ?? '') !== 'NOT-REACHED') {
                $violations[] = sprintf(
                    'BASELINE ENTRY NOW DECIDED: %s (%s) is recorded as NOT-REACHED in %s but probed '
                    . '%s. That is good news — delete the entry so the undecided count drops.',
                    $entry['file'],
                    $entry['method'],
                    self::RELATIVE_PATH,
                    (string) ($observed['verdict'] ?? '?'),
                );
            }
        }

        foreach ($this->excluded as $entry) {
            if ($this->findEntry($skipped, $entry['file'], $entry['method']) === null) {
                $violations[] = sprintf(
                    'STALE EXCLUSION: %s (%s) is excluded from probing in %s but the prober no longer '
                    . 'enumerates it. Delete the entry — an exclusion nothing matches is a hole waiting '
                    . 'for a future site with the same name.',
                    $entry['file'],
                    $entry['method'],
                    self::RELATIVE_PATH,
                );
            }
        }

        return $violations;
    }

    /**
     * @param list<array<string, string>> $haystack
     * @return array<string, string>|null
     */
    private function findEntry(array $haystack, string $file, string $method): ?array
    {
        foreach ($haystack as $candidate) {
            if (($candidate['file'] ?? null) === $file && ($candidate['method'] ?? null) === $method) {
                return $candidate;
            }
        }

        return null;
    }
}
