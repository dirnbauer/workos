<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Controller\Frontend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\RequestTokenService;
use Webconsulting\WorkosAuth\Service\IdentityService;

/**
 * Shared plumbing for the WorkOS frontend plugins: request-token
 * validation, session-bound flash messages, translation and the lookup
 * of the WorkOS identity linked to the signed-in frontend user.
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

    protected function isFrontendUserLoggedIn(): bool
    {
        $frontendUser = $this->request->getAttribute('frontend.user');

        return $frontendUser instanceof FrontendUserAuthentication && is_array($frontendUser->user ?? null);
    }

    /**
     * Resolve the WorkOS user id linked to the signed-in frontend user.
     * When the plugin is not usable (not configured, not signed in, or
     * not linked) a rendered response is returned instead.
     *
     * @return array{response: ?ResponseInterface, workosUserId: string, displayName: string}
     */
    protected function resolveLinkedWorkosContext(): array
    {
        $frontendUser = $this->request->getAttribute('frontend.user');
        $isLoggedIn = $this->isFrontendUserLoggedIn();

        if (!$this->configuration->isFrontendReady() || !$isLoggedIn || !$frontendUser instanceof FrontendUserAuthentication) {
            $this->view->assignMultiple([
                'configured' => $this->configuration->isFrontendReady(),
                'isLoggedIn' => $isLoggedIn,
            ]);
            return ['response' => $this->htmlResponse(), 'workosUserId' => '', 'displayName' => ''];
        }

        $identity = $this->identityService->findIdentityByLocalUser(
            'frontend',
            'fe_users',
            MixedCaster::int($frontendUser->user['uid'] ?? null)
        );

        $workosUserId = is_array($identity) ? MixedCaster::string($identity['workos_user_id'] ?? null) : '';
        if ($workosUserId === '') {
            $this->view->assignMultiple([
                'configured' => true,
                'isLoggedIn' => true,
                'noWorkosLink' => true,
            ]);
            return ['response' => $this->htmlResponse(), 'workosUserId' => '', 'displayName' => ''];
        }

        $displayName = MixedCaster::string(
            $frontendUser->user['name'] ?? $frontendUser->user['username'] ?? $frontendUser->user['email'] ?? null
        );

        $this->view->assignMultiple([
            'configured' => true,
            'isLoggedIn' => true,
            'displayName' => $displayName,
            'workosUserId' => $workosUserId,
        ]);

        return ['response' => null, 'workosUserId' => $workosUserId, 'displayName' => $displayName];
    }

    protected function hasValidRequestToken(): bool
    {
        return $this->requestTokenService->validate(static::REQUEST_TOKEN_SCOPE);
    }

    protected function getFrontendUser(): FrontendUserAuthentication
    {
        $frontendUser = $this->request->getAttribute('frontend.user');
        if (!$frontendUser instanceof FrontendUserAuthentication) {
            throw new \RuntimeException('No frontend user session available.', 1744277820);
        }
        return $frontendUser;
    }

    protected function setFlash(string $type, string $message): void
    {
        $this->getFrontendUser()->setAndSaveSessionData(static::SESSION_FLASH, [
            'type' => $type,
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function consumeFlash(): ?array
    {
        $flash = $this->getFrontendUser()->getSessionData(static::SESSION_FLASH);
        if (!is_array($flash) || !isset($flash['message']) || $flash['message'] === '') {
            return null;
        }
        $this->getFrontendUser()->setAndSaveSessionData(static::SESSION_FLASH, null);
        $keyed = [];
        foreach ($flash as $key => $value) {
            $keyed[(string)$key] = $value;
        }
        return $keyed;
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
