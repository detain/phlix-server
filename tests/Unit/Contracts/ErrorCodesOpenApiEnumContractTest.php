<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * W2 (error-code doctrine) — `openapi.yaml` `Error.code` carries a closed enum of
 * exactly the codes the server can place on that field, and the enum cannot drift
 * from either side.
 *
 * ## The law
 *
 * `components.schemas.Error.properties.code.enum` must equal, in order,
 * {@see ErrorCodesContractTest::rest_emittable_codes()} — the positional scan of
 * every code-channel literal in `src/` (registry-checked by
 * {@see ErrorCodesContractTest}) plus the declared {@see ErrorCodesContractTest::DYNAMIC_CODE_CHANNELS}
 * exception-carried codes, filtered to the registry's declared order. Exact set
 * equality, not subset: an enum member the server cannot emit is a spec lie in one
 * direction, an emittable code missing from the enum is a spec lie in the other.
 *
 * ## Why the WS channel is absent (and that is not a gap)
 *
 * SyncPlay WebSocket frames travel on a different envelope — the `syncplay_error`
 * message typed by the SyncPlay wire SPEC (@phlix/syncplay), carrying the legacy
 * SCREAMING vocabulary plus the dotted `syncplay.*` twins. This OpenAPI document
 * describes the HTTP surface only; pinning WS codes here would document a field
 * that HTTP never carries.
 *
 * ## Red-green record (lane evidence, PR description mirrors it)
 *
 * Removing a member from the YAML enum reddens {@see test_the_error_code_enum_equals_the_emittable_set()};
 * adding a phantom member reddens it the same way; an unregistered literal in `src/`
 * is caught upstream by {@see ErrorCodesContractTest} before this set is even built.
 */
final class ErrorCodesOpenApiEnumContractTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../../openapi.yaml';

    /** Floor mirroring the emit law's scan floor (drift alarm, not a boundary). */
    private const MIN_ENUM_MEMBERS = 40;

    /** @return list<string> */
    private function enum_members(): array
    {
        $this->assertFileExists(self::SPEC);
        $spec = Yaml::parseFile(self::SPEC);
        $this->assertIsArray($spec);

        $code = $spec['components']['schemas']['Error']['properties']['code'] ?? null;
        $this->assertIsArray($code, 'components.schemas.Error.properties.code vanished from the spec.');

        $enum = $code['enum'] ?? null;
        $this->assertIsArray(
            $enum,
            'Error.code lost its enum — W2 pinned it to the server-emittable code set; see this class docblock.',
        );
        /** @var list<string> $enum */
        return $enum;
    }

    public function test_the_error_code_enum_is_present_and_non_vacuous(): void
    {
        $enum = $this->enum_members();

        $this->assertGreaterThanOrEqual(
            self::MIN_ENUM_MEMBERS,
            count($enum),
            'a truncated enum cannot pass: the floor records the size of the emit-side census at adoption time.',
        );
        $this->assertSame(array_values(array_unique($enum)), $enum, 'enum members must be unique.');
    }

    public function test_every_enum_member_is_a_registry_code(): void
    {
        $registry = array_flip(ErrorCodesContractTest::registry_codes());

        foreach ($this->enum_members() as $member) {
            $this->assertIsString($member);
            $this->assertArrayHasKey(
                $member,
                $registry,
                "openapi Error.code enum carries '{$member}' which is not in the vendored @phlix/contracts registry.",
            );
        }
    }

    public function test_the_error_code_enum_equals_the_emittable_set(): void
    {
        $this->assertSame(
            ErrorCodesContractTest::rest_emittable_codes(),
            $this->enum_members(),
            'openapi Error.code enum drifted from the server-emittable set (registry order). '
            . 'Fix the emitter or the enum — never add a member the server cannot emit.',
        );
    }
}
