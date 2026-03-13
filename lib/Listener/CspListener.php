<?php

declare(strict_types=1);

namespace OCA\MovieDB\Listener;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * @template-implements IEventListener<AddContentSecurityPolicyEvent>
 */
class CspListener implements IEventListener {
    public function handle(Event $event): void {
        if (!$event instanceof AddContentSecurityPolicyEvent) {
            return;
        }

        $csp = new ContentSecurityPolicy();
        $csp->addAllowedImageDomain('https://image.tmdb.org');
        $event->addPolicy($csp);
    }
}
