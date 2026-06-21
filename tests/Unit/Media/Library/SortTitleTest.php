<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Media\Library\SortTitle;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Media\Library\SortTitle
 */
final class SortTitleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titleProvider(): iterable
    {
        // English articles.
        yield 'strips The'                 => ['The Plot', 'Plot'];
        yield 'strips lowercase the'       => ['the matrix', 'matrix'];
        yield 'strips UPPERCASE THE'       => ['THE THING', 'THING'];
        yield 'strips A'                   => ['A Quiet Place', 'Quiet Place'];
        yield 'strips An'                  => ['An American Tail', 'American Tail'];

        // Non-article words that merely START with an article's letters.
        yield 'keeps Theory'               => ['Theory', 'Theory'];
        yield 'keeps Theodore'             => ['Theodore', 'Theodore'];
        yield 'keeps Antman (no space)'    => ['Antman', 'Antman'];
        yield 'keeps Andes'                => ['Andes', 'Andes'];
        yield 'keeps A.I. (dot not space)' => ['A.I.', 'A.I.'];
        yield 'keeps Death Race'           => ['Death Race', 'Death Race'];

        // Romance + German articles.
        yield 'strips El'                  => ['El Camino', 'Camino'];
        yield 'strips La'                  => ['La La Land', 'La Land']; // only the first article goes
        yield 'strips Le'                  => ['Le Mans', 'Mans'];
        yield 'strips Les'                 => ['Les Misérables', 'Misérables'];
        yield 'strips Los'                 => ['Los Angeles', 'Angeles'];
        yield 'strips Las'                 => ['Las Vegas', 'Vegas'];
        yield 'strips Die'                 => ['Die Hard', 'Hard'];
        yield 'strips Der'                 => ['Der Untergang', 'Untergang'];
        yield 'strips Das'                 => ['Das Boot', 'Boot'];

        // Whitespace + degenerate inputs.
        yield 'collapses multi-space'      => ['The  Double', 'Double'];
        yield 'leading space not stripped' => ['  The Spaced', 'The Spaced'];
        yield 'bare The kept'              => ['The', 'The'];
        yield 'The + space empties'        => ['The ', ''];
        yield 'bare A kept'                => ['A', 'A'];
        yield 'empty stays empty'          => ['', ''];

        // Accent-sensitive: "Thé " is NOT the article "the " (mirrors the
        // COLLATE utf8mb4_bin SQL, which is case-insensitive but accent-sensitive).
        yield 'keeps accented The'         => ['Thé Café', 'Thé Café'];
    }

    /**
     * @dataProvider titleProvider
     */
    public function testFromComputesSortKey(string $name, string $expected): void
    {
        $this->assertSame($expected, SortTitle::from($name));
    }

    public function testSqlExpressionCoversEveryArticleCaseInsensitivelyAndPortably(): void
    {
        $sql = SortTitle::sqlExpression('name');

        $this->assertStringStartsWith('TRIM(CASE ', $sql);
        $this->assertStringEndsWith(' ELSE name END)', $sql);
        // Case-insensitive (LOWER) + accent-sensitive (utf8mb4_bin), no REGEXP_REPLACE
        // (whose case-insensitive form is not portable between MySQL and MariaDB).
        $this->assertStringContainsString('LOWER(LEFT(name,', $sql);
        $this->assertStringContainsString('COLLATE utf8mb4_bin', $sql);
        $this->assertStringNotContainsString('REGEXP', $sql);

        // One WHEN branch per article, keyed on the lowercase "<article> " prefix.
        foreach (SortTitle::ARTICLES as $article) {
            $this->assertStringContainsString("= '{$article} '", $sql);
        }
        $this->assertSame(count(SortTitle::ARTICLES), substr_count($sql, 'WHEN '));
    }

    public function testSqlExpressionHonorsColumnArgument(): void
    {
        $sql = SortTitle::sqlExpression('m.name');
        $this->assertStringContainsString('LEFT(m.name,', $sql);
        $this->assertStringContainsString('ELSE m.name END', $sql);
    }

    public function testLetterSqlExpressionWrapsSortKeyInUpperLeft(): void
    {
        $sql = SortTitle::letterSqlExpression('name');
        $this->assertStringStartsWith('UPPER(LEFT(TRIM(CASE ', $sql);
        $this->assertStringEndsWith(', 1))', $sql);
    }
}
