<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

// The three content elements live in their own "WorkOS" CType group with
// branded icons instead of the generic "Plugins" bucket.
$plugins = [
    'Login' => 'workos-auth-logo',
    'Account' => 'workos-auth-account',
    'Team' => 'workos-auth-team',
];
foreach ($plugins as $pluginName => $iconIdentifier) {
    $key = strtolower($pluginName);
    ExtensionUtility::registerPlugin(
        'WorkosAuth',
        $pluginName,
        'workos_auth.messages:plugin.' . $key . '.title',
        $iconIdentifier,
        'workos',
        'workos_auth.messages:plugin.' . $key . '.description',
    );
    $GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['workosauth_' . $key] = $iconIdentifier;
}

ExtensionManagementUtility::addTcaSelectItemGroup(
    'tt_content',
    'CType',
    'workos',
    'workos_auth.messages:plugin.group.title',
);
