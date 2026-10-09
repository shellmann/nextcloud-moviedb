<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\PageController;
use OCA\MovieDB\Service\SettingsService;
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

        $initialState = $this->createMock(IInitialState::class);
        $initialState->expects($this->once())
            ->method('provideInitialState')
            ->with('settings', $settings);

        $controller = new PageController(
            $this->createMock(IRequest::class),
            $this->createMock(IURLGenerator::class),
            $userSession,
            $settingsService,
            $initialState
        );

        $this->assertSame('index', $controller->index()->getTemplateName());
    }
}
