<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/NativeFixture.php';
try {
    $data=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
    $om=\Lunixi\Payments\Tests\NativeFixture::manager();$ledger=$om->get(\Lunixi\Payments\Model\Ledger::class);$settings=$om->get(\Lunixi\Payments\Model\Settings::class);
    $record=$ledger->transaction($data['context']['orderKey'],fn()=>$ledger->load($data['context']['orderKey']));
    $http=new \Lunixi\Payments\Tests\NativeHttp($data['context']['snapshot']['merchantId']);$http->restore($record,$data['context']);
    $factory=new \Lunixi\Payments\Model\ClientFactory($om->get(\Lunixi\Payments\Model\TokenCache::class),$http);
    $profiles=new \Lunixi\Payments\Model\Profiles($ledger,$settings,$factory);
    $runtime=new \Lunixi\Payments\Model\Runtime($ledger,$settings,$profiles,$factory,$om->get(\Lunixi\Payments\Model\NativeOrders::class),$om->get(\Magento\Framework\UrlInterface::class),$om->get(\Magento\Framework\Locale\ResolverInterface::class));
    $runtime->receive($data['context']['profileId'],$data['raw'],$data['headers']);echo 'OK';
} catch(Throwable $error) {fwrite(STDERR,get_class($error).': native worker failed');exit(1);}
