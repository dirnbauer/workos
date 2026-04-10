<?php

declare(strict_types=1);

use WebConsulting\WorkosAuth\Middleware\BackendWorkosAuthMiddleware;
use WebConsulting\WorkosAuth\Middleware\FrontendWorkosAuthMiddleware;

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
    ],
    'backend' => [
        'webconsulting/workos-auth/backend' => [
            'target' => BackendWorkosAuthMiddleware::class,
            'before' => [
                'typo3/cms-backend/site-resolver',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
];
