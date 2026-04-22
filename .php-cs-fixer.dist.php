<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->files()
    ->name('*.php')
    ->in([
        __DIR__ . '/Classes',
        __DIR__ . '/Configuration',
        __DIR__ . '/Tests',
        __DIR__ . '/Build/phpstan/stubs',
    ])
    ->append([
        __DIR__ . '/ext_emconf.php',
        __DIR__ . '/ext_localconf.php',
    ])
;

$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->setFinder($finder);

return $config;
