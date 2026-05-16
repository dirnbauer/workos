<?php

declare(strict_types=1);

use WebConsulting\WorkosAuth\Controller\Backend\McpConfigurationController;
use WebConsulting\WorkosAuth\Controller\Backend\SetupAssistantController;
use WebConsulting\WorkosAuth\Controller\Backend\UserManagementController;

return [
    // WorkOS admin modules operate on extension configuration and the
    // live-only identity mapping — they do not edit versioned content,
    // so they are only offered in the LIVE workspace.
    'workos' => [
        'labels' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 'workos-auth-logo',
        'position' => ['after' => 'system'],
        'access' => 'admin',
        'workspaces' => 'live',
    ],
    'workos_users' => [
        'parent' => 'workos',
        'position' => ['top'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/workos/users',
        'iconIdentifier' => 'workos-auth-users',
        'labels' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang_mod_users.xlf',
        'routes' => [
            '_default' => [
                'target' => UserManagementController::class . '::indexAction',
            ],
            'token' => [
                'target' => UserManagementController::class . '::tokenAction',
                'methods' => ['POST'],
            ],
            'join' => [
                'target' => UserManagementController::class . '::joinAction',
                'methods' => ['POST'],
            ],
            'createOrganization' => [
                'target' => UserManagementController::class . '::createOrganizationAction',
                'methods' => ['POST'],
            ],
        ],
    ],
    'workos_setup' => [
        'parent' => 'workos',
        'position' => ['after' => 'workos_users'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/workos/setup',
        'iconIdentifier' => 'workos-auth-setup',
        'labels' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang_mod_setup.xlf',
        'aliases' => ['system_workosauth'],
        'routes' => [
            '_default' => [
                'target' => SetupAssistantController::class . '::indexAction',
            ],
            'save' => [
                'target' => SetupAssistantController::class . '::saveAction',
                'methods' => ['POST'],
            ],
        ],
    ],
    'workos_mcp' => [
        'parent' => 'workos',
        'position' => ['after' => 'workos_setup'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/workos/mcp',
        'iconIdentifier' => 'workos-auth-mcp',
        'labels' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang_mod_mcp.xlf',
        'routes' => [
            '_default' => [
                'target' => McpConfigurationController::class . '::indexAction',
            ],
            'save' => [
                'target' => McpConfigurationController::class . '::saveAction',
                'methods' => ['POST'],
            ],
            'schema' => [
                'target' => McpConfigurationController::class . '::applySchemaAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
