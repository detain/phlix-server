<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Concerns;

use Phlix\Console\Commands\Concerns\JsonOutput;
use Phlix\Console\Commands\UserApproveCommand;
use Phlix\Console\Commands\UserCreateCommand;
use Phlix\Console\Commands\UserDeleteCommand;
use Phlix\Console\Commands\UserDisableCommand;
use Phlix\Console\Commands\UserListCommand;
use Phlix\Console\Commands\UserPromoteCommand;
use Phlix\Console\Commands\UserRejectCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SSOT pins for {@see JsonOutput::fail()} — the single context-carrying
 * failure renderer the server `user:*` commands now share (A-06 wave-5
 * adoption, SRV-CONSOLE lane).
 *
 * Before this commit the server copy of the trait had no failure leg: each of
 * `user:promote`, `user:disable`, `user:reject`, `user:approve`,
 * `user:delete`, and `user:create` carried its own private `fail()` clone with
 * identical bodies (the create clone took a required exit code; the other five
 * hard-returned FAILURE), and `user:list` inlined the same render twice. The
 * pins below are the red-first net: the direct trait calls were written against
 * the surface the adoption promises (plain render byte-identical to the old
 * clones, envelope byte-identical, FAILURE default, exit-code pass-through),
 * and the census test names every command that must resolve `fail()` to the
 * trait — so a future local re-clone reddens CI even if its bytes happen to
 * match.
 *
 * The hub twin shipped the same fold first (`phlix-hub` HUB-CONSOLE lane,
 * commit 47715678, tests/Unit/Console/Concerns/JsonOutputTraitTest.php); with
 * this adoption the two trait copies re-converge to exact clones of each
 * other, which is what both header docblocks always claimed.
 * {@see UserCommandOutputByteMatrixTest} holds the end-to-end byte net for the
 * commands themselves; this file pins the shared primitive.
 *
 * @package Phlix\Tests\Unit\Console\Concerns
 */
class JsonOutputTraitTest extends TestCase
{
    /**
     * Builds the trait-exposing sink inline per test: PHPStan types an
     * anonymous class only at its creation site, and the repo convention keeps
     * every test file to a single top-level declaration, so each test owns its
     * double instead of sharing a factory. `failDefault()` forwards THREE
     * arguments only, pinning the trait's own default parameter — a sink-side
     * default would mask a change to the shared signature.
     */
    private function definition(): InputDefinition
    {
        return new InputDefinition([
            new InputOption('json', null, InputOption::VALUE_NONE),
        ]);
    }

    /**
     * The default-arm contract: omitting the exit code returns FAILURE (1),
     * exactly what the five 3-argument clones hard-returned.
     */
    public function testFailDefaultsToCommandFailure(): void
    {
        $sink = new class extends Command {
            use JsonOutput;

            public function failDefault(InputInterface $input, OutputInterface $output, string $error): int
            {
                return $this->fail($input, $output, $error);
            }
        };
        $input = new ArrayInput([], $this->definition());
        $output = new BufferedOutput();

        $this->assertSame(Command::FAILURE, $sink->failDefault($input, $output, 'Nope.'));
        $this->assertSame("Nope.\n", $output->fetch());
    }

    /**
     * The pass-through arm: `user:create` needs INVALID (2) for validation and
     * collision refusals, FAILURE (1) for repository throws — the trait
     * returns whatever exit code it is handed, unchanged.
     */
    public function testFailPassesTheExplicitExitCodeThrough(): void
    {
        $sink = new class extends Command {
            use JsonOutput;

            public function failWithCode(
                InputInterface $input,
                OutputInterface $output,
                string $error,
                int $exitCode,
            ): int {
                return $this->fail($input, $output, $error, $exitCode);
            }
        };
        $input = new ArrayInput([], $this->definition());
        $output = new BufferedOutput();

        $this->assertSame(Command::INVALID, $sink->failWithCode($input, $output, 'Bad.', Command::INVALID));
        $this->assertSame("Bad.\n", $output->fetch());
    }

    /**
     * Without `--json` the render is the bare message plus newline — the
     * undecorated form of the old clones' `<error>` tag writes (the matrix
     * fixtures captured the same bytes because tests run undecorated; this is
     * the SSOT-side restatement of that contract).
     */
    public function testPlainModeRendersBareMessageLine(): void
    {
        $sink = new class extends Command {
            use JsonOutput;

            public function failDefault(InputInterface $input, OutputInterface $output, string $error): int
            {
                return $this->fail($input, $output, $error);
            }
        };
        $input = new ArrayInput([], $this->definition());
        $output = new BufferedOutput();

        $sink->failDefault($input, $output, 'Refusing to delete user "alice"; re-run with --force to confirm.');

        $this->assertSame(
            "Refusing to delete user \"alice\"; re-run with --force to confirm.\n",
            $output->fetch(),
        );
    }

    /**
     * With `--json` the render is the single-line `{"ok":false,"error":…}`
     * envelope, key order pinned — the shape scripts branch on.
     */
    public function testJsonModeRendersFailureEnvelope(): void
    {
        $sink = new class extends Command {
            use JsonOutput;

            public function failDefault(InputInterface $input, OutputInterface $output, string $error): int
            {
                return $this->fail($input, $output, $error);
            }
        };
        $input = new ArrayInput(['--json' => true], $this->definition());
        $output = new BufferedOutput();

        $sink->failDefault($input, $output, 'User not found: ghost');

        $this->assertSame(
            "{\"ok\":false,\"error\":\"User not found: ghost\"}\n",
            $output->fetch(),
        );
    }

    /**
     * Embedded quotes and unicode survive the envelope escaping unchanged
     * (`JSON_UNESCAPED_UNICODE` posture of the shared encoder).
     */
    public function testJsonModeEscapesQuotesAndKeepsUnicodeUnescaped(): void
    {
        $sink = new class extends Command {
            use JsonOutput;

            public function failDefault(InputInterface $input, OutputInterface $output, string $error): int
            {
                return $this->fail($input, $output, $error);
            }
        };
        $input = new ArrayInput(['--json' => true], $this->definition());
        $output = new BufferedOutput();

        $sink->failDefault($input, $output, 'Feu "d\'école" – über');

        $this->assertSame(
            "{\"ok\":false,\"error\":\"Feu \\\"d'école\\\" – über\"}\n",
            $output->fetch(),
        );
    }

    /**
     * Census adoption teeth: every migrated command resolves `fail()` to the
     * trait — a re-introduced private clone moves the method's source location
     * off the trait file and reddens this pin (mechanics and failure modes are
     * spelled out in the test body).
     *
     * @return array<string, array{0: class-string}>
     */
    public static function adoptedCommands(): array
    {
        return [
            'promote' => [UserPromoteCommand::class],
            'disable' => [UserDisableCommand::class],
            'approve' => [UserApproveCommand::class],
            'reject' => [UserRejectCommand::class],
            'create' => [UserCreateCommand::class],
            'delete' => [UserDeleteCommand::class],
            'list' => [UserListCommand::class],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('adoptedCommands')]
    public function testFailResolvesToTheTraitOnEveryAdoptedCommand(string $class): void
    {
        $method = (new ReflectionClass($class))->getMethod('fail');

        // PHP copies trait methods into the using class, so getDeclaringClass()
        // reports the command; the method's source location still resolves to
        // the trait file. A re-introduced command-local fail() — or dropping
        // the trait use — moves that file off JsonOutput.php and reddens this
        // pin with a real fatality (no fail() left to resolve).
        $this->assertSame(
            (new ReflectionClass(JsonOutput::class))->getFileName(),
            $method->getFileName(),
            $class . ' must render failures through the JsonOutput trait, not a local clone',
        );
        $this->assertContains(
            JsonOutput::class,
            class_uses($class),
            $class . ' must use the JsonOutput trait directly',
        );
    }
}
