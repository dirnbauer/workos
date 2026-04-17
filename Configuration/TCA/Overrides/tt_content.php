<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

ExtensionUtility::registerPlugin(
    'WorkosAuth',
    'Login',
    'workos_auth.messages:plugin.login.title',
    'workos-auth-logo'
);

ExtensionUtility::registerPlugin(
    'WorkosAuth',
    'Account',
    'workos_auth.messages:plugin.account.title',
    'workos-auth-account'
);

ExtensionUtility::registerPlugin(
    'WorkosAuth',
    'Team',
    'workos_auth.messages:plugin.team.title',
    'workos-auth-team'
);
