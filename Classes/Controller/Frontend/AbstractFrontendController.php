<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Controller\Frontend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\RequestTokenService;
use Webconsulting\WorkosAuth\Service\IdentityService;

/**
 * Shared plumbing of the WorkOS frontend plugins: request-token validation,
 * session-bound one-shot messages, translation and the lookup of the WorkOS
 * identity linked to the signed-in frontend user.
 */
abstract class AbstractFrontendController extends ActionController
{
    /**
     * Scope of the request token every state-changing action must carry.
     */
    protected const string REQUEST_TOKEN_SCOPE = '';

    /**
     * Frontend session key used for one-shot flash messages.
     */
    protected const string SESSION_FLASH = '';

    public function __construct(
        protected readonly WorkosConfiguration $configuration,
        protected readonly IdentityService $identityService,
        protected readonly RequestTokenService $requestTokenService,
    ) {}

    protected function getFrontendUser(): FrontendUserAuthentication
    {
        $frontendUser = $this->request->getAttribute('frontend.user');
        if (!$frontendUser instanceof FrontendUserAuthentication) {
            throw new \RuntimeException('No frontend user session available.', 1744277820);
        }

        return $frontendUser;
    }

    protected function isFrontendUserLoggedIn(): bool
    {
        $frontendUser = $this->request->getAttribute('frontend.user');

        return $frontendUser instanceof FrontendUserAuthentication && is_array($frontendUser->user);
    }

    /**
     * First non-empty of name, username, email of the signed-in user.
     */
    protected function resolveDisplayName(): string
    {
        $user = $this->getFrontendUser()->user ?? [];
        foreach (['name', 'username', 'email'] as $field) {
            $value = trim(MixedCaster::string($user[$field] ?? null));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * The WorkOS user id linked to the signed-in frontend user. When the
     * plugin is not usable (not configured, not signed in, not linked) the
     * "empty state" response to return instead is given back.
     */
    protected function resolveLinkedWorkosUserId(): ResponseInterface|string
    {
        if (!$this->configuration->isFrontendReady() || !$this->isFrontendUserLoggedIn()) {
            $this->view->assignMultiple([
                'configured' => $this->configuration->isFrontendReady(),
                'isLoggedIn' => $this->isFrontendUserLoggedIn(),
            ]);

            return $this->htmlResponse();
        }

        $identity = $this->identityService->findIdentityByLocalUser(
            LoginContext::Frontend,
            MixedCaster::int($this->getFrontendUser()->user['uid'] ?? null)
        );
        $workosUserId = MixedCaster::string($identity['workos_user_id'] ?? null);

        $this->view->assignMultiple([
            'configured' => true,
            'isLoggedIn' => true,
            'noWorkosLink' => $workosUserId === '',
            'displayName' => $this->resolveDisplayName(),
            'workosUserId' => $workosUserId,
        ]);

        return $workosUserId === '' ? $this->htmlResponse() : $workosUserId;
    }

    protected function hasValidRequestToken(): bool
    {
        return $this->requestTokenService->validate(static::REQUEST_TOKEN_SCOPE);
    }

    protected function setFlash(string $type, string $message): void
    {
        $this->getFrontendUser()->setAndSaveSessionData(static::SESSION_FLASH, ['type' => $type, 'message' => $message]);
    }

    /**
     * @return array{type: string, message: string}|null
     */
    protected function consumeFlash(): ?array
    {
        $flash = $this->getFrontendUser()->getSessionData(static::SESSION_FLASH);
        $message = is_array($flash) ? MixedCaster::string($flash['message'] ?? null) : '';
        if ($message === '') {
            return null;
        }
        $this->getFrontendUser()->setAndSaveSessionData(static::SESSION_FLASH, null);

        return ['type' => MixedCaster::string($flash['type'] ?? null), 'message' => $message];
    }

    /**
     * Read and clear a one-shot string stored in the frontend session.
     */
    protected function consumeSessionString(string $key): ?string
    {
        $value = $this->getFrontendUser()->getSessionData($key);
        if (!is_string($value) || $value === '') {
            return null;
        }
        $this->getFrontendUser()->setAndSaveSessionData($key, null);

        return $value;
    }

    /**
     * Read and clear a one-shot array stored in the frontend session.
     *
     * @return array<string, mixed>|null
     */
    protected function consumeSessionArray(string $key): ?array
    {
        $value = MixedCaster::stringKeyedArray($this->getFrontendUser()->getSessionData($key));
        if ($value !== null) {
            $this->getFrontendUser()->setAndSaveSessionData($key, null);
        }

        return $value;
    }

    protected function formatDateTime(\DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i');
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    protected function translate(string $key, array $arguments = []): string
    {
        return LocalizationUtility::translate($key, 'WorkosAuth', $arguments !== [] ? $arguments : null) ?? $key;
    }
}
