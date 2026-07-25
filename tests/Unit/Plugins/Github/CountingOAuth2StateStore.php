<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Github;

use Phlix\Plugins\OAuth2\OAuth2StateStore;

/**
 * In-memory {@see OAuth2StateStore} that COUNTS the state rows it was asked to
 * write.
 *
 * Exists for the S48 review r3 fail-closed assertions: when no callback URL can be
 * resolved the authorize leg must issue neither a correlation cookie nor a state
 * row, and "no state row" needs a positive assertion. The production
 * {@see \Phlix\Plugins\OAuth2\InMemoryOAuth2StateStore} is final and deliberately
 * exposes no size accessor, so the counter lives here in the test fixture instead
 * of being bolted onto production code.
 *
 * @internal Test fixture only.
 */
final class CountingOAuth2StateStore implements OAuth2StateStore
{
    /** How many times {@see put()} was called. */
    public int $puts = 0;

    /** @var array<string, array{code_verifier: string, context?: array<string, mixed>}> */
    private array $entries = [];

    public function put(string $state, string $codeVerifier, ?array $context = null): void
    {
        $this->puts++;
        $entry = ['code_verifier' => $codeVerifier];
        if ($context !== null && $context !== []) {
            $entry['context'] = $context;
        }
        $this->entries[$state] = $entry;
    }

    public function consume(string $state): ?array
    {
        if (!isset($this->entries[$state])) {
            return null;
        }
        $entry = $this->entries[$state];
        unset($this->entries[$state]);

        return $entry;
    }
}
