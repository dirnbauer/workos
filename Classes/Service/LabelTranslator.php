<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Translates extension labels from the `workos_auth.messages` domain for
 * non-Extbase code (backend modules, middlewares, the login provider).
 *
 * The language is taken from the backend user preferences when a backend
 * session exists; otherwise TYPO3's default language is used.
 */
final readonly class LabelTranslator
{
    private const string DOMAIN = 'workos_auth.messages:';

    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @param array<int|string, mixed> $arguments
     */
    public function translate(string $key, array $arguments = []): string
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $languageService = $this->languageServiceFactory->createFromUserPreferences(
            $backendUser instanceof AbstractUserAuthentication ? $backendUser : null
        );

        return (string)$languageService->label(self::DOMAIN . $key, $arguments, $key);
    }
}
