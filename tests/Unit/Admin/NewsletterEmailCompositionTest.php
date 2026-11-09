<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\NewsletterSender;
use ReflectionMethod;

/**
 * The newsletter wire-composition law (review SH-011 + SH-015).
 *
 * SH-011: `NewsletterSender::sendNewsletter()` interpolated the raw
 * `users.username` into the `To:` header (AuthManager::register enforces
 * only trim + length 3-50, no charset whitelist), so a username carrying
 * CRLF imploded extra header lines — email header injection (Bcc forgery,
 * spam amplification) straight into the mail() header block. The config
 * display name rode unquoted for the same class. The fix routes every
 * untrusted/interpolated string through RFC 5322 quoted-string assembly
 * with all control bytes removed, so no header slot can break out of its
 * own line — and the envelope recipient is never the header display value.
 *
 * SH-015: the generator built a `plain_text` copy that was declared
 * text/html and never reached a recipient. The compose seam now emits a
 * real `multipart/alternative` message: base64 text/plain part first,
 * base64 text/html part second (RFC 2046 §5.1.4 order: the least-capable
 * client reads the first part it can parse).
 *
 * The composition is exercised through the class's own private seam
 * (`composeEmail`) rather than mail(): a unit test must not reach the
 * MTA, and the pure function is the law's natural surface.
 */
class NewsletterEmailCompositionTest extends TestCase
{
    /**
     * @param array<string, mixed> $content
     * @return array{envelope_to: string, subject: string, body: string, headers: string}
     */
    private function compose(
        array $content,
        string $userEmail,
        string $userName,
        string $fromName,
        string $fromEmail,
        string $boundary
    ): array {
        $method = new ReflectionMethod(NewsletterSender::class, 'composeEmail');
        $method->setAccessible(true);

        /** @var array{envelope_to: string, subject: string, body: string, headers: string} $wire */
        $wire = $method->invoke(null, $content, $userEmail, $userName, $fromName, $fromEmail, $boundary);
        return $wire;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $subject = 'Your week in Phlix'): array
    {
        return [
            'subject' => $subject,
            'html_body' => '<h1>Hello</h1><p>6 items this week.</p>',
            'plain_text' => 'Hello - 6 items this week.',
        ];
    }

    public function testCrlfUsernameCannotInjectAHeaderLine(): void
    {
        $wire = $this->compose(
            $this->content(),
            'alice@example.com',
            "Alice\r\nBcc: spammer@evil.example",
            'Phlix Weekly',
            'newsletter@phlix.example',
            'phlix-boundary-1'
        );

        // The law is FRAMING, not content: control bytes are gone, so the
        // leftover text can only ride the quoted display name — it can never
        // start a header line, and the block keeps exactly its five slots.
        $this->assertStringNotContainsString("\r\nBcc", $wire['headers']);
        $this->assertSame(5, count(explode("\r\n", $wire['headers'])));
        $this->assertStringContainsString('To: "AliceBcc: spammer@evil.example"', $wire['headers']);
    }

    public function testEveryHeaderLineStaysFramedAfterControlByteInjection(): void
    {
        $wire = $this->compose(
            $this->content(),
            'alice@example.com',
            "Alice\nX-Evil: yes\rSubject: hijack",
            'Phlix Weekly',
            'newsletter@phlix.example',
            'phlix-boundary-1'
        );

        foreach (explode("\r\n", $wire['headers']) as $line) {
            $this->assertMatchesRegularExpression(
                '/^(MIME-Version|Content-Type|From|To|X-Mailer): /',
                $line,
                'header block may contain only the five composed slots, one per line'
            );
        }
        // Bare LF and bare CR are folds for MTA parsers too: the only
        // newlines allowed in the block are the \r\n between slots.
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $wire['headers']), 'no bare LF in the header block');
        $this->assertSame(0, preg_match('/\r(?!\n)/', $wire['headers']), 'no bare CR in the header block');
    }

    public function testDisplayNameRidesAnRfc5322QuotedStringSoSeparatorsStayCosmetic(): void
    {
        $wire = $this->compose(
            $this->content(),
            'alice@example.com',
            'Alice, bob@evil.example; carol',
            'Phlix Weekly',
            'newsletter@phlix.example',
            'phlix-boundary-1'
        );

        $this->assertStringContainsString('To: "Alice, bob@evil.example; carol"', $wire['headers']);
    }

    public function testQuoteAndBackslashInDisplayNameAreEscapedInsideTheQuotedString(): void
    {
        $wire = $this->compose(
            $this->content(),
            'alice@example.com',
            'Ali"ce \\ the "admin"',
            'Phlix \\ Weekly',
            'newsletter@phlix.example',
            'phlix-boundary-1'
        );

        $this->assertStringContainsString('To: "Ali\"ce \\\\ the \"admin\""', $wire['headers']);
        $this->assertStringContainsString('From: "Phlix \\\\ Weekly" <newsletter@phlix.example>', $wire['headers']);
    }

    public function testEnvelopeRecipientIsTheDbEmailNotAnyHeaderValue(): void
    {
        $wire = $this->compose(
            $this->content(),
            'alice@example.com',
            'attacker@evil.example',
            'Phlix Weekly',
            'newsletter@phlix.example',
            'phlix-boundary-1'
        );

        $this->assertSame('alice@example.com', $wire['envelope_to']);
    }

    public function testControlBytesAndAngleBracketsAreScrubbedFromTheFromAddressSlot(): void
    {
        $wire = $this->compose(
            $this->content(),
            'alice@example.com',
            'Alice',
            'Phlix Weekly',
            "news\r\nletter@phlix.example><x",
            'phlix-boundary-1'
        );

        // Removal is per-character: the address stays the config value minus
        // the bytes that could break <...> framing. `x` surviving after the
        // stripped angles is harmless residue — the law is that the slot
        // holds exactly one <...> pair and no line break.
        $this->assertStringContainsString('From: "Phlix Weekly" <newsletter@phlix.examplex>', $wire['headers']);
    }

    public function testSubjectCarriesNoControlBytes(): void
    {
        $wire = $this->compose(
            $this->content("Weekly\r\nBcc: spammer@evil.example report"),
            'alice@example.com',
            'Alice',
            'Phlix Weekly',
            'newsletter@phlix.example',
            'phlix-boundary-1'
        );

        $this->assertStringNotContainsString("\r", $wire['subject']);
        $this->assertStringNotContainsString("\n", $wire['subject']);
        $this->assertSame('WeeklyBcc: spammer@evil.example report', $wire['subject']);
    }

    public function testMessageIsMultipartAlternativeDeclaringTheBoundary(): void
    {
        $wire = $this->compose(
            $this->content(),
            'alice@example.com',
            'Alice',
            'Phlix Weekly',
            'newsletter@phlix.example',
            'phlix-boundary-42'
        );

        $this->assertStringContainsString(
            'Content-Type: multipart/alternative; boundary="phlix-boundary-42"',
            $wire['headers']
        );
        $this->assertStringContainsString('MIME-Version: 1.0', $wire['headers']);
        $this->assertStringNotContainsString('Content-Type: text/html', $wire['headers']);
    }

    public function testPlainTextCopyActuallyReachesTheWire(): void
    {
        $content = $this->content();
        $wire = $this->compose(
            $content,
            'alice@example.com',
            'Alice',
            'Phlix Weekly',
            'newsletter@phlix.example',
            'phlix-boundary-1'
        );

        $body = $wire['body'];
        $this->assertStringStartsWith('--phlix-boundary-1', $body);
        $this->assertStringContainsString(
            "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n",
            $body
        );
        $this->assertStringContainsString(
            "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n",
            $body
        );
        $this->assertStringContainsString('--phlix-boundary-1--', $body);

        // Part 1 of the multipart (the plain section) must carry the exact
        // plain_text payload after base64 decoding, proving the copy the
        // generator built is no longer discarded before the wire.
        $parts = explode('--phlix-boundary-1', $body);
        $plainSection = $parts[1];
        $headerSep = strpos($plainSection, "\r\n\r\n");
        $this->assertIsInt($headerSep);
        $encoded = trim(substr($plainSection, $headerSep + 4));
        $this->assertSame($content['plain_text'], base64_decode($encoded));
        $this->assertStringNotContainsString('<h1>', base64_decode($encoded));
    }

    public function testPlainPartPrecedesHtmlPartForLeastCapableClient(): void
    {
        $wire = $this->compose(
            $this->content(),
            'alice@example.com',
            'Alice',
            'Phlix Weekly',
            'newsletter@phlix.example',
            'phlix-boundary-1'
        );

        $plainAt = strpos($wire['body'], 'text/plain');
        $htmlAt = strpos($wire['body'], 'text/html');
        $this->assertIsInt($plainAt);
        $this->assertIsInt($htmlAt);
        $this->assertLessThan($htmlAt, $plainAt);
    }

    public function testTheGeneratedBoundaryIsTokenSafePerRfc2045(): void
    {
        $method = new ReflectionMethod(NewsletterSender::class, 'generateMimeBoundary');
        $method->setAccessible(true);

        $first = (string) $method->invoke(null);
        $second = (string) $method->invoke(null);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\'_\-+.]{1,70}$/', $first);
        $this->assertNotSame($first, $second);
    }

    public function testSendNewsletterPathIsTheOnlyConsumerLeftAssemblingNoHeadersInline(): void
    {
        $source = (string) file_get_contents((string) (new \ReflectionClass(NewsletterSender::class))->getFileName());
        $this->assertMatchesRegularExpression(
            '/mail\(\s*\$wire\[/',
            $source,
            'sendNewsletter() must hand mail() exactly the composed wire set — '
            . 'inline header assembly is how the SH-011 injection rode'
        );
    }
}
