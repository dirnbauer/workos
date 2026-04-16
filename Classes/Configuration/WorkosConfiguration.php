<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use WebConsulting\WorkosAuth\Service\PathUtility;

final class WorkosConfiguration
{
    public const EXTENSION_KEY = 'workos_auth';

    private const DEFAULTS = [
        'apiKey' => '',
        'clientId' => '',
        'cookiePassword' => '',
        'frontendEnabled' => true,
        'frontendAutoCreateUsers' => true,
        'frontendLinkByEmail' => true,
        'frontendStoragePid' => 0,
        'frontendDefaultGroupUids' => '',
        'frontendLoginPath' => '/workos-auth/frontend/login',
        'frontendCallbackPath' => '/workos-auth/frontend/callback',
        'frontendLogoutPath' => '/workos-auth/frontend/logout',
        'frontendSuccessRedirect' => '/',
        'backendEnabled' => true,
        'backendAutoCreateUsers' => false,
        'backendLinkByEmail' => true,
        'backendDefaultGroupUids' => '',
        'backendAllowedDomains' => '',
        'backendLoginPath' => '/workos-auth/backend/login',
        'backendCallbackPath' => '/workos-auth/backend/callback',
        'backendSuccessPath' => '/main',
        'authkitOrganizationId' => '',
        'authkitConnectionId' => '',
        'authkitDomainHint' => '',
    ];

    private ?array $configuration = null;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function all(): array
    {
        return $this->configuration ??= $this->loadConfiguration();
    }

    public function normalizeInput(array $input): array
    {
        $normalized = self::DEFAULTS;

        foreach (self::DEFAULTS as $key => $defaultValue) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];
            if (is_bool($defaultValue)) {
                $normalized[$key] = (bool)$value;
                continue;
            }

            if (is_int($defaultValue)) {
                $normalized[$key] = max(0, (int)$value);
                continue;
            }

            $normalized[$key] = trim((string)$value);
        }

        foreach ([
            'frontendLoginPath',
            'frontendCallbackPath',
            'frontendLogoutPath',
            'backendLoginPath',
            'backendCallbackPath',
            'backendSuccessPath',
        ] as $pathKey) {
            $normalized[$pathKey] = PathUtility::normalizePath($normalized[$pathKey]);
        }

        $normalized['frontendSuccessRedirect'] = $normalized['frontendSuccessRedirect'] !== ''
            ? trim((string)$normalized['frontendSuccessRedirect'])
            : '/';

        return $normalized;
    }

    public function validate(array $configuration): array
    {
        $errors = [];
        $authEnabled = (bool)($configuration['frontendEnabled'] ?? false) || (bool)($configuration['backendEnabled'] ?? false);

        if ($authEnabled && trim((string)($configuration['apiKey'] ?? '')) === '') {
            $errors['apiKey'] = $this->translate('validation.apiKeyRequired');
        }

        if ($authEnabled && trim((string)($configuration['clientId'] ?? '')) === '') {
            $errors['clientId'] = $this->translate('validation.clientIdRequired');
        }

        if ($authEnabled && mb_strlen(trim((string)($configuration['cookiePassword'] ?? ''))) < 32) {
            $errors['cookiePassword'] = $this->translate('validation.cookiePasswordTooShort');
        }

        if ((bool)($configuration['frontendEnabled'] ?? false)
            && (bool)($configuration['frontendAutoCreateUsers'] ?? false)
            && (int)($configuration['frontendStoragePid'] ?? 0) <= 0
        ) {
            $errors['frontendStoragePid'] = $this->translate('validation.frontendStoragePidRequired');
        }

        if ((bool)($configuration['backendEnabled'] ?? false)
            && (bool)($configuration['backendAutoCreateUsers'] ?? false)
            && trim((string)($configuration['backendDefaultGroupUids'] ?? '')) === ''
        ) {
            $errors['backendDefaultGroupUids'] = $this->translate('validation.backendGroupUidsRequired');
        }

        return $errors;
    }

    private function translate(string $key): string
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        return $languageService->sL('workos_auth.messages:' . $key) ?: $key;
    }

    public function getApiKey(): string
    {
        return trim((string)$this->all()['apiKey']);
    }

    public function getClientId(): string
    {
        return trim((string)$this->all()['clientId']);
    }

    public function getCookiePassword(): string
    {
        return trim((string)$this->all()['cookiePassword']);
    }

    public function isFrontendEnabled(): bool
    {
        return (bool)$this->all()['frontendEnabled'];
    }

    public function isFrontendReady(): bool
    {
        $configuration = $this->all();
        if (!$this->isFrontendEnabled() || !$this->hasBaseCredentials($configuration)) {
            return false;
        }

        return !(bool)$configuration['frontendAutoCreateUsers'] || (int)$configuration['frontendStoragePid'] > 0;
    }

    public function shouldAutoCreateFrontendUsers(): bool
    {
        return (bool)$this->all()['frontendAutoCreateUsers'];
    }

    public function shouldLinkFrontendUsersByEmail(): bool
    {
        return (bool)$this->all()['frontendLinkByEmail'];
    }

    public function getFrontendStoragePid(): int
    {
        return (int)$this->all()['frontendStoragePid'];
    }

    public function getFrontendDefaultGroupUids(): array
    {
        return $this->parseIntegerList((string)$this->all()['frontendDefaultGroupUids']);
    }

    public function getFrontendDefaultGroupCsv(): string
    {
        return implode(',', $this->getFrontendDefaultGroupUids());
    }

    public function getFrontendLoginPath(): string
    {
        return (string)$this->all()['frontendLoginPath'];
    }

    public function getFrontendCallbackPath(): string
    {
        return (string)$this->all()['frontendCallbackPath'];
    }

    public function getFrontendLogoutPath(): string
    {
        return (string)$this->all()['frontendLogoutPath'];
    }

    public function getFrontendSuccessRedirect(): string
    {
        return trim((string)$this->all()['frontendSuccessRedirect']) ?: '/';
    }

    public function isBackendEnabled(): bool
    {
        return (bool)$this->all()['backendEnabled'];
    }

    public function isBackendReady(): bool
    {
        $configuration = $this->all();
        if (!$this->isBackendEnabled() || !$this->hasBaseCredentials($configuration)) {
            return false;
        }

        return !(bool)$configuration['backendAutoCreateUsers']
            || trim((string)$configuration['backendDefaultGroupUids']) !== '';
    }

    public function shouldAutoCreateBackendUsers(): bool
    {
        return (bool)$this->all()['backendAutoCreateUsers'];
    }

    public function shouldLinkBackendUsersByEmail(): bool
    {
        return (bool)$this->all()['backendLinkByEmail'];
    }

    public function getBackendDefaultGroupUids(): array
    {
        return $this->parseIntegerList((string)$this->all()['backendDefaultGroupUids']);
    }

    public function getBackendDefaultGroupCsv(): string
    {
        return implode(',', $this->getBackendDefaultGroupUids());
    }

    public function getBackendAllowedDomains(): array
    {
        $domains = preg_split('/[,\s;]+/', strtolower((string)$this->all()['backendAllowedDomains'])) ?: [];
        return array_values(array_filter(array_map('trim', $domains), static fn(string $value): bool => $value !== ''));
    }

    public function getBackendLoginPath(): string
    {
        return (string)$this->all()['backendLoginPath'];
    }

    public function getBackendCallbackPath(): string
    {
        return (string)$this->all()['backendCallbackPath'];
    }

    public function getBackendSuccessPath(): string
    {
        return (string)$this->all()['backendSuccessPath'];
    }

    public function getAuthkitOrganizationId(): ?string
    {
        $value = trim((string)$this->all()['authkitOrganizationId']);
        return $value !== '' ? $value : null;
    }

    public function getAuthkitConnectionId(): ?string
    {
        $value = trim((string)$this->all()['authkitConnectionId']);
        return $value !== '' ? $value : null;
    }

    public function getAuthkitDomainHint(): ?string
    {
        $value = trim((string)$this->all()['authkitDomainHint']);
        return $value !== '' ? $value : null;
    }

    private function loadConfiguration(): array
    {
        try {
            $configuration = $this->extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\Throwable) {
            $configuration = [];
        }

        if (!is_array($configuration)) {
            $configuration = [];
        }

        return $this->normalizeInput(array_replace(self::DEFAULTS, $configuration));
    }

    private function parseIntegerList(string $value): array
    {
        $items = preg_split('/[,\s;]+/', $value) ?: [];
        $items = array_map(static fn(string $item): int => (int)$item, $items);
        return array_values(array_filter($items, static fn(int $item): bool => $item > 0));
    }

    private function hasBaseCredentials(array $configuration): bool
    {
        return trim((string)($configuration['apiKey'] ?? '')) !== ''
            && trim((string)($configuration['clientId'] ?? '')) !== ''
            && mb_strlen(trim((string)($configuration['cookiePassword'] ?? ''))) >= 32;
    }
}
