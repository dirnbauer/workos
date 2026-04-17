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
     * Shared assets (CSS for the heading + button row, the JS that relocates the
     * provider switcher) are loaded for both providers so the visual treatment is
     * consistent regardless of which login screen is active.
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

        $this->pageRenderer->addCssInlineBlock(
            'workos-login-heading',
            <<<'CSS'
            .workos-login-heading {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 0.25rem;
                margin: 0 -1.875rem 1.25rem;
                padding: 0.85rem 1.875rem;
                background: var(--typo3-surface-container-color, #f3f4f6);
                border-top: 1px solid var(--typo3-component-border-color, rgba(0, 0, 0, 0.08));
                border-bottom: 1px solid var(--typo3-component-border-color, rgba(0, 0, 0, 0.08));
                text-align: center;
                color: var(--typo3-text-color-base, #1f2937);
            }
            .workos-login-heading__title {
                font-size: 1rem;
                font-weight: 600;
                line-height: 1.35;
                letter-spacing: 0.01em;
            }
            .workos-login-heading__title strong { font-weight: 700; }
            /* Defensive: force the relocated provider switcher to render as a
               small inline text link, regardless of any TYPO3 backend styles
               that target anchors inside cards/headings. */
            a.workos-login-heading__link,
            .workos-login-heading a.workos-login-heading__link {
                display: inline !important;
                width: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                background: transparent !important;
                border: 0 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                font-size: 0.8rem !important;
                font-weight: 400 !important;
                line-height: 1.3 !important;
                letter-spacing: normal !important;
                color: inherit !important;
                text-decoration: underline !important;
                text-underline-offset: 0.15em !important;
                opacity: 0.85;
            }
            a.workos-login-heading__link:hover,
            a.workos-login-heading__link:focus,
            .workos-login-heading a.workos-login-heading__link:hover,
            .workos-login-heading a.workos-login-heading__link:focus {
                color: inherit !important;
                background: transparent !important;
                border: 0 !important;
                text-decoration: underline !important;
                opacity: 1;
            }

            /* The provider switcher link is moved INSIDE the heading box as a
               small text link, and the "More sign-in options" link is moved
               into a button row below the heading. Hide the originals
               immediately to avoid a layout flash. */
            .typo3-login-links { display: none !important; }
            .workos-authkit-link[data-workos-relocate] { display: none; }

            .workos-login-buttons {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                margin: 0 0 1.25rem;
            }
            .workos-login-buttons__btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
                padding: 0.6rem 1rem;
                font-size: 0.95rem;
                font-weight: 500;
                line-height: 1.4;
                text-align: center;
                text-decoration: none;
                color: var(--typo3-text-color-base, #1f2937);
                background: var(--typo3-btn-bg, #fff);
                border: 1px solid color-mix(in srgb, currentColor 22%, transparent);
                border-radius: var(--typo3-btn-border-radius, 6px);
                transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
            }
            .workos-login-buttons__btn:hover,
            .workos-login-buttons__btn:focus {
                background: color-mix(in srgb, currentColor 6%, transparent);
                border-color: color-mix(in srgb, currentColor 40%, transparent);
                color: var(--typo3-text-color-base, #111827);
                text-decoration: none;
            }

            @media (prefers-color-scheme: dark) {
                .workos-login-heading {
                    background: var(--typo3-surface-container-color, #2a2f36);
                    border-color: var(--typo3-component-border-color, rgba(255, 255, 255, 0.08));
                    color: var(--typo3-text-color-base, #e5e7eb);
                }
                .workos-login-buttons__btn {
                    background: var(--typo3-btn-bg, transparent);
                    color: var(--typo3-text-color-base, #e5e7eb);
                }
            }
            CSS
        );

        $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
            JavaScriptModuleInstruction::create('@webconsulting/workos-auth/login-headings.js')
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

        // Plain-HTML data carrier (no script, so no CSP concerns).
        $this->pageRenderer->addHeaderData(sprintf(
            '<template data-workos-login-heading data-text="%s"></template>',
            htmlspecialchars($headingText, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));
    }
}
