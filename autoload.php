<?php
declare(strict_types=1);
// Only the shared SDK is scoped; Magento and other modules' dependencies are untouched.
if (is_file(__DIR__ . '/sdk/autoload.php')) {
    require_once __DIR__ . '/sdk/autoload.php';
}
spl_autoload_register(static function (string $class): void {
    $prefix = 'Lunixi\\Payments\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        if (!preg_match('/^[A-Za-z0-9_\\\\]+$/D', $relative)) { return; }
        $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});
