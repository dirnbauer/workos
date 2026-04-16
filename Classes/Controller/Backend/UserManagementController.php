<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
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
use WebConsulting\WorkosAuth\Service\WorkosClientFactory;
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
                $status['organizationId'],
                $status['workosUserId'],
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
        $payload = (array)($request->getParsedBody() ?? []);
        if (!$this->isValidToken((string)($payload['csrfToken'] ?? ''))) {
            $this->flash($this->translate('error.csrfTokenInvalid'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $status = $this->resolveStatus();
        if (($status['workosUserId'] ?? '') === '') {
            $this->flash($status['message'] ?? $this->translate('module.users.error.noWorkosIdentity'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $organizationId = trim((string)($payload['organizationId'] ?? ''));
        if ($organizationId === '') {
            $this->flash($this->translate('module.users.error.missingOrganization'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        try {
            $this->workosClientFactory->createUserManagement()->createOrganizationMembership(
                $status['workosUserId'],
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
        $payload = (array)($request->getParsedBody() ?? []);
        if (!$this->isValidToken((string)($payload['csrfToken'] ?? ''))) {
            $this->flash($this->translate('error.csrfTokenInvalid'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $status = $this->resolveStatus();
        if (($status['workosUserId'] ?? '') === '') {
            $this->flash($status['message'] ?? $this->translate('module.users.error.noWorkosIdentity'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            $name = $this->suggestOrganizationName();
        }
        if ($name === '') {
            $this->flash($this->translate('module.users.error.missingOrganizationName'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        try {
            $organization = $this->workosClientFactory->createOrganizations()->createOrganization($name);
            $organizationId = (string)($organization->id ?? '');
            if ($organizationId === '') {
                throw new \RuntimeException('WorkOS did not return an organization id.', 1744320000);
            }

            $this->workosClientFactory->createUserManagement()->createOrganizationMembership(
                $status['workosUserId'],
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
        $beUserUid = (int)($beUser->user['uid'] ?? 0);
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

        $workosUserId = (string)$identity['workos_user_id'];
        $email = (string)($identity['email'] ?? '');
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

            $memberships = $result->organization_memberships ?? $result->data ?? [];
            if (!is_iterable($memberships)) {
                $memberships = [];
            }
            foreach ($memberships as $membership) {
                $organizationId = (string)($membership->organizationId ?? $membership->organization_id ?? '');
                if ($organizationId !== '') {
                    return $organizationId;
                }
            }
        } catch (\Throwable $exception) {
            $this->logger?->warning('WorkOS organization lookup failed: ' . $exception->getMessage());
        }

        return (string)($this->configuration->getAuthkitOrganizationId() ?? '');
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
            $rows = $result->data ?? [];
            if (!is_iterable($rows)) {
                return [];
            }

            $organizations = [];
            foreach ($rows as $organization) {
                $id = (string)($organization->id ?? '');
                $name = (string)($organization->name ?? $id);
                if ($id === '') {
                    continue;
                }
                $organizations[] = ['id' => $id, 'name' => $name];
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
        $sitename = trim((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? ''));
        if ($sitename !== '') {
            return $sitename;
        }

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
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

    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        return (string)$languageService->label('workos_auth.messages:' . $key, $arguments, $key);
    }
}
