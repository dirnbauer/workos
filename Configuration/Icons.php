<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'workos-auth-logo' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:workos_auth/Resources/Public/Icons/module-workos-auth.svg',
    ],
    'workos-auth-users' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:workos_auth/Resources/Public/Icons/module-workos-users.svg',
    ],
    'workos-auth-setup' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:workos_auth/Resources/Public/Icons/module-workos-setup.svg',
    ],
    'workos-auth-mcp' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:workos_auth/Resources/Public/Icons/module-workos-mcp.svg',
    ],
    'workos-auth-account' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:workos_auth/Resources/Public/Icons/module-workos-account.svg',
    ],
    'workos-auth-team' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:workos_auth/Resources/Public/Icons/module-workos-team.svg',
    ],
];
