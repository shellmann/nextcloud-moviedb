<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\Config\IUserConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;

/**
 * The single TMDB call behind the trailer link and "where to watch".
 */
class TmdbServiceExtrasTest extends TestCase {
    private IClient $client;
    private IClientService $clientService;
    private IUserConfig $userConfig;
    private IAppConfig $appConfig;
    private ?TmdbService $service = null;

    protected function setUp(): void {
        parent::setUp();
        $this->client = $this->createStub(IClient::class);
        $this->clientService = $this->createStub(IClientService::class);
        $this->clientService->method('newClient')->willReturnCallback(fn () => $this->client);
        $this->userConfig = $this->createStub(IUserConfig::class);
        $this->userConfig->method('getValueString')->willReturn('user-key');
        $this->appConfig = $this->createStub(IAppConfig::class);
    }

    private function service(): TmdbService {
        return $this->service ??= new TmdbService($this->clientService, $this->userConfig, $this->appConfig);
    }

    private function respond(array $body): IResponse {
        $response = $this->createStub(IResponse::class);
        $response->method('getBody')->willReturn(json_encode($body));
        return $response;
    }

    public function testAsksForVideosAndProvidersInOneCall(): void {
        $this->client = $this->createMock(IClient::class);
        $this->client->expects($this->once())->method('get')
            ->with('https://api.themoviedb.org/3/tv/42', $this->callback(
                fn (array $o) => $o['headers']['Authorization'] === 'Bearer user-key'
                    && $o['query']['append_to_response'] === 'videos,watch/providers'
                    && $o['query']['include_video_language'] === 'de,en,null'
                    && $o['query']['language'] === 'de-DE'
            ))
            ->willReturn($this->respond([]));

        $this->service()->getExtras('tv', 42, 'alice', 'de-DE', 'DE');
    }

    public function testMapsTrailerAndProviders(): void {
        $this->client->method('get')->willReturn($this->respond([
            'videos' => ['results' => [['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'abc', 'name' => 'T', 'iso_639_1' => 'de']]],
            'watch/providers' => ['results' => ['DE' => [
                'link' => 'https://www.themoviedb.org/tv/42/watch?locale=DE',
                'flatrate' => [['provider_id' => 8, 'provider_name' => 'Netflix', 'logo_path' => '/n.jpg']],
            ]]],
        ]));

        $extras = $this->service()->getExtras('tv', 42, 'alice', 'de-DE', 'DE');

        $this->assertSame('https://www.youtube.com/watch?v=abc', $extras['trailer']['url']);
        $this->assertSame('DE', $extras['watch']['region']);
        $this->assertSame('Netflix', $extras['watch']['stream'][0]['name']);
    }

    public function testMissingPartsGiveEmptyResult(): void {
        $this->client->method('get')->willReturn($this->respond(['id' => 1]));

        $extras = $this->service()->getExtras('movie', 1, 'alice', 'en-US', 'US');

        $this->assertNull($extras['trailer']);
        $this->assertSame([], $extras['watch']['stream']);
    }

    public function testRejectsUnknownType(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->getExtras('person', 1, 'alice', 'en-US', 'US');
    }

    public function testWithoutKeyThrows(): void {
        $this->userConfig = $this->createStub(IUserConfig::class);
        $this->userConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('getValueString')->willReturn('');

        $this->expectException(\Exception::class);
        $this->service()->getExtras('movie', 1, 'alice', 'en-US', 'US');
    }
}
