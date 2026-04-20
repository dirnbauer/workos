<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

// Register our three Content Elements under a dedicated "WorkOS" group
// instead of the generic "Plugins" bucket. Pattern mirrors EXT:news 14.x:
// pass the group key as the 5th argument of registerPlugin(), then assign
// the human-readable group label via TCA itemGroups. Per-CType icons are
// wired through typeicon_classes so record lists show the branded icon
// instead of the generic plugin glyph.

ExtensionUtility::registerPlugin(
    'WorkosAuth',
    'Login',
    'workos_auth.messages:plugin.login.title',
    'workos-auth-logo',
    'workos',
    'workos_auth.messages:plugin.login.description',
);

ExtensionUtility::registerPlugin(
    'WorkosAuth',
    'Account',
    'workos_auth.messages:plugin.account.title',
    'workos-auth-account',
    'workos',
    'workos_auth.messages:plugin.account.description',
);

ExtensionUtility::registerPlugin(
    'WorkosAuth',
    'Team',
    'workos_auth.messages:plugin.team.title',
    'workos-auth-team',
    'workos',
    'workos_auth.messages:plugin.team.description',
);

ExtensionManagementUtility::addTcaSelectItemGroup(
    'tt_content',
    'CType',
    'workos',
    'workos_auth.messages:plugin.group.title',
);

// Per-CType icon in record lists / page module. No core helper exists for
// $GLOBALS['TCA'][...]['ctrl']['typeicon_classes'], so we narrow the
// access path through local vars to keep PHPStan level max happy.
(static function (): void {
    /** @var array<string, mixed> $tca */
    $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
    $ttContent = is_array($tca['tt_content'] ?? null) ? $tca['tt_content'] : [];
    $ctrl = is_array($ttContent['ctrl'] ?? null) ? $ttContent['ctrl'] : [];
    $icons = is_array($ctrl['typeicon_classes'] ?? null) ? $ctrl['typeicon_classes'] : [];

    $icons['workosauth_login'] = 'workos-auth-logo';
    $icons['workosauth_account'] = 'workos-auth-account';
    $icons['workosauth_team'] = 'workos-auth-team';

    $ctrl['typeicon_classes'] = $icons;
    $ttContent['ctrl'] = $ctrl;
    $tca['tt_content'] = $ttContent;
    $GLOBALS['TCA'] = $tca;
})();
