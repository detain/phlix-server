<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\PluginFieldHelp;

/**
 * Link coverage and dead-link regression guards for the plugin help overlay
 * (`config/plugin_field_help.php`).
 *
 * ## What these assert, and why in this shape
 *
 * Per plan §4 rule 9 the assertions here check CONSEQUENCES — that a link
 * actually survives {@see PluginFieldHelp::decorate()} into the descriptor the
 * SPA renders — rather than merely that the config file contains a URL string.
 * A field can carry a perfectly good `link` in the config array and still show
 * nothing if `OVERLAY_KEYS` does not whitelist it.
 *
 * The dead-link guard is a **denylist of specific URLs already proven dead**,
 * deliberately not a general liveness check. Network liveness is covered
 * separately by the `@group network` test below, which CI can skip; this one
 * must run everywhere, because the failure it guards is "somebody pastes the
 * old URL back", which needs no network to detect.
 */
class PluginFieldHelpLinkCoverageTest extends TestCase
{
    /**
     * URLs proven dead, with the evidence. Reintroducing any of these fails.
     *
     * @var array<string, string>
     */
    private const DEAD_URLS = [
        'https://trakt.tv/oauth/applications' =>
            '404 — all trakt.tv/* paths redirect to app.trakt.tv/*. '
            . 'Use https://app.trakt.tv/settings/apps/api/new',
        'https://trakt.tv/apps' =>
            '404 — same redirect. Use https://app.trakt.tv/settings/apps/api/new',
        'https://trakt.docs.apiary.io' =>
            'No response at all; the live docs are https://docs.trakt.tv/',
        // These four were proposed by the investigation notes and verified
        // against the MediaWiki API: the first three are MISSING articles, the
        // fourth is a redirect that lands on the general MusicBrainz page.
        'https://en.wikipedia.org/wiki/AniDB' =>
            'MISSING Wikipedia article. Use https://anidb.net/',
        'https://en.wikipedia.org/wiki/AniList' =>
            'MISSING Wikipedia article. Use https://anilist.co/',
        'https://en.wikipedia.org/wiki/Trakt' =>
            'MISSING Wikipedia article. Use https://trakt.tv/',
        'https://en.wikipedia.org/wiki/Cover_Art_Archive' =>
            'Redirects to the general MusicBrainz article. Use https://coverartarchive.org/',
    ];

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    private static function overlay(): array
    {
        /** @var array<string, array<string, array<string, string>>> $overlay */
        $overlay = require __DIR__ . '/../../../config/plugin_field_help.php';

        return $overlay;
    }

    /**
     * CONSEQUENCE: every overlay field must reach the SPA carrying a link.
     *
     * Runs each field through the real `decorate()` — the same call the admin
     * plugin endpoint makes — and asserts the decorated descriptor has a non-empty
     * `link` AND `link_text`. A link present in the config file but not whitelisted
     * in `OVERLAY_KEYS` would pass a naive config-file assertion and fail this one.
     *
     * Mutation-verified: deleting `link` from any single field fails this test, and
     * removing `'link'` from `PluginFieldHelp::OVERLAY_KEYS` fails it for all of them.
     */
    public function test_every_overlay_field_delivers_a_link_through_decorate(): void
    {
        $missing = [];

        foreach (self::overlay() as $plugin => $fields) {
            // Feed decorate() a schema shaped like SettingsMasker::schema() output.
            $schema = [];
            foreach (array_keys($fields) as $key) {
                $schema[$key] = ['type' => 'string', 'required' => false, 'secret' => false];
            }

            $decorated = PluginFieldHelp::decorate($plugin, $schema);

            foreach (array_keys($fields) as $key) {
                $link = $decorated[$key]['link'] ?? null;
                $text = $decorated[$key]['link_text'] ?? null;
                if (!is_string($link) || $link === '' || !is_string($text) || $text === '') {
                    $missing[] = "{$plugin}.{$key}";
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Every plugin help field must carry a link + link_text through decorate(). Missing:\n  "
            . implode("\n  ", $missing)
        );
    }

    /**
     * CONSEQUENCE: a known-dead URL must never reappear in the shipped overlay.
     *
     * Checks the decorated output, not the raw file, so a dead URL cannot sneak in
     * through a code path that bypasses the config array.
     */
    public function test_no_known_dead_url_is_served(): void
    {
        foreach (self::overlay() as $plugin => $fields) {
            $schema = [];
            foreach (array_keys($fields) as $key) {
                $schema[$key] = ['type' => 'string', 'required' => false, 'secret' => false];
            }
            $decorated = PluginFieldHelp::decorate($plugin, $schema);

            foreach ($decorated as $key => $descriptor) {
                $link = $descriptor['link'] ?? null;
                if (!is_string($link)) {
                    continue;
                }
                foreach (self::DEAD_URLS as $dead => $why) {
                    $this->assertStringNotContainsString(
                        $dead,
                        $link,
                        sprintf('%s.%s serves a known-dead link (%s). %s', $plugin, $key, $dead, $why)
                    );
                }
            }
        }
    }

    /**
     * Guard the file-level source too, so a dead URL cannot hide in a description
     * string or a plugin's own manifest default rather than the `link` field.
     */
    public function test_no_known_dead_url_appears_in_any_link_value(): void
    {
        foreach (self::overlay() as $plugin => $fields) {
            foreach ($fields as $key => $field) {
                foreach (['link', 'description', 'label'] as $slot) {
                    $value = $field[$slot] ?? null;
                    if (!is_string($value)) {
                        continue;
                    }
                    foreach (array_keys(self::DEAD_URLS) as $dead) {
                        $this->assertStringNotContainsString(
                            $dead,
                            $value,
                            sprintf('%s.%s[%s] contains dead URL %s', $plugin, $key, $slot, $dead)
                        );
                    }
                }
            }
        }
    }

    /**
     * Every link must be https. A plaintext help link is both a downgrade vector
     * and inconsistent with the rest of the overlay.
     */
    public function test_every_link_is_https(): void
    {
        foreach (self::overlay() as $plugin => $fields) {
            foreach ($fields as $key => $field) {
                $link = $field['link'] ?? null;
                if (!is_string($link) || $link === '') {
                    continue;
                }
                $this->assertStringStartsWith(
                    'https://',
                    $link,
                    sprintf('%s.%s link must be https', $plugin, $key)
                );
            }
        }
    }

    /**
     * Network liveness. Skipped unless explicitly requested, mirroring the
     * `@group network` schema-link probe in phlix-shared.
     *
     * A 403 is NOT treated as dead: anidb.net and opensubtitles.com both sit
     * behind a Cloudflare bot challenge that returns 403 to non-browser clients.
     *
     * @group network
     */
    public function test_every_link_resolves(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/120.0.0.0 Safari/537.36';
        $dead = [];

        foreach (self::overlay() as $plugin => $fields) {
            foreach ($fields as $key => $field) {
                $link = $field['link'] ?? null;
                if (!is_string($link) || $link === '') {
                    continue;
                }

                $ch = curl_init($link);
                if ($ch === false) {
                    continue;
                }
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY         => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 20,
                    CURLOPT_USERAGENT      => $ua,
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);

                // 403 == bot challenge, not a dead link. 0 == transport failure.
                if ($status !== 0 && $status < 400) {
                    continue;
                }
                if ($status === 403) {
                    continue;
                }
                $dead[] = sprintf('%s.%s -> %s (HTTP %d)', $plugin, $key, $link, $status);
            }
        }

        $this->assertSame([], $dead, "Dead help links:\n  " . implode("\n  ", $dead));
    }
}
