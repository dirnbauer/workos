<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\EventListener;

use TYPO3\CMS\Backend\LoginProvider\Event\ModifyPageLayoutOnLoginProviderSelectionEvent;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderResolver;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;

#[AsEventListener('workos-auth/inject-classic-login-heading')]
final readonly class InjectLoginHeadingsListener
{
    /**
     * Identifier of the WorkOS backend login provider (registered in ext_localconf.php).
     * The WorkOS provider renders its own heading inside the Fluid template, so we only
     * need to inject one for foreign providers (the standard "Username/Password" form).
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

        if ($providerIdentifier === self::WORKOS_PROVIDER_IDENTIFIER) {
            return;
        }

        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        $headingText = (string)$languageService->sL(
            'LLL:EXT:workos_auth/Resources/Private/Language/locallang.xlf:backend.login.heading.classic'
        );
        if ($headingText === '') {
            $headingText = 'Classic sign-in';
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

        // Plain-HTML data carrier (no script, so no CSP concerns).
        $this->pageRenderer->addHeaderData(sprintf(
            '<template data-workos-login-heading data-text="%s"></template>',
            htmlspecialchars($headingText, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));

        $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
            JavaScriptModuleInstruction::create('@webconsulting/workos-auth/login-headings.js')
        );
    }
}
