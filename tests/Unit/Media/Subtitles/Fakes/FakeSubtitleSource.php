<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Subtitles\Fakes;

use Phlix\Shared\Subtitle\Exception\QuotaExceeded;
use Phlix\Shared\Subtitle\SubtitleCandidate;
use Phlix\Shared\Subtitle\SubtitleFile;
use Phlix\Shared\Subtitle\SubtitleSourceInterface;

/**
 * Configurable in-memory {@see SubtitleSourceInterface} for unit tests: it
 * returns a canned candidate list from its search methods and either returns a
 * canned {@see SubtitleFile} or throws a canned {@see QuotaExceeded} from
 * download(). It records how many times download() ran so tests can assert a
 * provider was (or was not) reached.
 */
final class FakeSubtitleSource implements SubtitleSourceInterface
{
    public int $downloadCalls = 0;
    public int $searchByPathCalls = 0;
    public int $searchByImdbIdCalls = 0;

    /**
     * @param non-empty-string        $name
     * @param list<SubtitleCandidate> $candidates candidates returned by searchByPath()
     */
    public function __construct(
        private string $name,
        private int $priority = 0,
        private array $candidates = [],
        private ?SubtitleFile $file = null,
        private ?QuotaExceeded $quota = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function searchByPath(string $path, array $languages): array
    {
        $this->searchByPathCalls++;

        return $this->candidates;
    }

    public function searchByHash(string $movieHash, int $byteSize, array $languages): array
    {
        return $this->candidates;
    }

    public function searchByImdbId(string $imdbId, array $languages): array
    {
        $this->searchByImdbIdCalls++;

        return $this->candidates;
    }

    public function download(SubtitleCandidate $candidate): SubtitleFile
    {
        $this->downloadCalls++;

        if ($this->quota !== null) {
            throw $this->quota;
        }

        return $this->file ?? new SubtitleFile(
            language: $candidate->language,
            format: 'vtt',
            content: "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nhi\n",
            provider: $this->name,
            suggestedFilename: 'sub.vtt',
        );
    }
}
