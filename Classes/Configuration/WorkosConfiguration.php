<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Configuration;

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use WebConsulting\WorkosAuth\Service\PathUtility;

/**
 * @phpstan-type WorkosSettings array{
 *     apiKey: string,
 *     clientId: string,
 *     cookiePassword: string,
 *     frontendEnabled: bool,
 *     frontendAutoCreateUsers: bool,
 *     frontendLinkByEmail: bool,
 *     frontendStoragePid: int,
 *     frontendDefaultGroupUids: string,
 *     frontendLoginPath: string,
 *     frontendCallbackPath: string,
 *     frontendLogoutPath: string,
 *     frontendSuccessRedirect: string,
 *     backendEnabled: bool,
 *     backendAutoCreateUsers: bool,
 *     backendLinkByEmail: bool,
 *     backendDefaultGroupUids: string,
 *     backendAllowedDomains: string,
 *     backendLoginPath: string,
 *     backendCallbackPath: string,
 *     backendSuccessPath: string,
 *     authkitOrganizationId: string,
 *     authkitConnectionId: string,
 *     authkitDomainHint: string,
 * }
 */
final class WorkosConfiguration
{
    public const EXTENSION_KEY = 'workos_auth';

    /**
     * Social login providers supported by the `?provider=` query
     * parameter on the login endpoints. Values are the identifiers
     * WorkOS expects when building the authorization URL.
     *
     * @var list<string>
     */
    public const SUPPORTED_SOCIAL_PROVIDERS = [
        'GoogleOAuth',
        'MicrosoftOAuth',
        'GitHubOAuth',
        'AppleOAuth',
    ];

    /**
     * @var WorkosSettings
     */
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

    /**
     * @var WorkosSettings|null
     */
    private ?array $configuration = null;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @return WorkosSettings
     */
    public function all(): array
    {
        return $this->configuration ??= $this->loadConfiguration();
    }

    /**
     * @param array<string, mixed> $input
     * @return WorkosSettings
     */
    public function normalizeInput(array $input): array
    {
        $successRedirect = self::toString($input['frontendSuccessRedirect'] ?? self::DEFAULTS['frontendSuccessRedirect']);
        $successRedirect = trim($successRedirect) !== '' ? trim($successRedirect) : '/';

        return [
            'apiKey' => trim(self::toString($input['apiKey'] ?? self::DEFAULTS['apiKey'])),
            'clientId' => trim(self::toString($input['clientId'] ?? self::DEFAULTS['clientId'])),
            'cookiePassword' => trim(self::toString($input['cookiePassword'] ?? self::DEFAULTS['cookiePassword'])),
            'frontendEnabled' => (bool)($input['frontendEnabled'] ?? self::DEFAULTS['frontendEnabled']),
            'frontendAutoCreateUsers' => (bool)($input['frontendAutoCreateUsers'] ?? self::DEFAULTS['frontendAutoCreateUsers']),
            'frontendLinkByEmail' => (bool)($input['frontendLinkByEmail'] ?? self::DEFAULTS['frontendLinkByEmail']),
            'frontendStoragePid' => max(0, self::toInt($input['frontendStoragePid'] ?? self::DEFAULTS['frontendStoragePid'])),
            'frontendDefaultGroupUids' => trim(self::toString($input['frontendDefaultGroupUids'] ?? self::DEFAULTS['frontendDefaultGroupUids'])),
            'frontendLoginPath' => PathUtility::normalizePath(trim(self::toString($input['frontendLoginPath'] ?? self::DEFAULTS['frontendLoginPath']))),
            'frontendCallbackPath' => PathUtility::normalizePath(trim(self::toString($input['frontendCallbackPath'] ?? self::DEFAULTS['frontendCallbackPath']))),
            'frontendLogoutPath' => PathUtility::normalizePath(trim(self::toString($input['frontendLogoutPath'] ?? self::DEFAULTS['frontendLogoutPath']))),
            'frontendSuccessRedirect' => $successRedirect,
            'backendEnabled' => (bool)($input['backendEnabled'] ?? self::DEFAULTS['backendEnabled']),
            'backendAutoCreateUsers' => (bool)($input['backendAutoCreateUsers'] ?? self::DEFAULTS['backendAutoCreateUsers']),
            'backendLinkByEmail' => (bool)($input['backendLinkByEmail'] ?? self::DEFAULTS['backendLinkByEmail']),
            'backendDefaultGroupUids' => trim(self::toString($input['backendDefaultGroupUids'] ?? self::DEFAULTS['backendDefaultGroupUids'])),
            'backendAllowedDomains' => trim(self::toString($input['backendAllowedDomains'] ?? self::DEFAULTS['backendAllowedDomains'])),
            'backendLoginPath' => PathUtility::normalizePath(trim(self::toString($input['backendLoginPath'] ?? self::DEFAULTS['backendLoginPath']))),
            'backendCallbackPath' => PathUtility::normalizePath(trim(self::toString($input['backendCallbackPath'] ?? self::DEFAULTS['backendCallbackPath']))),
            'backendSuccessPath' => PathUtility::normalizePath(trim(self::toString($input['backendSuccessPath'] ?? self::DEFAULTS['backendSuccessPath']))),
            'authkitOrganizationId' => trim(self::toString($input['authkitOrganizationId'] ?? self::DEFAULTS['authkitOrganizationId'])),
            'authkitConnectionId' => trim(self::toString($input['authkitConnectionId'] ?? self::DEFAULTS['authkitConnectionId'])),
            'authkitDomainHint' => trim(self::toString($input['authkitDomainHint'] ?? self::DEFAULTS['authkitDomainHint'])),
        ];
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array<string, string>
     */
    public function validate(array $configuration): array
    {
        $errors = [];
        $frontendEnabled = (bool)($configuration['frontendEnabled'] ?? false);
        $backendEnabled = (bool)($configuration['backendEnabled'] ?? false);
        $authEnabled = $frontendEnabled || $backendEnabled;

        if ($authEnabled && trim(self::toString($configuration['apiKey'] ?? '')) === '') {
            $errors['apiKey'] = $this->translate('validation.apiKeyRequired');
        }

        if ($authEnabled && trim(self::toString($configuration['clientId'] ?? '')) === '') {
            $errors['clientId'] = $this->translate('validation.clientIdRequired');
        }

        if ($authEnabled && mb_strlen(trim(self::toString($configuration['cookiePassword'] ?? ''))) < 32) {
            $errors['cookiePassword'] = $this->translate('validation.cookiePasswordTooShort');
        }

        if ($frontendEnabled
            && (bool)($configuration['frontendAutoCreateUsers'] ?? false)
            && self::toInt($configuration['frontendStoragePid'] ?? 0) <= 0
        ) {
            $errors['frontendStoragePid'] = $this->translate('validation.frontendStoragePidRequired');
        }

        if ($backendEnabled
            && (bool)($configuration['backendAutoCreateUsers'] ?? false)
            && trim(self::toString($configuration['backendDefaultGroupUids'] ?? '')) === ''
        ) {
            $errors['backendDefaultGroupUids'] = $this->translate('validation.backendGroupUidsRequired');
        }

        if ($backendEnabled && !$this->isBackendCookieSameSiteCompatible()) {
            $errors['backendCookieSameSite'] = $this->translate(
                'validation.backendCookieSameSiteUnsupported',
                [$this->getBackendCookieSameSite()]
            );
        }

        return $errors;
    }

    public function getApiKey(): string
    {
        return trim($this->all()['apiKey']);
    }

    public function getClientId(): string
    {
        return trim($this->all()['clientId']);
    }

    public function getCookiePassword(): string
    {
        return trim($this->all()['cookiePassword']);
    }

    public function isFrontendEnabled(): bool
    {
        return $this->all()['frontendEnabled'];
    }

    public function isFrontendReady(): bool
    {
        $configuration = $this->all();
        if (!$this->isFrontendEnabled() || !$this->hasBaseCredentials($configuration)) {
            return false;
        }

        return !$configuration['frontendAutoCreateUsers'] || $configuration['frontendStoragePid'] > 0;
    }

    public function shouldAutoCreateFrontendUsers(): bool
    {
        return $this->all()['frontendAutoCreateUsers'];
    }

    public function shouldLinkFrontendUsersByEmail(): bool
    {
        return $this->all()['frontendLinkByEmail'];
    }

    public function getFrontendStoragePid(): int
    {
        return $this->all()['frontendStoragePid'];
    }

    /**
     * @return list<int>
     */
    public function getFrontendDefaultGroupUids(): array
    {
        return $this->parseIntegerList($this->all()['frontendDefaultGroupUids']);
    }

    public function getFrontendDefaultGroupCsv(): string
    {
        return implode(',', $this->getFrontendDefaultGroupUids());
    }

    public function getFrontendLoginPath(): string
    {
        return $this->all()['frontendLoginPath'];
    }

    public function getFrontendCallbackPath(): string
    {
        return $this->all()['frontendCallbackPath'];
    }

    public function getFrontendLogoutPath(): string
    {
        return $this->all()['frontendLogoutPath'];
    }

    public function getFrontendSuccessRedirect(): string
    {
        $value = trim($this->all()['frontendSuccessRedirect']);
        return $value !== '' ? $value : '/';
    }

    public function isBackendEnabled(): bool
    {
        return $this->all()['backendEnabled'];
    }

    public function isBackendReady(): bool
    {
        $configuration = $this->all();
        if (!$this->isBackendEnabled() || !$this->hasBaseCredentials($configuration)) {
            return false;
        }

        return $this->isBackendCookieSameSiteCompatible()
            && (!$configuration['backendAutoCreateUsers']
            || trim($configuration['backendDefaultGroupUids']) !== '');
    }

    public function getBackendCookieSameSite(): string
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $stringKeyedConfVars = is_array($confVars) ? $confVars : [];
        $beConfiguration = is_array($stringKeyedConfVars['BE'] ?? null) ? $stringKeyedConfVars['BE'] : [];
        $value = strtolower(trim(self::toString($beConfiguration['cookieSameSite'] ?? 'strict')));
        return $value !== '' ? $value : 'strict';
    }

    public function isBackendCookieSameSiteCompatible(): bool
    {
        return in_array($this->getBackendCookieSameSite(), ['strict', 'lax', 'none'], true);
    }

    public function shouldAutoCreateBackendUsers(): bool
    {
        return $this->all()['backendAutoCreateUsers'];
    }

    public function shouldLinkBackendUsersByEmail(): bool
    {
        return $this->all()['backendLinkByEmail'];
    }

    /**
     * @return list<int>
     */
    public function getBackendDefaultGroupUids(): array
    {
        return $this->parseIntegerList($this->all()['backendDefaultGroupUids']);
    }

    public function getBackendDefaultGroupCsv(): string
    {
        return implode(',', $this->getBackendDefaultGroupUids());
    }

    /**
     * @return list<string>
     */
    public function getBackendAllowedDomains(): array
    {
        $split = preg_split('/[,\s;]+/', strtolower($this->all()['backendAllowedDomains']));
        $domains = $split === false ? [] : $split;
        return array_values(array_filter(array_map('trim', $domains), static fn(string $value): bool => $value !== ''));
    }

    public function getBackendLoginPath(): string
    {
        return $this->all()['backendLoginPath'];
    }

    public function getBackendCallbackPath(): string
    {
        return $this->all()['backendCallbackPath'];
    }

    public function getBackendSuccessPath(): string
    {
        return $this->all()['backendSuccessPath'];
    }

    public function getAuthkitOrganizationId(): ?string
    {
        $value = trim($this->all()['authkitOrganizationId']);
        return $value !== '' ? $value : null;
    }

    public function getAuthkitConnectionId(): ?string
    {
        $value = trim($this->all()['authkitConnectionId']);
        return $value !== '' ? $value : null;
    }

    public function getAuthkitDomainHint(): ?string
    {
        $value = trim($this->all()['authkitDomainHint']);
        return $value !== '' ? $value : null;
    }

    /**
     * @return WorkosSettings
     */
    private function loadConfiguration(): array
    {
        try {
            $raw = $this->extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\Throwable) {
            $raw = [];
        }

        $input = is_array($raw) ? $raw : [];
        $stringKeyedInput = [];
        foreach ($input as $key => $value) {
            $stringKeyedInput[(string)$key] = $value;
        }
        return $this->normalizeInput($stringKeyedInput);
    }

    /**
     * @param WorkosSettings $configuration
     */
    private function hasBaseCredentials(array $configuration): bool
    {
        return trim($configuration['apiKey']) !== ''
            && trim($configuration['clientId']) !== '';
    }

    /**
     * @return list<int>
     */
    private function parseIntegerList(string $value): array
    {
        $split = preg_split('/[,\s;]+/', $value);
        $items = $split === false ? [] : $split;
        $ints = array_map(static fn(string $item): int => (int)$item, $items);
        return array_values(array_filter($ints, static fn(int $item): bool => $item > 0));
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        $languageService = $this->languageServiceFactory->createFromUserPreferences(
            $beUser instanceof AbstractUserAuthentication ? $beUser : null
        );
        return (string)$languageService->label('workos_auth.messages:' . $key, $arguments, $key);
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        return '';
    }

    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int)$value;
        }
        if (is_float($value)) {
            return (int)$value;
        }
        return 0;
    }
}
