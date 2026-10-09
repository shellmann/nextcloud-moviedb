<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Settings;

use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Settings\Admin;
use OCA\MovieDB\Settings\AdminSection;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;

class AdminTest extends TestCase {
    public function testFormProvidesOnlyWhetherAKeyIsSet(): void {
        $tmdbService = $this->createMock(TmdbService::class);
        $tmdbService->method('hasInstanceApiKey')->willReturn(true);

        $initialState = $this->createMock(IInitialState::class);
        $initialState->expects($this->once())
            ->method('provideInitialState')
            ->with('admin-settings', ['hasInstanceApiKey' => true]);

        $form = (new Admin($tmdbService, $initialState))->getForm();

        $this->assertSame('admin', $form->getTemplateName());
        $this->assertSame(TemplateResponse::RENDER_AS_BLANK, $form->getRenderAs());
    }

    public function testFormIsInTheMovieDbSection(): void {
        $admin = new Admin($this->createMock(TmdbService::class), $this->createMock(IInitialState::class));
        $section = new AdminSection($this->createMock(IURLGenerator::class));

        $this->assertSame($section->getID(), $admin->getSection());
    }

    public function testSectionUsesDarkAppIcon(): void {
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('imagePath')->with('moviedb', 'app-dark.svg')->willReturn('/apps/moviedb/img/app-dark.svg');

        $this->assertSame('/apps/moviedb/img/app-dark.svg', (new AdminSection($urlGenerator))->getIcon());
    }
}
