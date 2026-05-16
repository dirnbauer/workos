<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$rootPath = getenv('TYPO3_PATH_ROOT') ?: dirname(__DIR__, 2) . '/public';
$rootPath = rtrim(strtr((string)$rootPath, '\\', '/'), '/') . '/';

if (!is_file($rootPath . 'index.php')) {
    fwrite(STDERR, 'Unable to determine TYPO3 document root. Set TYPO3_PATH_ROOT.' . PHP_EOL);
    exit(1);
}

if (!defined('ORIGINAL_ROOT')) {
    define('ORIGINAL_ROOT', $rootPath);
}

defined('TYPO3') or define('TYPO3', true);

@mkdir(ORIGINAL_ROOT . 'typo3temp/var/tests', 0777, true);
@mkdir(ORIGINAL_ROOT . 'typo3temp/var/transient', 0777, true);
