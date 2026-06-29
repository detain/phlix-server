<?php

declare(strict_types=1);

namespace Phlix\Common;

/**
 * Shared UUID v4 generator.
 *
 * Produces UUID v4 strings in the standard format:
 * `550e8400-e29b-41d4-a716-446655440000`
 *
 * The format matches the historical per-class generateUuid() implementations
 * scattered throughout the codebase (sprintf with mt_rand, version 0x4000,
 * variant 0x8000). Centralising it here eliminates ~32 duplicate definitions.
 *
 * @package Phlix\Common
 */
final readonly class Uuid
{
    private const string UUID_FORMAT = '%04x%04x-%04x-%04x-%04x-%04x%04x%04x';

    /**
     * Generate a random UUID v4 string.
     *
     * @return string UUID in standard format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    public static function v4(): string
    {
        return sprintf(
            self::UUID_FORMAT,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }

    /**
     * Prevent instantiation — static methods only.
     */
    private function __construct()
    {
    }
}
