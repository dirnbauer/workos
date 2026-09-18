<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\EventListener;

use TYPO3\CMS\Backend\LoginProvider\Event\ModifyPageLayoutOnLoginProviderSelectionEvent;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderResolver;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use Webconsulting\WorkosAuth\LoginProvider\WorkosBackendLoginProvider;
use Webconsulting\WorkosAuth\Service\LabelTranslator;

/**
 * Gives every backend login screen the same heading treatment: the WorkOS
 * provider renders its heading in Fluid, the classic username/password form
 * gets one injected client-side. login-headings.js relocates TYPO3's
 * provider-switcher link into that heading; the labels are handed over in a
 * plain `<template>` element (no inline script, CSP-safe).
 */
#[AsEventListener('workos-auth/inject-classic-login-heading')]
final readonly class InjectLoginHeadingsListener
{
    public function __construct(
        private PageRenderer $pageRenderer,
        private LabelTranslator $translator,
        private LoginProviderResolver $loginProviderResolver,
    ) {}

    public function __invoke(ModifyPageLayoutOnLoginProviderSelectionEvent $event): void
    {
        $isWorkosProvider = $this->loginProviderResolver->resolveLoginProviderIdentifierFromRequest(
            $event->getRequest(),
            'be_lastLoginProvider'
        ) === WorkosBackendLoginProvider::IDENTIFIER;

        $this->pageRenderer->addCssFile('EXT:workos_auth/Resources/Public/Css/Backend/login-headings.css');
        $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
            JavaScriptModuleInstruction::create('@webconsulting/workos-auth/login-headings.js')
        );

        $attributes = [
            'data-switch-text' => $this->translator->translate(
                $isWorkosProvider ? 'backend.login.switch.toClassic' : 'backend.login.switch.toWorkos'
            ),
        ];
        if (!$isWorkosProvider) {
            $attributes['data-text'] = $this->translator->translate('backend.login.heading.classic');
        }

        $this->pageRenderer->addHeaderData(sprintf(
            '<template data-workos-login-heading %s></template>',
            implode(' ', array_map(
                static fn(string $name, string $value): string => sprintf('%s="%s"', $name, htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                array_keys($attributes),
                $attributes
            ))
        ));
    }
}
