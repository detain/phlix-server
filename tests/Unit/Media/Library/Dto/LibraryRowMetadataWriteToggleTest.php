<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library\Dto;

use Phlix\Media\Library\Dto\LibraryRow;
use PHPUnit\Framework\TestCase;

/**
 * S87 — the per-library metadata write-back toggle read from the same
 * `libraries.options` JSON column S33 uses for auto-collections, with the
 * DELIBERATELY INVERTED default: absent/malformed reads as OFF, because
 * writing metadata back to disk mutates the operator's media library and is
 * opt-in. Explicit truthy values coerce through the shared optionIsTruthy()
 * grammar (bool / int / "1"/"true"/"yes"/"on").
 */
final class LibraryRowMetadataWriteToggleTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     */
    private function row(array $options): LibraryRow
    {
        return LibraryRow::fromRow([
            'id' => 'lib-1',
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/mnt/media'],
            'options' => $options,
        ]);
    }

    public function test_absent_options_key_means_off(): void
    {
        $this->assertFalse(
            $this->row([])->metadataWriteEnabled(),
            'S87: a library that never stored the flag must NOT be queued for disk writes.'
        );
    }

    public function test_absent_enabled_key_inside_block_means_off(): void
    {
        $this->assertFalse($this->row(['metadataWrite' => []])->metadataWriteEnabled());
    }

    public function test_malformed_block_means_off(): void
    {
        $this->assertFalse($this->row(['metadataWrite' => 'yes please'])->metadataWriteEnabled());
    }

    public function test_explicit_true_enables(): void
    {
        $this->assertTrue($this->row(['metadataWrite' => ['enabled' => true]])->metadataWriteEnabled());
    }

    public function test_explicit_false_disables(): void
    {
        $this->assertFalse($this->row(['metadataWrite' => ['enabled' => false]])->metadataWriteEnabled());
    }

    /**
     * Truthy coercion parity with optionIsTruthy(): the JSON round-trip can
     * carry strings where a UI stored them verbatim.
     *
     * @param scalar $stored
     *
     * @dataProvider truthyStoredValues
     */
    public function test_truthy_coercions_enable($stored): void
    {
        $this->assertTrue(
            $this->row(['metadataWrite' => ['enabled' => $stored]])->metadataWriteEnabled(),
            'Stored value ' . var_export($stored, true) . ' must read as enabled.'
        );
    }

    /**
     * @return array<string, array{scalar}>
     */
    public static function truthyStoredValues(): array
    {
        return [
            'bool true' => [true],
            'int 1' => [1],
            'string 1' => ['1'],
            'string true' => ['true'],
            'string yes' => ['yes'],
            'string on' => ['on'],
        ];
    }

    /**
     * @param scalar $stored
     *
     * @dataProvider falsyStoredValues
     */
    public function test_falsy_coercions_disable($stored): void
    {
        $this->assertFalse(
            $this->row(['metadataWrite' => ['enabled' => $stored]])->metadataWriteEnabled(),
            'Stored value ' . var_export($stored, true) . ' must read as disabled.'
        );
    }

    /**
     * @return array<string, array{scalar}>
     */
    public static function falsyStoredValues(): array
    {
        return [
            'bool false' => [false],
            'int 0' => [0],
            'string 0' => ['0'],
            'string false' => ['false'],
            'string no' => ['no'],
            'empty string' => [''],
        ];
    }

    public function test_toggle_is_independent_of_the_s33_autocollections_gate(): void
    {
        $row = $this->row([
            'autoCollections' => ['enabled' => false],
            'metadataWrite' => ['enabled' => true],
        ]);

        $this->assertFalse($row->autoCollectionsEnabled());
        $this->assertTrue($row->metadataWriteEnabled(), 'Two gates, two keys, no cross-talk.');
    }
}
