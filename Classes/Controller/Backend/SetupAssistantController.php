<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Service\PathUtility;

final class SetupAssistantController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private WorkosConfiguration $configuration,
        private ExtensionConfiguration $extensionConfiguration,
        private SiteFinder $siteFinder,
        private FormProtectionFactory $formProtectionFactory,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $formValues = $this->configuration->all();
        $errors = [];
        $saved = false;

        if (strtoupper($request->getMethod()) === 'POST') {
            $parsedBody = $request->getParsedBody();
            $payload = is_array($parsedBody) ? $parsedBody : [];
            $formValues = $this->configuration->normalizeInput(is_array($payload['configuration'] ?? null) ? $payload['configuration'] : []);

            if (($payload['generateCookiePassword'] ?? '') === '1') {
                $formValues['cookiePassword'] = $this->generateCookiePassword();
            }

            if (!$this->isValidToken((string)($payload['csrfToken'] ?? ''))) {
                $errors['general'] = 'The form token is invalid. Reload the module and try again.';
            } else {
                $errors = $this->configuration->validate($formValues);
                if ($errors === []) {
                    $this->extensionConfiguration->set(WorkosConfiguration::EXTENSION_KEY, $formValues);
                    $saved = true;
                }
            }
        }

        $backendBasePath = PathUtility::guessBackendBasePath($request->getUri()->getPath());
        $backendUrls = [
            'login' => PathUtility::buildAbsoluteUrlFromRequest(
                $request,
                PathUtility::joinBaseAndPath($backendBasePath, (string)$formValues['backendLoginPath'])
            ),
            'callback' => PathUtility::buildAbsoluteUrlFromRequest(
                $request,
                PathUtility::joinBaseAndPath($backendBasePath, (string)$formValues['backendCallbackPath'])
            ),
            'success' => PathUtility::buildAbsoluteUrlFromRequest(
                $request,
                PathUtility::joinBaseAndPath($backendBasePath, (string)$formValues['backendSuccessPath'])
            ),
        ];

        $frontendSites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $baseUrl = rtrim((string)$site->getBase(), '/');
            $frontendSites[] = [
                'identifier' => $site->getIdentifier(),
                'baseUrl' => $baseUrl,
                'loginUrl' => PathUtility::joinBaseUrlAndPath($baseUrl, (string)$formValues['frontendLoginPath']),
                'callbackUrl' => PathUtility::joinBaseUrlAndPath($baseUrl, (string)$formValues['frontendCallbackPath']),
                'logoutUrl' => PathUtility::joinBaseUrlAndPath($baseUrl, (string)$formValues['frontendLogoutPath']),
            ];
        }

        $moduleTemplate->assignMultiple([
            'formValues' => $formValues,
            'errors' => $errors,
            'saved' => $saved,
            'csrfToken' => $this->generateToken(),
            'backendUrls' => $backendUrls,
            'frontendSites' => $frontendSites,
            'hasCredentials' => trim((string)$formValues['apiKey']) !== '' && trim((string)$formValues['clientId']) !== '',
            'cookiePasswordValid' => mb_strlen(trim((string)$formValues['cookiePassword'])) >= 32,
        ]);
        $moduleTemplate->setTitle('WorkOS Setup Assistant');

        return $moduleTemplate->renderResponse('Backend/SetupAssistant/Index');
    }

    private function generateCookiePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    private function generateToken(): string
    {
        return $this->formProtectionFactory
            ->createForType('backend')
            ->generateToken('workosAuth', 'saveConfiguration');
    }

    private function isValidToken(string $token): bool
    {
        return $this->formProtectionFactory
            ->createForType('backend')
            ->validateToken($token, 'workosAuth', 'saveConfiguration');
    }
}
