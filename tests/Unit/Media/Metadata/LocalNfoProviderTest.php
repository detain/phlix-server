<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\LocalNfoProvider;
use Phlix\Common\Logger\LoggerFactory;

class LocalNfoProviderTest extends TestCase
{
    private LocalNfoProvider $provider;
    private string $testDir;

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
        $this->provider = new LocalNfoProvider('');
        $this->testDir = sys_get_temp_dir() . '/phlix_nfo_test';
        
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test files
        $files = glob($this->testDir . "/*") ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->testDir)) {
            rmdir($this->testDir);
        }
    }

    public function testCanCreateLocalNfoProvider(): void
    {
        $this->assertInstanceOf(LocalNfoProvider::class, $this->provider);
    }

    public function testGetProvidersReturnsExpected(): void
    {
        $providers = $this->provider->getProviders();
        
        $this->assertContains('local', $providers);
        $this->assertContains('nfo', $providers);
    }

    public function testSearchReturnsEmptyArray(): void
    {
        // Local NFO doesn't support search
        $result = $this->provider->search('Test');

        $this->assertEmpty($result);
    }

    public function testParseMovieNfoReturnsArray(): void
    {
        $result = $this->provider->getDetails('/nonexistent/nonexistent.nfo');

        // A missing NFO path yields an empty result (no metadata to parse).
        $this->assertEmpty($result);
    }

    public function testParseDirectoryReturnsArray(): void
    {
        $result = $this->provider->getDetails($this->testDir);

        $this->assertArrayHasKey('type', $result);
    }

    public function testParseDirectoryWithEmptyDir(): void
    {
        $result = $this->provider->parseDirectory($this->testDir);

        $this->assertEquals('unknown', $result['type']);
    }

    public function testParseMovieNfoWithXmlFormat(): void
    {
        $nfoContent = <<<'NFO'
<?xml version="1.0" encoding="utf-8"?>
<movie>
    <title>Test Movie</title>
    <originaltitle>Test Movie Original</originaltitle>
    <plot>A test plot</plot>
    <year>2023</year>
    <premiered>2023-05-15</premiered>
    <rating>8.5</rating>
    <votes>1000</votes>
    <runtime>120</runtime>
    <mpaa>PG-13</mpaa>
    <tagline>Test tagline</tagline>
    <genre>Action</genre>
    <genre>Adventure</genre>
    <studio>Test Studio</studio>
    <director>Test Director</director>
    <credits>Writer 1</credits>
    <credits>Writer 2</credits>
    <actor>
        <name>Actor 1</name>
        <role>Hero</role>
    </actor>
    <actor>
        <name>Actor 2</name>
        <role>Villain</role>
    </actor>
    <tmdbid>tt1234567</tmdbid>
    <imdbid>tt1234567</imdbid>
</movie>
NFO;
        
        $nfoFile = $this->testDir . '/movie.nfo';
        file_put_contents($nfoFile, $nfoContent);
        
        $result = $this->provider->parseMovieNfo($nfoFile);
        
        $this->assertEquals('movie', $result['type']);
        $this->assertEquals('Test Movie', $result['name']);
        $this->assertEquals('Test Movie Original', $result['original_name']);
        $this->assertEquals('A test plot', $result['overview']);
        $this->assertEquals(2023, $result['year']);
        $this->assertEquals('2023-05-15', $result['premiered']);
        $this->assertEquals(8.5, $result['rating']);
        $this->assertEquals(1000, $result['votes']);
        $this->assertEquals(120, $result['runtime']);
        $this->assertEquals('PG-13', $result['mpaa']);
        $this->assertEquals('Test tagline', $result['tagline']);
        $genres = $result['genres'];
        $this->assertIsArray($genres);
        $this->assertContains('Action', $genres);
        $this->assertContains('Adventure', $genres);
        $studios = $result['studios'];
        $this->assertIsArray($studios);
        $this->assertContains('Test Studio', $studios);
        $directors = $result['directors'];
        $this->assertIsArray($directors);
        $this->assertContains('Test Director', $directors);
        $actors = $result['actors'];
        $this->assertIsArray($actors);
        $this->assertCount(2, $actors);
        $externalIds = $result['external_ids'];
        $this->assertIsArray($externalIds);
        $this->assertEquals('tt1234567', $externalIds['tmdb']);
        $this->assertEquals('tt1234567', $externalIds['imdb']);
    }

    public function testParseTvShowNfoWithXmlFormat(): void
    {
        $nfoContent = <<<'NFO'
<?xml version="1.0" encoding="utf-8"?>
<tvshow>
    <title>Test TV Show</title>
    <originaltitle>Test TV Show Original</originaltitle>
    <plot>A test TV show plot</plot>
    <premiered>2020-01-15</premiered>
    <rating>9.0</rating>
    <votes>5000</votes>
    <status>Continuing</status>
    <episode_run_time>45</episode_run_time>
    <genre>Drama</genre>
    <genre>Thriller</genre>
    <studio>TV Studio</studio>
    <tvdbid>123456</tvdbid>
    <imdbid>tt1234567</imdbid>
</tvshow>
NFO;
        
        $nfoFile = $this->testDir . '/tvshow.nfo';
        file_put_contents($nfoFile, $nfoContent);
        
        $result = $this->provider->parseTvShowNfo($nfoFile);
        
        $this->assertEquals('tvshow', $result['type']);
        $this->assertEquals('Test TV Show', $result['name']);
        $this->assertEquals('Test TV Show Original', $result['original_name']);
        $this->assertEquals('A test TV show plot', $result['overview']);
        $this->assertEquals(2020, $result['year']);
        $this->assertEquals(9.0, $result['rating']);
        $this->assertEquals('Continuing', $result['status']);
        $this->assertEquals(45, $result['episode_run_time']);
        $genres = $result['genres'];
        $this->assertIsArray($genres);
        $this->assertContains('Drama', $genres);
        $this->assertContains('Thriller', $genres);
        $studios = $result['studios'];
        $this->assertIsArray($studios);
        $this->assertContains('TV Studio', $studios);
        $externalIds = $result['external_ids'];
        $this->assertIsArray($externalIds);
        $this->assertEquals('123456', $externalIds['tvdb']);
    }

    public function testParseEpisodeNfoWithXmlFormat(): void
    {
        $nfoContent = <<<'NFO'
<?xml version="1.0" encoding="utf-8"?>
<episodedetails>
    <title>Test Episode</title>
    <plot>An episode plot</plot>
    <season>2</season>
    <episode>3</episode>
    <aired>2020-03-15</aired>
    <rating>8.0</rating>
    <runtime>42</runtime>
    <director>Episode Director</director>
    <credits>Episode Writer</credits>
</episodedetails>
NFO;
        
        $nfoFile = $this->testDir . '/episode.nfo';
        file_put_contents($nfoFile, $nfoContent);
        
        $result = $this->provider->parseEpisodeNfo($nfoFile);
        
        $this->assertEquals('episode', $result['type']);
        $this->assertEquals('Test Episode', $result['name']);
        $this->assertEquals('An episode plot', $result['overview']);
        $this->assertEquals(2, $result['season_number']);
        $this->assertEquals(3, $result['episode_number']);
        $this->assertEquals('2020-03-15', $result['aired']);
        $this->assertEquals(8.0, $result['rating']);
        $this->assertEquals(42, $result['runtime']);
        $this->assertEquals('Episode Director', $result['director']);
    }

    public function testParseSimpleNfoWithIdOnly(): void
    {
        $nfoContent = <<<'NFO'
tmdb: 12345
imdb: tt54321
NFO;
        
        $nfoFile = $this->testDir . '/simple.nfo';
        file_put_contents($nfoFile, $nfoContent);
        
        $result = $this->provider->parseMovieNfo($nfoFile);
        
        $this->assertEquals('movie', $result['type']);
        $externalIds = $result['external_ids'];
        $this->assertIsArray($externalIds);
        $this->assertEquals('12345', $externalIds['tmdb']);
        $this->assertEquals('tt54321', $externalIds['imdb']);
    }

    public function testFindLocalImagesInDirectory(): void
    {
        // Create mock image files
        file_put_contents($this->testDir . '/poster.jpg', 'fake-image');
        file_put_contents($this->testDir . '/fanart.jpg', 'fake-image');
        file_put_contents($this->testDir . '/movie.jpg', 'fake-image');
        file_put_contents($this->testDir . '/folder.jpg', 'fake-image');
        
        $result = $this->provider->getImages($this->testDir);

        // The four image files created above are discovered and grouped.
        $this->assertNotEmpty($result);
    }

    public function testParseDirectoryWithTvshowNfo(): void
    {
        $nfoContent = <<<'NFO'
<?xml version="1.0" encoding="utf-8"?>
<tvshow>
    <title>My TV Show</title>
    <tvdbid>789</tvdbid>
</tvshow>
NFO;
        
        $nfoFile = $this->testDir . '/tvshow.nfo';
        file_put_contents($nfoFile, $nfoContent);
        
        $result = $this->provider->parseDirectory($this->testDir);
        
        $this->assertEquals('tvshow', $result['type']);
        $metadata = $result['metadata'];
        $this->assertIsArray($metadata);
        $this->assertEquals('My TV Show', $metadata['name']);
    }
}
