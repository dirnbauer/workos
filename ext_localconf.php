<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

(static function (): void {
    /** @var array<string, mixed> $confVars */
    $confVars = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
    $sys = is_array($confVars['SYS'] ?? null) ? $confVars['SYS'] : [];
    $caching = is_array($sys['caching'] ?? null) ? $sys['caching'] : [];
    $cacheConfigurations = is_array($caching['cacheConfigurations'] ?? null) ? $caching['cacheConfigurations'] : [];

    if (!isset($cacheConfigurations['workos_auth_state'])) {
        $cacheConfigurations['workos_auth_state'] = [
            'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
            'backend' => \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class,
            'groups' => ['system'],
            'options' => [
                'defaultLifetime' => 600,
            ],
        ];

        $caching['cacheConfigurations'] = $cacheConfigurations;
        $sys['caching'] = $caching;
        $confVars['SYS'] = $sys;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
    }

    ExtensionUtility::configurePlugin(
        'WorkosAuth',
        'Login',
        [
            \WebConsulting\WorkosAuth\Controller\Frontend\LoginController::class => 'show,signUp,signUpSubmit,passwordAuth,magicAuthSend,magicAuthCode,magicAuthVerify,verifyEmail,verifyEmailSubmit,verifyEmailResend',
        ],
        [
            \WebConsulting\WorkosAuth\Controller\Frontend\LoginController::class => 'show,signUp,signUpSubmit,passwordAuth,magicAuthSend,magicAuthCode,magicAuthVerify,verifyEmail,verifyEmailSubmit,verifyEmailResend',
        ]
    );

    ExtensionUtility::configurePlugin(
        'WorkosAuth',
        'Account',
        [
            \WebConsulting\WorkosAuth\Controller\Frontend\AccountController::class => 'dashboard,updateProfile,changePassword,startMfaEnrollment,verifyMfaEnrollment,cancelMfaEnrollment,deleteFactor,revokeSession',
        ],
        [
            \WebConsulting\WorkosAuth\Controller\Frontend\AccountController::class => 'updateProfile,changePassword,startMfaEnrollment,verifyMfaEnrollment,cancelMfaEnrollment,deleteFactor,revokeSession',
        ]
    );

    ExtensionUtility::configurePlugin(
        'WorkosAuth',
        'Team',
        [
            \WebConsulting\WorkosAuth\Controller\Frontend\TeamController::class => 'dashboard,invite,resendInvitation,revokeInvitation,launchPortal',
        ],
        [
            \WebConsulting\WorkosAuth\Controller\Frontend\TeamController::class => 'invite,resendInvitation,revokeInvitation,launchPortal',
        ]
    );

    ExtensionManagementUtility::addService(
        'workos_auth',
        'auth',
        \WebConsulting\WorkosAuth\Authentication\WorkosTypo3AuthenticationService::class,
        [
            'title' => 'WorkOS TYPO3 Authentication Bridge',
            'description' => 'Authenticates TYPO3 FE and BE users after a successful WorkOS login flow.',
            'subtype' => 'getUserBE,getUserFE,authUserBE,authUserFE,processLoginDataBE,processLoginDataFE',
            'available' => true,
            'priority' => 85,
            'quality' => 80,
            'os' => '',
            'exec' => '',
            'className' => \WebConsulting\WorkosAuth\Authentication\WorkosTypo3AuthenticationService::class,
        ]
    );

    /** @var array<string, mixed> $confVars */
    $confVars = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
    $extconf = is_array($confVars['EXTCONF'] ?? null) ? $confVars['EXTCONF'] : [];
    $backend = is_array($extconf['backend'] ?? null) ? $extconf['backend'] : [];
    $providers = is_array($backend['loginProviders'] ?? null) ? $backend['loginProviders'] : [];

    $providers[1744276800] = [
        'provider' => \WebConsulting\WorkosAuth\LoginProvider\WorkosBackendLoginProvider::class,
        'sorting' => 60,
        'iconIdentifier' => 'workos-auth-logo',
        'label' => 'workos_auth.messages:loginprovider.label',
    ];

    $backend['loginProviders'] = $providers;
    $extconf['backend'] = $backend;
    $confVars['EXTCONF'] = $extconf;
    $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
})();
