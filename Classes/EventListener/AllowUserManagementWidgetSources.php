<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\EventListener;

use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;

/**
 * Opens the backend Content Security Policy for the WorkOS User Management
 * widget — its API calls, its avatar images, its inlined fonts — on the one
 * module page that embeds it, instead of for the whole backend.
 *
 * The Core builds the policy with the request as it entered the backend,
 * before routing resolved the module, so the page is recognised by its path.
 */
#[AsEventListener('workos-auth/user-management-widget-sources')]
final readonly class AllowUserManagementWidgetSources
{
    public const string MODULE_IDENTIFIER = 'workos_users';

    public function __construct(
        private ModuleProvider $moduleProvider,
    ) {}

    public function __invoke(PolicyMutatedEvent $event): void
    {
        if ($event->scope->type !== ApplicationType::BACKEND
            || $event->request === null
            || !$this->moduleProvider->isModuleRegistered(self::MODULE_IDENTIFIER)
        ) {
            return;
        }
        $modulePath = rtrim($this->moduleProvider->getModule(self::MODULE_IDENTIFIER)?->getPath() ?? '', '/');
        if ($modulePath === '' || !str_ends_with(rtrim($event->request->getUri()->getPath(), '/'), $modulePath)) {
            return;
        }

        $event->setCurrentPolicy($event->getCurrentPolicy()->mutate(
            new Mutation(MutationMode::Extend, Directive::ConnectSrc, new UriValue('https://api.workos.com')),
            new Mutation(
                MutationMode::Extend,
                Directive::ImgSrc,
                new UriValue('https://*.workoscdn.com'),
                new UriValue('https://workoscdn.com'),
                new UriValue('https://api.workos.com'),
                SourceScheme::data,
            ),
            new Mutation(MutationMode::Extend, Directive::FontSrc, SourceScheme::data),
        ));
    }
}
