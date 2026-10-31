<?php
declare(strict_types=1);
/** Build the standalone native module; merchants do not fetch Composer dependencies. */
$root = dirname(__DIR__);
$versionFile = $root . '/VERSION';
if (!is_file($versionFile)) { throw new RuntimeException('The authoritative module VERSION file is required.'); }
$version = trim(file_get_contents($versionFile));
if (!preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $version)) { throw new RuntimeException('Invalid module release version.'); }
$sourceManifest = json_decode(file_get_contents($root . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
if (($sourceManifest['name'] ?? null) !== 'lunixi/module-payments' || ($sourceManifest['type'] ?? null) !== 'magento2-module') { throw new RuntimeException('Expected the Lunixi Magento module manifest.'); }
// Public Composer exports omit version: Packagist derives it from the Git tag.
if (array_key_exists('version', $sourceManifest) && $sourceManifest['version'] !== $version) { throw new RuntimeException('Release version drift.'); }
if (($argv[1] ?? null) === '--check-release') { echo $version . "\n"; exit(0); }
$local = $argv[1] ?? null;
if ($local !== null && (!is_file($local . '/composer.json') || !is_dir($local . '/src/Commerce'))) { throw new RuntimeException('Expected a trusted shared SDK source directory.'); }
function run(array $command, string $cwd): void
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $cwd);
    if (!is_resource($process) || proc_close($process) !== 0) { throw new RuntimeException('Build command failed.'); }
}
function copyTree(string $source, string $target): void
{
    if (is_dir($source)) {
        if (!is_dir($target)) { mkdir($target, 0755, true); }
        foreach (new DirectoryIterator($source) as $entry) { if (!$entry->isDot()) { copyTree($entry->getPathname(), $target . '/' . $entry->getFilename()); } }
    } else { copy($source, $target); }
}
function removeTree(string $path): void
{
    if (is_link($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (new DirectoryIterator($path) as $entry) { if (!$entry->isDot()) { $entry->isDir() && !$entry->isLink() ? removeTree($entry->getPathname()) : unlink($entry->getPathname()); } }
    rmdir($path);
}
$input = $root . '/build/input'; $output = $root . '/build/module';
removeTree($input); removeTree($output); removeTree($root . '/sdk');
mkdir($input, 0755, true); mkdir($output, 0755, true);
$config = ['name' => 'lunixi/magento2-release-input', 'require' => ['lunixi/php-sdk' => '0.29.5'], 'config' => ['allow-plugins' => false, 'sort-packages' => true]];
if ($local !== null) { $config['repositories'] = [['type' => 'path', 'url' => realpath($local), 'options' => ['symlink' => false]]]; }
file_put_contents($input . '/composer.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
run(['composer','install','--no-dev','--no-plugins','--no-scripts','--no-interaction','--prefer-dist'], $input);
$source = $input . '/vendor/lunixi/php-sdk';
run([PHP_BINARY,$root . '/tools/vendor/bin/php-scoper','add-prefix','src','--output-dir=' . $root . '/sdk','--config=' . $root . '/scoper.inc.php','--force','--no-interaction'], $source);
copy($source . '/LICENSE', $root . '/sdk/LICENSE');
$prefix = 'Lunixi\\Payments\\Vendor\\Lunixi\\Sdk\\';
$loader = '<?php' . "\ndeclare(strict_types=1);\nspl_autoload_register(static function (string \$class): void {\n";
$loader .= '    $prefix = ' . var_export($prefix, true) . ";\n";
$loader .= '    if (str_starts_with($class, $prefix)) {' . "\n";
$loader .= '        $relative = substr($class, strlen($prefix));' . "\n";
$loader .= '        if (!preg_match(\'/^[A-Za-z0-9_\\\\\\\\]+$/D\', $relative)) { return; }' . "\n";
$loader .= '        $path = __DIR__ . "/" . str_replace("\\\\", "/", $relative) . ".php";' . "\n";
$loader .= '        if (is_file($path)) { require_once $path; }' . "\n    }\n});\n";
file_put_contents($root . '/sdk/autoload.php', $loader);
foreach (['registration.php','autoload.php','composer.json','Model','Gateway','Controller','Block','Plugin','etc','view','i18n','sdk','LICENSE','VERSION','README.md','CHANGELOG.md','SECURITY.md','docs'] as $file) {
    if (file_exists($root . '/' . $file)) { copyTree($root . '/' . $file, $output . '/' . $file); }
}
$manifest = json_decode(file_get_contents($output . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
if (trim(file_get_contents($output . '/VERSION')) !== $version || (array_key_exists('version', $manifest) && $manifest['version'] !== $version)) { throw new RuntimeException('Release version drift.'); }
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS)) as $entry) {
    if ($entry->isFile() && preg_match('~/(?:Users|home)/|git[.][A-Za-z0-9.-]+|(?<![A-Za-z])(?:\\.\\./){2}php/~', file_get_contents($entry->getPathname()))) { throw new RuntimeException('Internal path in merchant artifact.'); }
}
if (!is_dir($root . '/dist')) { mkdir($root . '/dist'); }
$target = $root . '/dist/lunixi-magento2-' . $version . '.zip'; $zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Cannot create ZIP.'); }
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS)) as $entry) {
    if ($entry->isFile()) { $zip->addFile($entry->getPathname(), 'app/code/Lunixi/Payments/' . substr($entry->getPathname(), strlen($output) + 1)); }
}
$zip->close(); $hash = hash_file('sha256', $target);
file_put_contents($target . '.sha256', $hash . '  ' . basename($target) . "\n");
echo 'Built ' . basename($target) . ' SHA256 ' . $hash . "\n";
