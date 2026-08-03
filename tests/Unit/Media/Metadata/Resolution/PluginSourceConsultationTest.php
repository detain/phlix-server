<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Resolution;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\Resolution\PluginSourceConsultation;
use Phlix\Media\Metadata\Resolution\SourceRegistry;
use PHPUnit\Framework\TestCase;

final class PluginSourceConsultationTest extends TestCase
{
    private function logger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    public function testConsultsPriorityListedSourcesBeforeUnlistedOnes(): void
    {
        $registry = new SourceRegistry();
        // Registration order: unlisted first, priority-listed second — so a naive
        // registration-order walk would put 'zeta' before 'alpha'. The consult
        // order must instead put the priority-listed 'alpha' FIRST.
        $registry->register(
            new FakeMetadataSource('zeta', ['movie'], [['id' => 'z1', 'title' => 'Z']], ['title' => 'Z'])
        );
        $registry->register(
            new FakeMetadataSource('alpha', ['movie'], [['id' => 'a1', 'title' => 'A']], ['title' => 'A'])
        );

        $out = (new PluginSourceConsultation($registry, $this->logger()))
            ->consult('movie', 'whatever', null, ['tmdb', 'imdb', 'alpha']);

        // 'alpha' is named in the priority order → consulted first; 'zeta' (not in
        // the order) follows. tmdb/imdb are not plugin sources → ignored.
        $this->assertSame(['alpha', 'zeta'], $out['sources']);
    }

    public function testThrowingSourceIsSkippedAndOthersStillContribute(): void
    {
        $registry = new SourceRegistry();
        $registry->register(new FakeMetadataSource('bad', ['movie'], [], [], throwOnSearch: true));
        $registry->register(
            new FakeMetadataSource('good', ['movie'], [['id' => 'g1', 'title' => 'G']], ['title' => 'G'])
        );

        $out = (new PluginSourceConsultation($registry, $this->logger()))
            ->consult('movie', 'q', null, ['bad', 'good']);

        // The throwing source contributes nothing; the good one still does.
        $this->assertSame(['good'], $out['sources']);
        $this->assertCount(1, $out['records']);
        $this->assertSame('good', $out['records'][0]->source);
    }

    public function testExtractsOmdbStyleRatings(): void
    {
        $registry = new SourceRegistry();
        $registry->register(new FakeMetadataSource(
            'omdb',
            ['movie'],
            [['id' => 'tt1', 'title' => 'Film']],
            [
                'title' => 'Film',
                'ratings' => [
                    ['source' => 'imdb', 'score' => 8.1, 'votes' => 1234],
                    ['source' => 'rt', 'score' => 7.5],
                    ['source' => '', 'score' => 9.9],      // dropped: blank source
                    ['source' => 'x', 'score' => 'not-a-number'], // dropped: bad score
                ],
            ],
        ));

        $out = (new PluginSourceConsultation($registry, $this->logger()))
            ->consult('movie', 'Film', null, ['omdb']);

        $this->assertSame([
            ['source' => 'imdb', 'score' => 8.1, 'votes' => 1234],
            ['source' => 'rt', 'score' => 7.5],
        ], $out['ratings']);
    }

    public function testSourceWithNoHitOrEmptyDetailsContributesNothing(): void
    {
        $registry = new SourceRegistry();
        $registry->register(new FakeMetadataSource('nohit', ['movie'], [], ['title' => 'X']));
        $registry->register(new FakeMetadataSource('nodetails', ['movie'], [['id' => 'n1', 'title' => 'N']], []));

        $out = (new PluginSourceConsultation($registry, $this->logger()))
            ->consult('movie', 'q', null, []);

        $this->assertSame([], $out['sources']);
        $this->assertSame([], $out['records']);
    }
}
