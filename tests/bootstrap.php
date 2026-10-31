<?php
declare(strict_types=1);
$native = getenv('MAGENTO_ROOT');
if (!is_string($native) || !is_file($native . '/vendor/autoload.php')) { throw new RuntimeException('Set MAGENTO_ROOT to a trusted Composer-installed Magento Open Source 2.4.9 source checkout.'); }
require_once $native . '/vendor/autoload.php';
$module = getenv('LUNIXI_TEST_MODULE_PATH') ?: dirname(__DIR__);
require_once $module . '/autoload.php';
if (!function_exists('__')) { require_once $native . '/app/functions.php'; }
// Generate actual native factories with Magento's own factory generator, never handwritten platform stubs.
$composer = require $native . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($composer): void {
    if (!str_starts_with($class, 'Magento\\') || !str_ends_with($class, 'Factory') || $composer->findFile($class)) { return; }
    $source = substr($class, 0, -7);
    if (!class_exists($source) && !interface_exists($source)) { return; }
    $directory = dirname(__DIR__) . '/.cache/generated';
    $io = new \Magento\Framework\Code\Generator\Io(new \Magento\Framework\Filesystem\Driver\File(), $directory);
    $generator = new \Magento\Framework\ObjectManager\Code\Generator\Factory($source, $class, $io);
    $file = $generator->generate();
    if (is_string($file) && is_file($file)) { require_once $file; }
});
