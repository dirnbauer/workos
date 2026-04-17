<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\EventListener;

use TYPO3\CMS\Backend\LoginProvider\Event\ModifyPageLayoutOnLoginProviderSelectionEvent;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderResolver;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;

#[AsEventListener('workos-auth/inject-classic-login-heading')]
final readonly class InjectLoginHeadingsListener
{
    /**
     * Identifier of the WorkOS backend login provider (see ext_localconf.php).
     */
    private const WORKOS_PROVIDER_IDENTIFIER = '1744276800';

    public function __construct(
        private PageRenderer $pageRenderer,
        private LanguageServiceFactory $languageServiceFactory,
        private LoginProviderResolver $loginProviderResolver,
    ) {}

    public function __invoke(ModifyPageLayoutOnLoginProviderSelectionEvent $event): void
    {
        $request = $event->getRequest();
        $providerIdentifier = $this->loginProviderResolver->resolveLoginProviderIdentifierFromRequest(
            $request,
            'be_lastLoginProvider'
        );

        // The WorkOS provider renders its own heading inside its Fluid template.
        if ($providerIdentifier === self::WORKOS_PROVIDER_IDENTIFIER) {
            return;
        }

        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        $headingText = (string)$languageService->sL('LLL:EXT:workos_auth/Resources/Private/Language/locallang.xlf:backend.login.heading.classic');
        if ($headingText === '') {
            $headingText = 'Classic sign-in';
        }

        try {
            $headingJson = json_encode($headingText, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        $this->pageRenderer->addCssInlineBlock(
            'workos-classic-login-heading',
            <<<CSS
            .workos-login-heading {
                margin: 0 0 1rem;
                font-size: 1.05rem;
                font-weight: 600;
                text-align: center;
                line-height: 1.35;
                color: var(--typo3-text-color-base, #1f2937);
            }
            .workos-login-heading strong { font-weight: 700; }
            CSS
        );

        $this->pageRenderer->addJsFooterInlineCode(
            'workos-classic-login-heading',
            <<<JS
            (function () {
                function inject() {
                    var form = document.getElementById('typo3-login-form');
                    if (!form || form.querySelector('.workos-login-heading')) { return; }
                    var heading = document.createElement('h2');
                    heading.className = 'workos-login-heading';
                    heading.textContent = {$headingJson};
                    form.insertBefore(heading, form.firstChild);
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', inject);
                } else {
                    inject();
                }
            })();
            JS
        );
    }
}
