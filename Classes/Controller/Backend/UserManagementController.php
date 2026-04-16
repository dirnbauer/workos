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
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
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
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle($this->translate('module.users.title'));

        $status = $this->resolveStatus();

        $moduleTemplate->assignMultiple([
            'configured' => $this->configuration->isBackendReady(),
            'tokenUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_users.token'),
            'setupUri' => (string)$this->uriBuilder->buildUriFromRoute('workos_setup'),
            'status' => $status,
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

    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        return (string)$languageService->label('workos_auth.messages:' . $key, $arguments, $key);
    }
}
