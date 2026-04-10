<?php

declare(strict_types=1);

use WebConsulting\WorkosAuth\Controller\Backend\SetupAssistantController;

return [
    'system_workosauth' => [
        'parent' => 'system',
        'position' => ['after' => 'tools_ExtensionmanagerExtensionmanager'],
        'access' => 'admin',
        'path' => '/module/system/workos-auth',
        'iconIdentifier' => 'workos-auth-logo',
        'labels' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => SetupAssistantController::class . '::indexAction',
            ],
        ],
    ],
];
