<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Contracts;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Positional code-channel scanner for the W2 error-code doctrine.
 *
 * Finds every expression `src/` places on a wire `code`/`error_code` field —
 * via an array key (`'code' => …`, `'error_code' => …`), a registered emitter's
 * code argument (`Messages::error()` arg 0, `sendError()` arg 1,
 * `Response::error()` / `jsonError()` arg 1), and reports the literal strings,
 * resolvable `Class::CONST` references, and unresolvable (dynamic) expressions
 * found at those positions. Comments and docblocks are naturally excluded: the
 * PHP tokenizer reports them as whole-comment tokens, never as string literals.
 *
 * The scan is positional on purpose. A shape census over every dotted string in
 * src/ was measured before this class was written: it surfaced 174
 * non-registry dotted literals that are config keys, filenames, codec profiles
 * and webhook event names — none of them an error code. Only expressions AT a
 * code-channel position carry the semantics the emit law cares about.
 *
 * @package Phlix
 */
final class ErrorCodeScan
{
    /**
     * Array-key positions that carry a wire code, mapped to their channel.
     *
     * @var array<string, string>
     */
    public const CODE_KEYS = ['code' => 'rest', 'error_code' => 'ws'];

    /**
     * Instance/static method names that receive a code as an argument.
     * Each entry: method => [channel, 0-based code argument index].
     *
     * `error` is ambiguous by name (PSR-3 loggers and controller-local helpers
     * share it), so instance `->error(` calls are only treated as emits when
     * argument 0 is a pure integer literal — the canonical
     * `Response::error(int $status, string $code, …)` shape — and `$this->error(`
     * is attributed to the enclosing class, not to Response.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    public const EMITTER_METHODS = [
        'sendError' => ['ws', 1],
        'jsonError' => ['rest', 1],
        'error' => ['rest', 1],
    ];

    /** The Response emitter's FQCN — `$this->error(` elsewhere is NOT this method. */
    public const RESPONSE_CLASS = 'Phlix\\Server\\Http\\Response';

    /**
     * Scan every PHP file under $root.
     *
     * @return list<array{file:string,line:int,channel:string,kind:string,value:string,snippet:string}>
     *         kind is one of: literal | const | const-unresolved | nonliteral
     *         (`const` sites carry the use-map-resolved `FQCN::CONST` in value).
     */
    public static function run(string $root): array
    {
        $sites = [];
        foreach (self::phpFiles($root) as $file) {
            $sites = array_merge($sites, self::scanFile($file, $root));
        }
        usort($sites, static fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
        return $sites;
    }

    /**
     * Every method in $root that declares a `string $code` parameter, an
     * integer-first parameter list, and a Response (or self) return type — the
     * definition side of the emitter law.
     *
     * @return list<string>
     */
    public static function emitterDefinitions(string $root): array
    {
        $found = [];
        foreach (self::phpFiles($root) as $file) {
            foreach (self::definitionsIn($file) as $fqn) {
                $found[$fqn] = true;
            }
        }
        $out = array_keys($found);
        sort($out);
        return $out;
    }

    /** @return list<string> */
    private static function definitionsIn(string $path): array
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $out = [];
        $namespace = '';
        $structure = '';
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $namespace = self::readQualifiedName($tokens, $i + 1);
            } elseif ($t[0] === T_CLASS && self::isStructureDeclaration($tokens, $i)) {
                $structure = self::readStructureName($tokens, $i);
            } elseif ($t[0] === T_FUNCTION) {
                $nameIdx = self::nextSig($tokens, $i + 1);
                if ($nameIdx === null || !is_array($tokens[$nameIdx]) || $tokens[$nameIdx][0] !== T_STRING) {
                    continue;
                }
                $open = self::nextSig($tokens, $nameIdx + 1);
                if ($open === null || self::text($tokens[$open]) !== '(') {
                    continue;
                }
                $close = self::matchClose($tokens, $open, '(', ')');
                if ($close === null) {
                    continue;
                }
                if (
                    self::paramsHaveStringCode($tokens, $open)
                    && self::firstParamIsInt($tokens, $open)
                    && self::returnIsResponse($tokens, $close)
                ) {
                    $out[] = ($namespace !== '' ? $namespace . '\\' : '') . $structure . '::' . $tokens[$nameIdx][1];
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getRealPath();
            }
        }
        sort($files);
        return $files;
    }

    /** @return list<array{file:string,line:int,channel:string,kind:string,value:string,snippet:string}> */
    private static function scanFile(string $path, string $root): array
    {
        $rel = ltrim(str_replace($root, '', $path), '/');
        $code = (string) file_get_contents($path);
        $tokens = token_get_all($code);
        $n = count($tokens);
        $sites = [];

        $namespace = '';
        $class = '';
        $useMap = self::parseUseMap($tokens);

        // structural pre-pass: last-seen namespace + enclosing class for `$this` attribution
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $namespace = self::readQualifiedName($tokens, $i + 1);
            } elseif ($t[0] === T_CLASS && self::isStructureDeclaration($tokens, $i)) {
                $class = self::readStructureName($tokens, $i);
            }
        }

        // Rule A: 'code' => / 'error_code' => array keys.
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $keyValue = self::unquote((string) $t[1]);
            if (!isset(self::CODE_KEYS[$keyValue])) {
                continue;
            }
            $j = self::nextSig($tokens, $i + 1);
            if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_DOUBLE_ARROW) {
                continue;
            }
            $exprStart = self::nextSig($tokens, $j + 1);
            if ($exprStart === null) {
                continue;
            }
            $expr = self::collectExpr($tokens, $exprStart);
            foreach (self::classify($expr, $useMap) as [$kind, $value]) {
                $sites[] = [
                    'file' => $rel,
                    'line' => (int) $t[2],
                    'channel' => self::CODE_KEYS[$keyValue],
                    'kind' => $kind,
                    'value' => $value,
                    'snippet' => self::snippet($expr),
                ];
            }
        }

        // Rule B: registered emitter call arguments. After the membership guard
        // below, $name is provably a key of EMITTER_METHODS, so the lookup at the
        // T_OBJECT_OPERATOR branch indexes without re-testing isset().
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING) {
                continue;
            }
            $name = (string) $t[1];
            if ($name !== 'error' && !isset(self::EMITTER_METHODS[$name])) {
                continue;
            }
            $open = self::nextSig($tokens, $i + 1);
            if ($open === null || self::text($tokens[$open]) !== '(') {
                continue;
            }
            $prev = self::prevSig($tokens, $i - 1);
            $prevTok = $prev === null ? null : $tokens[$prev];

            $channel = null;
            $argIndex = null;

            if ($name === 'error' && is_array($prevTok) && $prevTok[0] === T_DOUBLE_COLON) {
                // Messages::error(...) — the receiver token is `Messages` or an FQN
                // ending in `Messages`.
                $pp = self::prevSig($tokens, $prev - 1);
                $ppTok = $pp === null ? null : $tokens[$pp];
                if (is_array($ppTok) && in_array($ppTok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $segs = explode('\\', (string) $ppTok[1]);
                    if (end($segs) === 'Messages') {
                        $channel = 'ws';
                        $argIndex = 0;
                    }
                }
            } elseif (is_array($prevTok) && $prevTok[0] === T_OBJECT_OPERATOR) {
                [$channel, $argIndex] = self::EMITTER_METHODS[$name];
                if ($name === 'error') {
                    $argsProbe = self::splitArgs($tokens, $open);
                    if ($argsProbe === null || !isset($argsProbe[1]) || !self::argIsPureInt($argsProbe[0])) {
                        continue;
                    }
                    $recvIdx = self::prevSig($tokens, $prev - 1);
                    $recvTok = $recvIdx === null ? null : $tokens[$recvIdx];
                    if (is_array($recvTok) && $recvTok[0] === T_VARIABLE && (string) $recvTok[1] === '$this') {
                        $fqn = ($namespace !== '' ? $namespace . '\\' : '') . $class;
                        if ($fqn !== self::RESPONSE_CLASS) {
                            continue;
                        }
                    }
                }
            }

            if ($channel === null || $argIndex === null) {
                continue;
            }

            $args = self::splitArgs($tokens, $open);
            if ($args === null || !isset($args[$argIndex])) {
                continue;
            }
            foreach (self::classify($args[$argIndex], $useMap) as [$kind, $value]) {
                $sites[] = [
                    'file' => $rel,
                    'line' => (int) $t[2],
                    'channel' => $channel,
                    'kind' => $kind,
                    'value' => $value,
                    'snippet' => $name . '(' . self::snippet($args[$argIndex]) . ')',
                ];
            }
        }

        return $sites;
    }

    // ── token helpers ────────────────────────────────────────────────────

    /** @param array{0:int}|string|int $t */
    private static function isWsToken(mixed $t): bool
    {
        return is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ATTRIBUTE], true);
    }

    /** @param list<mixed> $tokens */
    private static function nextSig(array $tokens, int $from): ?int
    {
        $n = count($tokens);
        for ($i = max(0, $from); $i < $n; $i++) {
            if (!self::isWsToken($tokens[$i])) {
                return $i;
            }
        }
        return null;
    }

    /** @param list<mixed> $tokens */
    private static function prevSig(array $tokens, int $from): ?int
    {
        for ($i = min(count($tokens) - 1, $from); $i >= 0; $i--) {
            if (!self::isWsToken($tokens[$i])) {
                return $i;
            }
        }
        return null;
    }

    private static function text(mixed $t): string
    {
        return is_array($t) ? (string) $t[1] : (string) $t;
    }

    /** @param list<mixed> $tokens @return list<mixed> */
    private static function significant(array $tokens): array
    {
        return array_values(array_filter($tokens, static fn ($t): bool => !self::isWsToken($t)));
    }

    /** @param list<mixed> $tokens */
    private static function snippet(array $tokens): string
    {
        $s = '';
        foreach ($tokens as $t) {
            $s .= self::text($t);
        }
        $s = (string) preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /** @param list<mixed> $tokens @return list<mixed> */
    private static function collectExpr(array $tokens, int $start): array
    {
        $n = count($tokens);
        $depth = 0;
        $expr = [];
        for ($i = $start; $i < $n; $i++) {
            $text = self::text($tokens[$i]);
            if ($depth === 0 && ($text === ',' || $text === ';' || $text === ')' || $text === ']')) {
                break;
            }
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                if ($depth < 0) {
                    break;
                }
            }
            $expr[] = $tokens[$i];
        }
        return $expr;
    }

    /** @param list<mixed> $tokens @return list<list<mixed>>|null */
    private static function splitArgs(array $tokens, int $open): ?array
    {
        $n = count($tokens);
        $depth = 0;
        $args = [[]];
        for ($i = $open; $i < $n; $i++) {
            $text = self::text($tokens[$i]);
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                if ($depth === 0) {
                    return $args;
                }
            }
            if ($depth === 1 && $text === ',') {
                $args[] = [];
                continue;
            }
            $args[count($args) - 1][] = $tokens[$i];
        }
        return null;
    }

    /** @param list<mixed> $argTokens */
    private static function argIsPureInt(array $argTokens): bool
    {
        $sig = self::significant($argTokens);
        if ($sig !== [] && !is_array($sig[0]) && (string) $sig[0] === '-') {
            array_shift($sig);
        }
        return count($sig) === 1 && is_array($sig[0]) && $sig[0][0] === T_LNUMBER;
    }

    /** @param list<mixed> $tokens */
    private static function matchClose(array $tokens, int $open, string $o, string $c): ?int
    {
        $depth = 0;
        $n = count($tokens);
        for ($i = $open; $i < $n; $i++) {
            $text = self::text($tokens[$i]);
            if ($text === $o) {
                $depth++;
            } elseif ($text === $c) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /** @param list<mixed> $tokens */
    private static function readQualifiedName(array $tokens, int $from): string
    {
        $name = '';
        $n = count($tokens);
        for ($i = $from; $i < $n; $i++) {
            $t = $tokens[$i];
            $text = self::text($t);
            if ($text === ';' || $text === '{') {
                break;
            }
            if (!self::isWsToken($t)) {
                $name .= $text;
            }
        }
        return trim($name, '\\');
    }

    /** @param list<mixed> $tokens */
    private static function readStructureName(array $tokens, int $classIndex): string
    {
        $j = self::nextSig($tokens, $classIndex + 1);
        if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
            return (string) $tokens[$j][1];
        }
        return '';
    }

    /** @param list<mixed> $tokens */
    private static function isStructureDeclaration(array $tokens, int $i): bool
    {
        $prev = self::prevSig($tokens, $i - 1);
        if ($prev === null) {
            return true;
        }
        $pt = $tokens[$prev];
        if (!is_array($pt)) {
            return true;
        }
        return !in_array($pt[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NEW, T_INSTANCEOF], true);
    }

    /** @param list<mixed> $tokens */
    private static function paramsHaveStringCode(array $tokens, int $open): bool
    {
        $args = self::splitArgs($tokens, $open);
        if ($args === null) {
            return false;
        }
        foreach ($args as $arg) {
            $sig = self::significant($arg);
            $var = false;
            foreach ($sig as $s) {
                if (is_array($s) && $s[0] === T_VARIABLE && (string) $s[1] === '$code') {
                    $var = true;
                }
            }
            if (!$var) {
                continue;
            }
            foreach ($sig as $s) {
                if (is_array($s) && $s[0] === T_STRING && (string) $s[1] === 'string') {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param list<mixed> $tokens */
    private static function firstParamIsInt(array $tokens, int $open): bool
    {
        $args = self::splitArgs($tokens, $open);
        if ($args === null || $args === [[]]) {
            return false;
        }
        foreach (self::significant($args[0]) as $s) {
            if (is_array($s) && $s[0] === T_STRING && (string) $s[1] === 'int') {
                return true;
            }
        }
        return false;
    }

    /** @param list<mixed> $tokens */
    private static function returnIsResponse(array $tokens, int $closeParen): bool
    {
        $i = self::nextSig($tokens, $closeParen + 1);
        if ($i === null || self::text($tokens[$i]) !== ':') {
            return false;
        }
        $j = self::nextSig($tokens, $i + 1);
        if ($j === null || !is_array($tokens[$j])) {
            return false;
        }
        $t = $tokens[$j];
        if (in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $segs = explode('\\', (string) $t[1]);
            return end($segs) === 'Response';
        }
        // `self` is the declaring class — for the emit law that is the Response
        // (or another emitter) itself; the fluent house style returns self.
        return $t[0] === T_STRING && in_array((string) $t[1], ['Response', 'self'], true);
    }

    /**
     * Classify a code-channel expression.
     *
     * @param list<mixed> $expr
     * @param array<string, string> $useMap
     * @return list<array{0:string,1:string}> pairs kind|value; kind 'ignore' means
     *         the expression carries no checkable string (pure integer literal).
     */
    private static function classify(array $expr, array $useMap): array
    {
        $sig = self::significant($expr);
        if ($sig === []) {
            return [['ignore', '']];
        }

        $allInt = true;
        foreach ($sig as $t) {
            $isInt = is_array($t) && $t[0] === T_LNUMBER;
            if (!$isInt && self::text($t) !== '-') {
                $allInt = false;
                break;
            }
        }
        if ($allInt) {
            return [['ignore', '']];
        }

        if (count($sig) === 1 && is_array($sig[0]) && $sig[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            return [['literal', self::unquote((string) $sig[0][1])]];
        }

        $out = [];

        foreach ($sig as $k => $t) {
            if (!is_array($t) || $t[0] !== T_DOUBLE_COLON || !isset($sig[$k + 1])) {
                continue;
            }
            $nameTok = $sig[$k + 1];
            if (!is_array($nameTok) || $nameTok[0] !== T_STRING) {
                continue;
            }
            $constName = (string) $nameTok[1];
            if (strtolower($constName) === 'class') {
                continue;
            }
            $parts = [];
            for ($p = $k - 1; $p >= 0; $p--) {
                $pt = $sig[$p];
                if (is_array($pt) && in_array($pt[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    foreach (explode('\\', (string) $pt[1]) as $seg) {
                        if ($seg !== '') {
                            $parts[] = $seg;
                        }
                    }
                    break;
                }
                if (!is_array($pt) && $pt === '\\') {
                    continue;
                }
                if (is_array($pt) && $pt[0] === T_NS_SEPARATOR) {
                    continue;
                }
                break;
            }
            if ($parts === []) {
                continue;
            }
            $alias = strtolower($parts[0]);
            if (count($parts) === 1 && isset($useMap[$alias])) {
                $parts[0] = $useMap[$alias];
            } elseif (count($parts) > 1 && isset($useMap[$alias])) {
                $parts = array_merge(explode('\\', $useMap[$alias]), array_slice($parts, 1));
            }
            $fqn = implode('\\', $parts);
            try {
                $value = constant($fqn . '::' . $constName);
            } catch (\Error) {
                $out[] = ['const-unresolved', $fqn . '::' . $constName];
                continue;
            }
            if (is_string($value)) {
                $out[] = ['const', $value];
            } else {
                $out[] = ['const-unresolved', $fqn . '::' . $constName];
            }
        }

        foreach ($sig as $k => $t) {
            if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $prevText = $k > 0 ? self::text($sig[$k - 1]) : '';
            if ($prevText === '[' || $prevText === '(') {
                continue; // array subscript / call argument of a helper, not the field value
            }
            $out[] = ['literal', self::unquote((string) $t[1])];
        }

        if ($out === []) {
            $out[] = ['nonliteral', self::snippet($sig)];
        }
        return $out;
    }

    private static function unquote(string $raw): string
    {
        $q = $raw[0] ?? "'";
        $inner = substr($raw, 1, -1);
        if ($q === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }
        return $inner;
    }

    /**
     * Class imports of a token stream: lowercase alias => FQCN.
     *
     * @param list<mixed> $tokens
     * @return array<string, string>
     */
    private static function parseUseMap(array $tokens): array
    {
        $map = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_USE) {
                continue;
            }
            $chunk = [];
            for ($j = $i + 1; $j < $n; $j++) {
                $jt = $tokens[$j];
                $text = self::text($jt);
                if ($text === ';' || $text === '{') {
                    break;
                }
                $chunk[] = $jt;
            }
            $sig = self::significant($chunk);
            if ($sig === []) {
                continue;
            }
            $first = $sig[0];
            if (is_array($first) && in_array($first[0], [T_FUNCTION, T_CONST], true)) {
                continue;
            }
            if (!is_array($first) || !in_array($first[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue; // closure `use (` and friends
            }
            // Trait imports live in class bodies (`use SomeTrait;`) — they resolve
            // like class imports here, which is harmless for constant lookups.
            foreach (explode(',', self::snippet($sig)) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (preg_match('/^(.+?)\s+as\s+(\S+)$/i', $part, $mm) === 1) {
                    $map[strtolower($mm[2])] = ltrim(trim($mm[1]), '\\');
                } else {
                    $seg = explode('\\', ltrim($part, '\\'));
                    $map[strtolower((string) end($seg))] = ltrim($part, '\\');
                }
            }
        }
        return $map;
    }
}
