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
 * Which TMDB key is used, and how the instance-wide key is stored and checked.
 */
class TmdbServiceApiKeyTest extends TestCase {
    private IClientService $clientService;
    private IClient $client;
    private IUserConfig $userConfig;
    private IAppConfig $appConfig;
    private TmdbService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->client = $this->createMock(IClient::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->clientService->method('newClient')->willReturn($this->client);
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->appConfig = $this->createMock(IAppConfig::class);

        $this->service = new TmdbService($this->clientService, $this->userConfig, $this->appConfig);
    }

    private function givenKeys(string $userKey, string $instanceKey): void {
        $this->userConfig->method('getValueString')->willReturn($userKey);
        $this->appConfig->method('getValueString')->willReturn($instanceKey);
    }

    public function testUserKeyOverridesInstanceKey(): void {
        $this->givenKeys('user-key', 'instance-key');

        $this->client->expects($this->once())
            ->method('get')
            ->with($this->anything(), $this->callback(
                fn (array $options) => $options['headers']['Authorization'] === 'Bearer user-key'
            ))
            ->willReturn($this->response(200, '{"results":[]}'));

        $this->service->searchMovies('Alien', null, 1, 'alice');
    }

    public function testInstanceKeyIsUsedWithoutUserKey(): void {
        $this->givenKeys('', 'instance-key');

        $this->client->expects($this->once())
            ->method('get')
            ->with($this->anything(), $this->callback(
                fn (array $options) => $options['headers']['Authorization'] === 'Bearer instance-key'
            ))
            ->willReturn($this->response(200, '{"results":[]}'));

        $this->assertTrue($this->service->hasApiKey('alice'));
        $this->service->searchMovies('Alien', null, 1, 'alice');
    }

    public function testNoKeyAtAll(): void {
        $this->givenKeys('', '');

        $this->assertFalse($this->service->hasApiKey('alice'));
        $this->assertFalse($this->service->hasInstanceApiKey());
    }

    public function testHasInstanceApiKey(): void {
        $this->givenKeys('', 'instance-key');

        $this->assertTrue($this->service->hasInstanceApiKey());
    }

    public function testInstanceKeyIsStoredAsSensitive(): void {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('moviedb', 'tmdb_api_key', 'instance-key', false, true);

        $this->service->setInstanceApiKey('instance-key');
    }

    public function testDeleteInstanceKey(): void {
        $this->appConfig->expects($this->once())
            ->method('deleteKey')
            ->with('moviedb', 'tmdb_api_key');

        $this->service->deleteInstanceApiKey();
    }

    public function testVerifyAcceptedKey(): void {
        $this->client->expects($this->once())
            ->method('get')
            ->with('https://api.themoviedb.org/3/authentication', $this->callback(
                fn (array $options) => $options['headers']['Authorization'] === 'Bearer new-key'
                    && $options['http_errors'] === false
            ))
            ->willReturn($this->response(200));

        $this->assertTrue($this->service->verifyApiKey('new-key'));
    }

    public function testVerifyRejectedKey(): void {
        $this->client->method('get')->willReturn($this->response(401));

        $this->assertFalse($this->service->verifyApiKey('wrong-key'));
    }

    public function testVerifyUnexpectedStatusThrows(): void {
        $this->client->method('get')->willReturn($this->response(500));

        $this->expectException(\RuntimeException::class);
        $this->service->verifyApiKey('new-key');
    }

    public function testVerifyNetworkErrorThrows(): void {
        $this->client->method('get')->willThrowException(new \Exception('Connection refused'));

        $this->expectException(\RuntimeException::class);
        $this->service->verifyApiKey('new-key');
    }

    private function response(int $status, string $body = '{}'): IResponse {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);
        return $response;
    }
}
