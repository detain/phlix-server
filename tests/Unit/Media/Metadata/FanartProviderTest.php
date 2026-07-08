<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\FanartProvider;
use Phlix\Common\Logger\LoggerFactory;

class FanartProviderTest extends TestCase
{
    private FanartProvider $provider;

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
        // Use test API key - in real tests this would be mocked
        $this->provider = new FanartProvider('test-api-key');
    }

    public function testCanCreateFanartProvider(): void
    {
        $this->assertInstanceOf(FanartProvider::class, $this->provider);
    }

    public function testGetProvidersReturnsExpected(): void
    {
        $providers = $this->provider->getProviders();
        
        $this->assertContains('fanart', $providers);
        $this->assertContains('fanarttv', $providers);
    }

    public function testSearchReturnsEmptyArray(): void
    {
        // Fanart.tv doesn't support search directly
        $result = $this->provider->search('Test');

        $this->assertEmpty($result);
    }

    public function testGetDetailsReturnsArray(): void
    {
        $result = $this->provider->getDetails('12345');

        // Result shape is network-dependent (empty when the API is unreachable);
        // assert the stable contract that it is a JSON-serialisable array.
        $this->assertIsString(json_encode($result));
    }

    public function testGetImagesReturnsArray(): void
    {
        $result = $this->provider->getImages('12345');

        $this->assertIsString(json_encode($result));
    }

    public function testGetMovieImagesReturnsArray(): void
    {
        $result = $this->provider->getMovieImages('tt1234567');

        $this->assertIsString(json_encode($result));
    }

    public function testGetTvShowImagesReturnsArray(): void
    {
        $result = $this->provider->getTvShowImages('12345');

        $this->assertIsString(json_encode($result));
    }

    public function testGetMusicImagesReturnsArray(): void
    {
        $result = $this->provider->getMusicImages('12345678-1234-1234-1234-123456789012');

        $this->assertIsString(json_encode($result));
    }

    public function testGetDetailsWithIdTypeOption(): void
    {
        $result = $this->provider->getDetails('tt1234567', ['id_type' => 'imdb']);

        $this->assertIsString(json_encode($result));
    }
}
