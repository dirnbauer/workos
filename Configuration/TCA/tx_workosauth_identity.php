<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang_db.xlf:tx_workosauth_identity',
        'label' => 'email',
        'label_alt' => 'workos_user_id',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'crdate DESC',
        'adminOnly' => true,
        'hideTable' => true,
        'rootLevel' => -1,
        'versioningWS' => false,
        'searchFields' => 'email,workos_user_id,user_table',
        'iconfile' => 'EXT:workos_auth/Resources/Public/Icons/Extension.svg',
    ],
    'columns' => [
        'login_context' => [
            'label' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang_db.xlf:tx_workosauth_identity.login_context',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Frontend', 'value' => 'frontend'],
                    ['label' => 'Backend', 'value' => 'backend'],
                ],
                'readOnly' => true,
            ],
        ],
        'workos_user_id' => [
            'label' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang_db.xlf:tx_workosauth_identity.workos_user_id',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 255,
                'readOnly' => true,
            ],
        ],
        'email' => [
            'label' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang_db.xlf:tx_workosauth_identity.email',
            'config' => [
                'type' => 'email',
                'size' => 40,
                'readOnly' => true,
            ],
        ],
        'user_table' => [
            'label' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang_db.xlf:tx_workosauth_identity.user_table',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'fe_users', 'value' => 'fe_users'],
                    ['label' => 'be_users', 'value' => 'be_users'],
                ],
                'readOnly' => true,
            ],
        ],
        'user_uid' => [
            'label' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang_db.xlf:tx_workosauth_identity.user_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'workos_profile_json' => [
            'label' => 'LLL:EXT:workos_auth/Resources/Private/Language/locallang_db.xlf:tx_workosauth_identity.workos_profile_json',
            'config' => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 8,
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'login_context, email, workos_user_id, user_table, user_uid, workos_profile_json',
        ],
    ],
];
