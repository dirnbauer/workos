<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

(static function (): void {
    ExtensionUtility::configurePlugin(
        'WorkosAuth',
        'Login',
        [
            \WebConsulting\WorkosAuth\Controller\Frontend\LoginController::class => 'show',
        ],
        [
            \WebConsulting\WorkosAuth\Controller\Frontend\LoginController::class => 'show',
        ]
    );

    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['backend']['loginProviders'][1744276800] = [
        'provider' => \WebConsulting\WorkosAuth\LoginProvider\WorkosBackendLoginProvider::class,
        'sorting' => 60,
        'iconIdentifier' => 'workos-auth-logo',
        'label' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang.xlf:loginprovider.label',
    ];
})();
