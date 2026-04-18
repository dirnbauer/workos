<?php

defined('TYPO3') or die();

$EM_CONF = $EM_CONF ?? [];
$EM_CONF['workos_auth'] = [
    'title' => 'WorkOS Auth',
    'description' => 'Frontend and backend login for TYPO3 using WorkOS AuthKit.',
    'category' => 'plugin',
    'author' => 'webconsulting',
    'author_email' => 'office@webconsulting.at',
    'state' => 'beta',
    'version' => '0.23.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'php' => '8.2.0-8.5.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
