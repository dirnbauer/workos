<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use Webconsulting\WorkosAuth\Authentication\WorkosTypo3AuthenticationService;
use Webconsulting\WorkosAuth\Controller\Frontend\AccountController;
use Webconsulting\WorkosAuth\Controller\Frontend\LoginController;
use Webconsulting\WorkosAuth\Controller\Frontend\TeamController;
use Webconsulting\WorkosAuth\LoginProvider\WorkosBackendLoginProvider;

(static function (): void {
    // Single-use state of multi-step login flows (see StateService); 10 minute TTL.
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['workos_auth_state'] ??= [
        'frontend' => VariableFrontend::class,
        'backend' => SimpleFileBackend::class,
        'groups' => ['system'],
        'options' => ['defaultLifetime' => 600],
    ];

    // Every plugin action depends on the frontend session (login state, CSRF
    // tokens, flash messages), so all actions are registered as non-cacheable.
    $plugins = [
        'Login' => [LoginController::class => 'show,signUp,signUpSubmit,passwordAuth,magicAuthSend,magicAuthCode,magicAuthVerify,verifyEmail,verifyEmailSubmit,verifyEmailResend'],
        'Account' => [AccountController::class => 'dashboard,updateProfile,changePassword,startMfaEnrollment,verifyMfaEnrollment,cancelMfaEnrollment,deleteFactor,revokeSession'],
        'Team' => [TeamController::class => 'dashboard,invite,resendInvitation,revokeInvitation,launchPortal'],
    ];
    foreach ($plugins as $pluginName => $controllerActions) {
        ExtensionUtility::configurePlugin('WorkosAuth', $pluginName, $controllerActions, $controllerActions);
    }

    ExtensionManagementUtility::addService(
        'workos_auth',
        'auth',
        WorkosTypo3AuthenticationService::class,
        [
            'title' => 'WorkOS TYPO3 Authentication Bridge',
            'description' => 'Authenticates TYPO3 FE and BE users after a successful WorkOS login flow.',
            'subtype' => 'getUserBE,getUserFE,authUserBE,authUserFE,processLoginDataBE,processLoginDataFE',
            'available' => true,
            'priority' => 85,
            'quality' => 80,
            'os' => '',
            'exec' => '',
            'className' => WorkosTypo3AuthenticationService::class,
        ]
    );

    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['backend']['loginProviders'][WorkosBackendLoginProvider::IDENTIFIER] = [
        'provider' => WorkosBackendLoginProvider::class,
        'sorting' => 60,
        'iconIdentifier' => 'workos-auth-logo',
        'label' => 'workos_auth.messages:loginprovider.label',
    ];
})();
