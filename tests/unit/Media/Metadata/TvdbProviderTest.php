<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\TvdbProvider;
use Phlix\Common\Logger\LoggerFactory;

class TvdbProviderTest extends TestCase
{
    private TvdbProvider $provider;

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
        // Use test API key - in real tests this would be mocked
        $this->provider = new TvdbProvider('test-api-key', 'eng');
    }

    public function testCanCreateTvdbProvider(): void
    {
        $this->assertInstanceOf(TvdbProvider::class, $this->provider);
    }

    public function testGetProvidersReturnsExpected(): void
    {
        $providers = $this->provider->getProviders();
        
        $this->assertContains('tvdb', $providers);
        $this->assertContains('thetvdb', $providers);
    }

    public function testSearchReturnsArray(): void
    {
        // This will return empty since we're using a test API key
        // but we can verify the method structure
        $result = $this->provider->search('Test Show');
        
        $this->assertIsArray($result);
    }

    public function testGetDetailsReturnsArray(): void
    {
        $result = $this->provider->getDetails('12345');
        
        $this->assertIsArray($result);
    }

    public function testGetImagesReturnsArray(): void
    {
        $result = $this->provider->getImages('12345');
        
        $this->assertIsArray($result);
    }

    public function testGetEpisodeReturnsArray(): void
    {
        $result = $this->provider->getEpisode('12345', 1, 1);
        
        $this->assertIsArray($result);
    }

    public function testGetSeasonEpisodesReturnsArray(): void
    {
        $result = $this->provider->getSeasonEpisodes('12345', 1);
        
        $this->assertIsArray($result);
    }

    public function testSearchWithOptions(): void
    {
        $result = $this->provider->search('Test', ['language' => 'eng']);
        
        $this->assertIsArray($result);
    }
}
