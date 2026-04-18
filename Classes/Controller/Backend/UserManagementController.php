<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Service\IdentityService;
use WebConsulting\WorkosAuth\Service\RequestBody;
use WebConsulting\WorkosAuth\Service\WorkosClientFactory;
use WorkOS\Resource\Organization;
use WorkOS\Resource\OrganizationMembership;
use WorkOS\Resource\WidgetScope;

/**
 * Backend module that embeds the WorkOS "User Management" Widget.
 *
 * @see https://workos.com/docs/widgets/user-management
 *
 * The widget is rendered client-side from WorkOS's CDN bundle. We only
 * mint a short-lived widget token on the server and hand it to the
 * JavaScript that mounts the web component.
 *
 * When the current backend user is not yet a member of any WorkOS
 * organization we render a self-service screen that lets an admin pick
 * an existing organization or create a new one without leaving TYPO3.
 */
final class UserManagementController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly WorkosConfiguration $configuration,
        private readonly WorkosClientFactory $workosClientFactory,
        private readonly IdentityService $identityService,
        private readonly UriBuilder $uriBuilder,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly FlashMessageService $flashMessageService,
        private readonly FormProtectionFactory $formProtectionFactory,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle($this->translate('module.users.title'));

        $status = $this->resolveStatus();
        $availableOrganizations = [];
        if (!$status['canLoadWidget'] && ($status['workosUserId'] ?? '') !== '') {
            $availableOrganizations = $this->listAvailableOrganizations();
        }

        $moduleTemplate->assignMultiple([
            'configured' => $this->configuration->isBackendReady(),
            'tokenUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_users.token'),
            'joinUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_users.join'),
            'createOrgUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_users.createOrganization'),
            'setupUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_setup'),
            'status' => $status,
            'availableOrganizations' => $availableOrganizations,
            'suggestedOrganizationName' => $this->suggestOrganizationName(),
            'csrfToken' => $this->generateToken(),
        ]);

        if ($status['canLoadWidget']) {
            $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
                JavaScriptModuleInstruction::create('@webconsulting/workos-auth/user-management-widget.js')
            );
        }

        return $moduleTemplate->renderResponse('Backend/UserManagement/Index');
    }

    /**
     * Returns a short-lived widget token. Called by the browser after
     * the module page is loaded. Exposed as POST so the response is
     * not cached and cannot be triggered via simple image/GET requests.
     */
    public function tokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $status = $this->resolveStatus();
        if (!$status['canLoadWidget']) {
            return new JsonResponse([
                'error' => $status['message'] ?? $this->translate('module.users.error.generic'),
            ], 400);
        }

        try {
            $widgets = $this->workosClientFactory->createWidgets();
            $response = $widgets->getToken(
                $status['organizationId'] ?? '',
                $status['workosUserId'] ?? '',
                [WidgetScope::UsersTableManage],
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS widget token error: ' . $exception->getMessage());
            return new JsonResponse([
                'error' => $this->translate('module.users.error.tokenFailed'),
            ], 502);
        }

        return new JsonResponse([
            'token' => $response->token ?? '',
        ]);
    }

    /**
     * Assign the current backend user to an existing WorkOS organization.
     */
    public function joinAction(ServerRequestInterface $request): ResponseInterface
    {
        $payload = RequestBody::fromRequest($request);
        if (!$this->isValidToken($payload->string('csrfToken'))) {
            $this->flash($this->translate('error.csrfTokenInvalid'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $status = $this->resolveStatus();
        $workosUserId = $status['workosUserId'] ?? '';
        if ($workosUserId === '') {
            $this->flash($status['message'] ?? $this->translate('module.users.error.noWorkosIdentity'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $organizationId = $payload->trimmedString('organizationId');
        if ($organizationId === '') {
            $this->flash($this->translate('module.users.error.missingOrganization'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        try {
            $this->workosClientFactory->createUserManagement()->createOrganizationMembership(
                $workosUserId,
                $organizationId,
                'admin',
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS join organization failed: ' . $exception->getMessage());
            $this->flash(sprintf('%s %s', $this->translate('module.users.error.joinFailed'), $exception->getMessage()), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $this->flash($this->translate('module.users.message.joined'), ContextualFeedbackSeverity::OK);
        return $this->redirectToIndex();
    }

    /**
     * Create a new WorkOS organization and assign the current backend user as admin.
     */
    public function createOrganizationAction(ServerRequestInterface $request): ResponseInterface
    {
        $payload = RequestBody::fromRequest($request);
        if (!$this->isValidToken($payload->string('csrfToken'))) {
            $this->flash($this->translate('error.csrfTokenInvalid'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $status = $this->resolveStatus();
        $workosUserId = $status['workosUserId'] ?? '';
        if ($workosUserId === '') {
            $this->flash($status['message'] ?? $this->translate('module.users.error.noWorkosIdentity'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $name = $payload->trimmedString('name');
        if ($name === '') {
            $name = $this->suggestOrganizationName();
        }
        if ($name === '') {
            $this->flash($this->translate('module.users.error.missingOrganizationName'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        try {
            $organization = $this->workosClientFactory->createOrganizations()->createOrganization($name);
            $organizationId = $organization->id ?? '';
            if ($organizationId === '') {
                throw new \RuntimeException('WorkOS did not return an organization id.', 1744320000);
            }

            $this->workosClientFactory->createUserManagement()->createOrganizationMembership(
                $workosUserId,
                $organizationId,
                'admin',
            );
        } catch (\Throwable $exception) {
            $this->logger?->error('WorkOS create organization failed: ' . $exception->getMessage());
            $this->flash(sprintf('%s %s', $this->translate('module.users.error.createFailed'), $exception->getMessage()), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $this->flash(sprintf($this->translate('module.users.message.createdAndJoined'), $name), ContextualFeedbackSeverity::OK);
        return $this->redirectToIndex();
    }

    /**
     * @return array{
     *     canLoadWidget: bool,
     *     message?: string,
     *     workosUserId?: string,
     *     organizationId?: string,
     *     email?: string,
     * }
     */
    private function resolveStatus(): array
    {
        if (!$this->configuration->isBackendReady()) {
            return [
                'canLoadWidget' => false,
                'message' => $this->translate('module.users.error.notConfigured'),
            ];
        }

        $beUser = $GLOBALS['BE_USER'] ?? null;
        $beUserUid = 0;
        if ($beUser instanceof BackendUserAuthentication && is_array($beUser->user)) {
            $uid = $beUser->user['uid'] ?? null;
            if (is_int($uid) || (is_string($uid) && ctype_digit($uid))) {
                $beUserUid = (int)$uid;
            }
        }
        if ($beUserUid === 0) {
            return [
                'canLoadWidget' => false,
                'message' => $this->translate('module.users.error.noSession'),
            ];
        }

        $identity = $this->identityService->findIdentityByLocalUser('backend', 'be_users', $beUserUid);
        if ($identity === null || ($identity['workos_user_id'] ?? '') === '') {
            return [
                'canLoadWidget' => false,
                'message' => $this->translate('module.users.error.noWorkosIdentity'),
            ];
        }

        $workosUserId = self::stringFromMixed($identity['workos_user_id']);
        $email = self::stringFromMixed($identity['email'] ?? null);
        $organizationId = $this->resolveOrganizationId($workosUserId);

        if ($organizationId === '') {
            return [
                'canLoadWidget' => false,
                'message' => $this->translate('module.users.error.noOrganization'),
                'workosUserId' => $workosUserId,
                'email' => $email,
            ];
        }

        return [
            'canLoadWidget' => true,
            'workosUserId' => $workosUserId,
            'organizationId' => $organizationId,
            'email' => $email,
        ];
    }

    /**
     * Find the organization to scope the widget to. Order of precedence:
     *   1. First active organization membership for this user.
     *   2. The default `authkitOrganizationId` from the extension config.
     */
    private function resolveOrganizationId(string $workosUserId): string
    {
        try {
            $userManagement = $this->workosClientFactory->createUserManagement();
            $result = $userManagement->listOrganizationMemberships(
                userId: $workosUserId,
                statuses: ['active'],
                limit: 10,
            );

            foreach ($result->data as $membership) {
                if ($membership instanceof OrganizationMembership) {
                    $organizationId = $membership->organizationId ?? '';
                    if ($organizationId !== '') {
                        return $organizationId;
                    }
                }
            }
        } catch (\Throwable $exception) {
            $this->logger?->warning('WorkOS organization lookup failed: ' . $exception->getMessage());
        }

        return $this->configuration->getAuthkitOrganizationId();
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function listAvailableOrganizations(): array
    {
        try {
            $result = $this->workosClientFactory->createOrganizations()->listOrganizations(
                limit: 50,
            );

            $organizations = [];
            foreach ($result->data as $organization) {
                if (!$organization instanceof Organization) {
                    continue;
                }
                $id = $organization->id ?? '';
                if ($id === '') {
                    continue;
                }
                $organizations[] = ['id' => $id, 'name' => $organization->name ?? $id];
            }
            usort($organizations, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
            return $organizations;
        } catch (\Throwable $exception) {
            $this->logger?->warning('WorkOS list organizations failed: ' . $exception->getMessage());
            return [];
        }
    }

    private function suggestOrganizationName(): string
    {
        $conf = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sitename = '';
        if (is_array($conf) && isset($conf['SYS']) && is_array($conf['SYS'])) {
            $sitename = trim(self::stringFromMixed($conf['SYS']['sitename'] ?? null));
        }
        if ($sitename !== '') {
            return $sitename;
        }

        $host = trim(self::stringFromMixed($_SERVER['HTTP_HOST'] ?? null));
        if ($host !== '') {
            return $host;
        }

        return 'TYPO3 Workspace';
    }

    private function redirectToIndex(): RedirectResponse
    {
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('workos_users'));
    }

    private function flash(string $body, ContextualFeedbackSeverity $severity): void
    {
        $this->flashMessageService
            ->getMessageQueueByIdentifier('workos-auth-users')
            ->addMessage(new FlashMessage($body, $this->translate('module.users.flashTitle'), $severity, true));
    }

    private function generateToken(): string
    {
        return $this->formProtectionFactory
            ->createForType('backend')
            ->generateToken('workosAuth', 'manageOrganizationMembership');
    }

    private function isValidToken(string $token): bool
    {
        return $this->formProtectionFactory
            ->createForType('backend')
            ->validateToken($token, 'workosAuth', 'manageOrganizationMembership');
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

    private static function stringFromMixed(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        return '';
    }
}
