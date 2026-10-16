<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\PageController;
use OCA\MovieDB\Service\SettingsService;
use OCA\MovieDB\Service\WhatsNewService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;

class PageControllerTest extends TestCase {
    public function testSendsSettingsWithThePage(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $settings = ['hasApiKey' => true, 'hasUserApiKey' => false, 'hasInstanceApiKey' => true];
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getUserSettings')->with('alice')->willReturn($settings);

        $whatsNew = ['currentVersion' => '1.9.0', 'lastSeenVersion' => '1.8.0'];
        $whatsNewService = $this->createMock(WhatsNewService::class);
        $whatsNewService->method('state')->with('alice')->willReturn($whatsNew);

        $provided = [];
        $initialState = $this->createMock(IInitialState::class);
        $initialState->expects($this->exactly(2))
            ->method('provideInitialState')
            ->willReturnCallback(function (string $key, mixed $data) use (&$provided) {
                $provided[$key] = $data;
            });

        $controller = new PageController(
            $this->createMock(IRequest::class),
            $this->createMock(IURLGenerator::class),
            $userSession,
            $settingsService,
            $initialState,
            $whatsNewService,
        );

        $this->assertSame('index', $controller->index()->getTemplateName());
        $this->assertSame(['settings' => $settings, 'whatsNew' => $whatsNew], $provided);
    }
}
