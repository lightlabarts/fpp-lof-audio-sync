<?php
/**
 * LOF Audio Supply - library bootstrap.
 *
 * Zero-dependency PSR-4 style autoloader. FPP images ship PHP without composer,
 * so the plugin must load its own classes.
 *
 * Target runtime: PHP 8.1+ (FPP 8/9/10). Do not use syntax newer than 8.1.
 */

declare(strict_types=1);

if (defined('LOF_AUDIO_SUPPLY_BOOTSTRAPPED')) {
    return;
}
define('LOF_AUDIO_SUPPLY_BOOTSTRAPPED', true);
define('LOF_AUDIO_SUPPLY_LIB', __DIR__);
define('LOF_AUDIO_SUPPLY_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'LofAudioSupply\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = LOF_AUDIO_SUPPLY_LIB . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once __DIR__ . '/Errors.php';
