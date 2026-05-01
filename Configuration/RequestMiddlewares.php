<?php

declare(strict_types=1);

use WebConsulting\WorkosAuth\Middleware\BackendWorkosAuthMiddleware;
use WebConsulting\WorkosAuth\Middleware\FrontendWorkosAuthMiddleware;
use WebConsulting\WorkosAuth\Middleware\McpServerMiddleware;

return [
    'frontend' => [
        'webconsulting/workos-auth/frontend' => [
            'target' => FrontendWorkosAuthMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
        'webconsulting/workos-auth/mcp' => [
            'target' => McpServerMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
    'backend' => [
        'webconsulting/workos-auth/backend' => [
            'target' => BackendWorkosAuthMiddleware::class,
            'before' => [
                'typo3/cms-backend/authentication',
                'typo3/cms-backend/backend-routing',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
];
