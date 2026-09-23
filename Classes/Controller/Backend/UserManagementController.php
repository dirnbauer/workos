<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\RequestTokenService;
use Webconsulting\WorkosAuth\Security\SecretRedactor;
use Webconsulting\WorkosAuth\Service\IdentityService;
use Webconsulting\WorkosAuth\Service\LabelTranslator;
use Webconsulting\WorkosAuth\Service\PathUtility;
use Webconsulting\WorkosAuth\Service\RequestBody;
use Webconsulting\WorkosAuth\Service\Typo3SessionService;
use Webconsulting\WorkosAuth\Service\WorkosClientFactory;
use WorkOS\Exception\ConflictException;
use WorkOS\Resource\Organization;
use WorkOS\Resource\OrganizationMembershipStatus;
use WorkOS\Resource\UserOrganizationMembership;
use WorkOS\Resource\WidgetSessionTokenScopes;
use WorkOS\Service\RoleSingle;

/**
 * Backend module "WorkOS > User Management": embeds the WorkOS User
 * Management widget (https://workos.com/docs/widgets/user-management).
 *
 * The widget renders client-side from the bundled JavaScript; the server only
 * mints a short-lived widget token. A backend user who is not yet a member of
 * a WorkOS organization gets a self-service screen to join or create one.
 *
 * A backend user who signed in with the TYPO3 password and was never linked
 * to a WorkOS user gets a "Sign in with WorkOS" action that starts the
 * backend WorkOS login and returns to this module.
 *
 * @phpstan-type WidgetStatus array{canLoadWidget: bool, message?: string, needsWorkosLogin?: bool, workosUserId?: string, organizationId?: string, email?: string}
 */
#[Autoconfigure(public: true)]
final class UserManagementController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const string TOKEN_REQUEST_SCOPE = 'workos/backend/users/token';
    private const string JOIN_REQUEST_SCOPE = 'workos/backend/users/join';
    private const string CREATE_ORGANIZATION_REQUEST_SCOPE = 'workos/backend/users/create-organization';

    public function __construct(
        private readonly ModulePageFactory $modulePageFactory,
        private readonly WorkosConfiguration $configuration,
        private readonly WorkosClientFactory $workosClientFactory,
        private readonly IdentityService $identityService,
        private readonly UriBuilder $uriBuilder,
        private readonly LabelTranslator $translator,
        private readonly PageRenderer $pageRenderer,
        private readonly RequestTokenService $requestTokenService,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $status = $this->resolveStatus($request);
        $availableOrganizations = !$status['canLoadWidget'] && ($status['workosUserId'] ?? '') !== ''
            ? $this->listAvailableOrganizations()
            : [];

        $moduleTemplate = $this->modulePageFactory->create($request, 'workos_users', 'module.users.title');
        $moduleTemplate->assignMultiple([
            'configured' => $this->configuration->isBackendReady(),
            'tokenUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_users.token'),
            'joinUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_users.join'),
            'createOrgUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_users.createOrganization'),
            'setupUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_setup'),
            'status' => $status,
            'workosLoginUri' => ($status['needsWorkosLogin'] ?? false) ? $this->workosLoginUri($request) : '',
            'availableOrganizations' => $availableOrganizations,
            'suggestedOrganizationName' => $this->suggestOrganizationName($request),
            'requestTokenName' => RequestToken::PARAM_NAME,
            'tokenRequestTokenValue' => $this->requestTokenService->createHashed(self::TOKEN_REQUEST_SCOPE),
            'joinRequestTokenValue' => $this->requestTokenService->createHashed(self::JOIN_REQUEST_SCOPE),
            'createOrganizationRequestTokenValue' => $this->requestTokenService->createHashed(self::CREATE_ORGANIZATION_REQUEST_SCOPE),
        ]);

        if ($status['canLoadWidget']) {
            $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
                JavaScriptModuleInstruction::create('@webconsulting/workos-auth/user-management-widget.js')
            );
        }

        return $moduleTemplate->renderResponse('Backend/UserManagement/Index');
    }

    /**
     * Mints the short-lived widget token after the module page has loaded.
     * POST only, so the response is never cached or triggerable via GET.
     */
    public function tokenAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!self::isAdmin($request)) {
            return new JsonResponse(['error' => $this->translator->translate('module.users.error.noSession')], 403);
        }
        if (!$this->requestTokenService->validate(self::TOKEN_REQUEST_SCOPE)) {
            return new JsonResponse(['error' => $this->translator->translate('error.csrfTokenInvalid')], 400);
        }

        $status = $this->resolveStatus($request);
        if (!$status['canLoadWidget']) {
            return new JsonResponse(['error' => $status['message'] ?? $this->translator->translate('module.users.error.generic')], 400);
        }

        try {
            $this->registerWidgetCorsOrigins($request);
            $response = $this->workosClientFactory->client()->widgets()->createToken(
                organizationId: $status['organizationId'] ?? '',
                userId: $status['workosUserId'] ?? '',
                scopes: [WidgetSessionTokenScopes::WidgetsUsersTableManage],
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS widget token error: ' . SecretRedactor::redact($exception->getMessage()));
            return new JsonResponse(['error' => $this->translator->translate('module.users.error.tokenFailed')], 502);
        }

        return new JsonResponse(['token' => $response->token]);
    }

    /**
     * Assign the current backend user to an existing WorkOS organization.
     */
    public function joinAction(ServerRequestInterface $request): ResponseInterface
    {
        $workosUserId = $this->authorizeMutation($request, self::JOIN_REQUEST_SCOPE);
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        $organizationId = RequestBody::fromRequest($request)->trimmedString('organizationId');
        if ($organizationId === '') {
            return $this->flashAndRedirect($this->translator->translate('module.users.error.missingOrganization'), ContextualFeedbackSeverity::ERROR);
        }

        try {
            $this->addAsAdmin($workosUserId, $organizationId);
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS join organization failed: ' . SecretRedactor::redact($exception->getMessage()));
            return $this->flashAndRedirect(
                $this->translator->translate('module.users.error.joinFailed') . ' ' . $exception->getMessage(),
                ContextualFeedbackSeverity::ERROR
            );
        }

        return $this->flashAndRedirect($this->translator->translate('module.users.message.joined'), ContextualFeedbackSeverity::OK);
    }

    /**
     * Create a WorkOS organization and assign the current backend user as admin.
     */
    public function createOrganizationAction(ServerRequestInterface $request): ResponseInterface
    {
        $workosUserId = $this->authorizeMutation($request, self::CREATE_ORGANIZATION_REQUEST_SCOPE);
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        $name = RequestBody::fromRequest($request)->trimmedString('name');
        $name = $name !== '' ? $name : $this->suggestOrganizationName($request);
        if ($name === '') {
            return $this->flashAndRedirect($this->translator->translate('module.users.error.missingOrganizationName'), ContextualFeedbackSeverity::ERROR);
        }

        try {
            $organization = $this->workosClientFactory->client()->organizations()->createOrganization($name);
            if ($organization->id === '') {
                throw new \RuntimeException('WorkOS did not return an organization id.', 1744320000);
            }
            $this->addAsAdmin($workosUserId, $organization->id);
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS create organization failed: ' . SecretRedactor::redact($exception->getMessage()));
            return $this->flashAndRedirect(
                $this->translator->translate('module.users.error.createFailed') . ' ' . $exception->getMessage(),
                ContextualFeedbackSeverity::ERROR
            );
        }

        return $this->flashAndRedirect(
            $this->translator->translate('module.users.message.createdAndJoined', ['organization' => $name]),
            ContextualFeedbackSeverity::OK
        );
    }

    /**
     * Shared guard of the mutating POST routes: admin, valid request token,
     * linked WorkOS identity. Returns the WorkOS user id, or the redirect
     * response that ends the request.
     */
    private function authorizeMutation(ServerRequestInterface $request, string $requestTokenScope): ResponseInterface|string
    {
        if (!self::isAdmin($request)) {
            return $this->flashAndRedirect($this->translator->translate('module.users.error.noSession'), ContextualFeedbackSeverity::ERROR);
        }
        if (!$this->requestTokenService->validate($requestTokenScope)) {
            return $this->flashAndRedirect($this->translator->translate('error.csrfTokenInvalid'), ContextualFeedbackSeverity::ERROR);
        }

        $status = $this->resolveStatus($request);
        $workosUserId = $status['workosUserId'] ?? '';
        if ($workosUserId === '') {
            return $this->flashAndRedirect(
                $status['message'] ?? $this->translator->translate('module.users.error.noWorkosIdentity'),
                ContextualFeedbackSeverity::ERROR
            );
        }

        return $workosUserId;
    }

    private function addAsAdmin(string $workosUserId, string $organizationId): void
    {
        $this->workosClientFactory->client()->organizationMembership()->createOrganizationMembership(
            $workosUserId,
            $organizationId,
            new RoleSingle('admin'),
        );
    }

    /**
     * @return WidgetStatus
     */
    private function resolveStatus(ServerRequestInterface $request): array
    {
        if (!$this->configuration->isBackendReady()) {
            return ['canLoadWidget' => false, 'message' => $this->translator->translate('module.users.error.notConfigured')];
        }

        $beUser = self::backendUser($request);
        $beUserUid = $beUser instanceof BackendUserAuthentication ? MixedCaster::int($beUser->user['uid'] ?? null) : 0;
        if (!$beUser instanceof BackendUserAuthentication || $beUserUid <= 0) {
            return ['canLoadWidget' => false, 'message' => $this->translator->translate('module.users.error.noSession')];
        }

        $identity = $this->identityService->findIdentityByLocalUser(LoginContext::Backend, $beUserUid);
        $workosUserId = MixedCaster::string($beUser->getSessionData(Typo3SessionService::SESSION_WORKOS_USER_ID));
        if ($workosUserId === '') {
            $workosUserId = MixedCaster::string($identity['workos_user_id'] ?? null);
        }
        if ($workosUserId === '') {
            // Signed in with the TYPO3 password and never linked: the widget
            // acts on behalf of a WorkOS user, so one WorkOS sign-in is needed.
            return [
                'canLoadWidget' => false,
                'needsWorkosLogin' => true,
                'message' => $this->translator->translate('module.users.noWorkosSession.title'),
                'email' => MixedCaster::string($beUser->user['email'] ?? null),
            ];
        }

        $email = MixedCaster::string($identity['email'] ?? null);
        $organizationId = $this->resolveOrganizationId($workosUserId);
        if ($organizationId === '') {
            return [
                'canLoadWidget' => false,
                'message' => $this->translator->translate('module.users.error.noOrganization'),
                'workosUserId' => $workosUserId,
                'email' => $email,
            ];
        }

        return ['canLoadWidget' => true, 'workosUserId' => $workosUserId, 'organizationId' => $organizationId, 'email' => $email];
    }

    /**
     * Starts the backend WorkOS login and comes back to this module: the
     * backend entry point opens the module through its `redirect` parameter,
     * so the target needs no module token of the session being replaced.
     * The login endpoint validates `returnTo` (same-host paths only) and
     * binds the flow to a single-use state cookie.
     */
    private function workosLoginUri(ServerRequestInterface $request): string
    {
        $backendBasePath = PathUtility::guessBackendBasePath($request->getUri()->getPath());
        $query = ['returnTo' => PathUtility::joinBaseAndPath($backendBasePath, '/main') . '?redirect=workos_users'];
        $email = trim(MixedCaster::string(self::backendUser($request)?->user['email'] ?? null));
        if ($email !== '') {
            $query['login_hint'] = $email;
        }

        return PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendLoginPath())
            . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The backend user of the request. TYPO3 keeps it in $GLOBALS['BE_USER'];
     * a `backend.user` request attribute (sub-requests, tests) wins.
     */
    private static function backendUser(ServerRequestInterface $request): ?BackendUserAuthentication
    {
        $backendUser = $request->getAttribute('backend.user') ?? $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    /**
     * First active organization membership of the user, else the configured
     * default `authkitOrganizationId`, else ''.
     */
    private function resolveOrganizationId(string $workosUserId): string
    {
        try {
            $result = $this->workosClientFactory->client()->organizationMembership()->listOrganizationMemberships(
                userId: $workosUserId,
                limit: 10,
            );
            foreach ($result->data as $membership) {
                if ($membership instanceof UserOrganizationMembership
                    && $membership->status === OrganizationMembershipStatus::Active
                    && $membership->organizationId !== ''
                ) {
                    return $membership->organizationId;
                }
            }
        } catch (\Throwable $exception) {
            $this->logger?->warning('WorkOS organization lookup failed: ' . SecretRedactor::redact($exception->getMessage()));
        }

        return $this->configuration->getAuthkitOrganizationId() ?? '';
    }

    /**
     * The widget calls the WorkOS API from the browser, so the backend origin
     * must be an allowed CORS origin in WorkOS.
     */
    private function registerWidgetCorsOrigins(ServerRequestInterface $request): void
    {
        if (!$this->configuration->shouldAutoRegisterWidgetCorsOrigins()) {
            return;
        }

        $origins = array_unique([...$this->configuration->getWidgetCorsOrigins(), PathUtility::originFromRequest($request)]);
        $userManagement = $this->workosClientFactory->client()->userManagement();
        foreach ($origins as $origin) {
            if ($origin === '') {
                continue;
            }
            try {
                $userManagement->createCorsOrigin($origin);
            } catch (ConflictException) {
                // Already registered.
            } catch (\Throwable $exception) {
                $this->logger?->warning(sprintf(
                    'WorkOS widget CORS origin registration failed for "%s": %s',
                    $origin,
                    SecretRedactor::redact($exception->getMessage())
                ));
            }
        }
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function listAvailableOrganizations(): array
    {
        try {
            $result = $this->workosClientFactory->client()->organizations()->listOrganizations(limit: 50);
        } catch (\Throwable $exception) {
            $this->logger?->warning('WorkOS list organizations failed: ' . SecretRedactor::redact($exception->getMessage()));
            return [];
        }

        $organizations = [];
        foreach ($result->data as $organization) {
            if ($organization instanceof Organization && $organization->id !== '') {
                $organizations[] = ['id' => $organization->id, 'name' => $organization->name];
            }
        }
        usort($organizations, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $organizations;
    }

    private function suggestOrganizationName(ServerRequestInterface $request): string
    {
        $sitename = trim(MixedCaster::string($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? null));
        if ($sitename !== '') {
            return $sitename;
        }

        $host = trim($request->getUri()->getHost());

        return $host !== '' ? $host : 'TYPO3 Workspace';
    }

    private function flashAndRedirect(string $body, ContextualFeedbackSeverity $severity): ResponseInterface
    {
        $this->modulePageFactory->flash($body, $severity, $this->translator->translate('module.users.flashTitle'));

        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('workos_users'));
    }

    /**
     * Defence in depth next to the module's `access => 'admin'` gate: even a
     * re-registered route must not mint widget tokens or mutate WorkOS data
     * for non-admins.
     */
    private static function isAdmin(ServerRequestInterface $request): bool
    {
        return self::backendUser($request)?->isAdmin() ?? false;
    }
}
