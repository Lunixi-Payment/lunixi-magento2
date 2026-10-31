<?php

declare(strict_types=1);

/** Destructive TEST-only fixture installer. Never point it at a merchant database. */
if (getenv('LUNIXI_NATIVE_TEST_DESTRUCTIVE') !== '1') {
    throw new RuntimeException('Set LUNIXI_NATIVE_TEST_DESTRUCTIVE=1 for the dedicated disposable TEST schema.');
}
$root = dirname(__DIR__);
$native = $argv[1] ?? $root . '/.cache/magento-2.4.9';
$file = getenv('LUNIXI_TEST_DB_CONFIG');
if (!is_string($file) || !is_file($file) || (fileperms($file) & 0077) !== 0) {
    throw new RuntimeException('Supply a private chmod600 LUNIXI_TEST_DB_CONFIG JSON file.');
}
$db = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
if (($db['database'] ?? '') !== 'lunixi_magento' || !isset($db['host'],$db['port'],$db['username'],$db['password'])) {
    throw new RuntimeException('Only the dedicated lunixi_magento TEST schema is accepted.');
}
function command(array $args, string $cwd, array $secrets): void
{
    if ($args[0] === PHP_BINARY) { array_splice($args, 1, 0, ['-d', 'memory_limit=2G']); }
    $outFile = tempnam(sys_get_temp_dir(), 'lunixi-native-out-');
    $errFile = tempnam(sys_get_temp_dir(), 'lunixi-native-err-');
    chmod($outFile, 0600); chmod($errFile, 0600);
    try {
        $process = proc_open($args, [STDIN, ['file',$outFile,'w'], ['file',$errFile,'w']], $pipes, $cwd);
        if (!is_resource($process)) { throw new RuntimeException('Could not start native TEST command.'); }
        $status = proc_close($process);
        $out = file_get_contents($outFile); $err = file_get_contents($errFile);
    } finally { unlink($outFile); unlink($errFile); }
    $output = str_replace($secrets, array_fill(0,count($secrets),'[REDACTED]'), $out . $err);
    if (!is_dir(__DIR__ . '/../.cache')) { mkdir(__DIR__ . '/../.cache', 0755, true); }
    file_put_contents(__DIR__ . '/../.cache/native-last-command.log', $output);
    echo $status === 0 ? substr($output,-1500) : str_replace($secrets, array_fill(0,count($secrets),'[REDACTED]'), substr($out,-1200) . "\n" . substr($err,0,4000));
    if ($status !== 0) { throw new RuntimeException('Native TEST command failed; no success is claimed.'); }
}
if (!is_dir($native)) {
    mkdir($native,0755,true);
    $archive = $root . '/.cache/magento-2.4.9.zip';
    if (!is_dir(dirname($archive))) { mkdir(dirname($archive),0755,true); }
    $input = fopen('https://codeload.github.com/magento/magento2/zip/refs/tags/2.4.9','rb');
    $output = fopen($archive,'wb'); stream_copy_to_stream($input,$output); fclose($input); fclose($output);
    if (hash_file('sha256',$archive) !== '57d31453bd8cc34fd1d7c770ed20ad85a764edbdc4e3bd065614f1413bd82ea0') { throw new RuntimeException('Native source checksum mismatch.'); }
    $zip = new ZipArchive(); $zip->open($archive);
    for ($i=0;$i<$zip->numFiles;$i++) {
        $name=$zip->getNameIndex($i);
        if (!str_starts_with($name,'magento2-2.4.9/') || str_contains($name,'../') || str_contains($name,'\\')) { throw new RuntimeException('Unsafe native source archive.'); }
    }
    $temporary=$native.'-extract';mkdir($temporary,0755,true);$zip->extractTo($temporary);$zip->close();rmdir($native);rename($temporary.'/magento2-2.4.9',$native);rmdir($temporary);
}
$manifest=json_decode(file_get_contents($native.'/composer.json'),true,32,JSON_THROW_ON_ERROR);
if (($manifest['version']??'')!=='2.4.9') { throw new RuntimeException('Native fixture requires exactly Magento Open Source 2.4.9.'); }
if(is_file($native.'/app/etc/env.php')){
    $existing=require $native.'/app/etc/env.php';
    if(($existing['db']['connection']['default']['dbname']??'')!=='lunixi_magento'){throw new RuntimeException('Refusing to replace a non-TEST native deployment configuration.');}
}
// A repeated TEST install must not reuse native persistent DDL/mview metadata.
function removeTestCache(string $path): void
{
    if (is_link($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach(new DirectoryIterator($path) as $entry){
        if($entry->isDot()){continue;}
        if($entry->isDir()&&!$entry->isLink()){removeTestCache($entry->getPathname());}
        else{unlink($entry->getPathname());}
    }
    rmdir($path);
}
foreach(['var/cache','var/page_cache','var/di','generated/metadata','generated/code'] as $cache){removeTestCache($native.'/'.$cache);}
$secrets = [$db['password'],$db['username']];
command(['composer','install','--no-dev','--no-interaction','--no-plugins','--no-scripts','--prefer-dist'], $native, $secrets);
command(['python3',__DIR__.'/native-security.py',$native],$root,$secrets);
$module=realpath($root.'/build/module');
if (!is_string($module) || !is_file($module.'/sdk/autoload.php')) { throw new RuntimeException('Build the standalone module artifact first.'); }
$parent=$native.'/app/code/Lunixi';if(!is_dir($parent)){mkdir($parent,0755,true);}
if (is_link($parent.'/Payments')) { unlink($parent.'/Payments'); }
if (file_exists($parent.'/Payments')) { throw new RuntimeException('Refusing to replace an existing native module directory.'); }
symlink($module,$parent.'/Payments');
$modules='Magento_AdminNotification,Magento_AsynchronousOperations,Magento_Authorization,Magento_AwsS3,Magento_Backend,Magento_Backup,Magento_Bundle,Magento_Captcha,Magento_Catalog,Magento_CatalogImportExport,Magento_CatalogInventory,Magento_CatalogRule,Magento_CatalogUrlRewrite,Magento_Checkout,Magento_Cms,Magento_CmsUrlRewrite,Magento_Config,Magento_Contact,Magento_Cron,Magento_Csp,Magento_Customer,Magento_Deploy,Magento_Developer,Magento_Directory,Magento_Downloadable,Magento_Eav,Magento_Email,Magento_EncryptionKey,Magento_GiftMessage,Magento_ImportExport,Magento_Indexer,Magento_Integration,Magento_MediaStorage,Magento_Msrp,Magento_Multishipping,Magento_Newsletter,Magento_PageCache,Magento_Payment,Magento_ProductAlert,Magento_Quote,Magento_RemoteStorage,Magento_Reports,Magento_RequireJs,Magento_Review,Magento_Robots,Magento_Rss,Magento_Rule,Magento_Sales,Magento_SalesRule,Magento_SalesSequence,Magento_Security,Magento_Shipping,Magento_Sitemap,Magento_Store,Magento_Tax,Magento_Theme,Magento_Translation,Magento_Ui,Magento_UrlRewrite,Magento_User,Magento_Variable,Magento_Widget,Magento_Wishlist,Lunixi_Payments';
command([PHP_BINARY,'bin/magento','setup:install','--no-interaction','--disable-modules=all','--enable-modules='.$modules,
    '--db-host='.$db['host'].':'.$db['port'],'--db-name='.$db['database'],'--db-user='.$db['username'],'--db-password='.$db['password'],
    '--base-url=https://magento.example.invalid/','--backend-frontname=lunixi_test_admin','--admin-firstname=Lunixi','--admin-lastname=Test',
    '--admin-email=test@example.invalid','--admin-user=lunixi_test','--admin-password=IsolatedNativeTest!927',
    '--language=en_US','--currency=TRY','--timezone=Europe/Istanbul','--use-rewrites=1','--cleanup-database'], $native, $secrets);
command([PHP_BINARY,'bin/magento','setup:di:compile'], $native, $secrets);

