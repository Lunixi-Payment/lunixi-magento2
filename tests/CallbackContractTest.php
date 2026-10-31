<?php
declare(strict_types=1);
namespace Lunixi\Payments\Tests;

use Lunixi\Payments\Controller\Checkout\Callback;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\Http as Response;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use PHPUnit\Framework\TestCase;

final class CallbackContractTest extends TestCase
{
    private function runAdapter(string $length, string $prefix = '/shop/index.php'): array
    {
        $request=$this->getMockBuilder(Http::class)->disableOriginalConstructor()->onlyMethods(['isSecure','getBaseUrl','getServer','getParam','getContent'])->getMock();
        $request->method('isSecure')->willReturn(true);$request->method('getBaseUrl')->willReturn($prefix);
        $request->method('getServer')->with('CONTENT_LENGTH')->willReturn($length);
        $request->expects(self::never())->method('getParam');$request->expects(self::never())->method('getContent');
        $raw=new Raw();$factory=$this->getMockBuilder(RawFactory::class)->disableOriginalConstructor()->onlyMethods(['create'])->getMock();$factory->method('create')->willReturn($raw);
        $controller=new Callback($request,$factory);$result=$controller->execute();
        $headers=[];$status=0;$body=null;
        $response=$this->getMockBuilder(Response::class)->disableOriginalConstructor()->onlyMethods(['setHeader','setHttpResponseCode','setBody'])->getMock();
        $response->method('setHeader')->willReturnCallback(function($name,$value,$replace=false)use(&$headers,$response){$headers[$name]=$value;return $response;});
        $response->method('setHttpResponseCode')->willReturnCallback(function($code)use(&$status,$response){$status=$code;return $response;});
        $response->method('setBody')->willReturnCallback(function($value)use(&$body,$response){$body=$value;return $response;});
        $result->renderResult($response);return [$status,$headers,$body];
    }
    public function testAnonymousBoundedPostOnlyRedirects303AndNeverReadsFieldsBodyOrOrder():void
    {
        [$status,$headers,$body]=$this->runAdapter('128');
        self::assertSame(303,$status);self::assertSame('/shop/index.php/lunixi/checkout/returned',$headers['Location']);
        self::assertSame('no-store',$headers['Cache-Control']);self::assertSame('',$body);
        self::assertTrue(is_subclass_of(Callback::class,\Magento\Framework\App\CsrfAwareActionInterface::class));
        self::assertFalse(is_subclass_of(\Lunixi\Payments\Controller\Checkout\Returned::class,\Magento\Framework\App\CsrfAwareActionInterface::class));
        self::assertTrue(is_subclass_of(\Lunixi\Payments\Controller\Checkout\Returned::class,\Magento\Framework\App\Action\HttpGetActionInterface::class));
        self::assertFalse(is_subclass_of(\Lunixi\Payments\Controller\Checkout\Returned::class,\Magento\Framework\App\Action\HttpPostActionInterface::class));
    }
    public function testUnboundedAndOversizedBodiesAndExternalPrefixesCannotRedirect():void
    {
        foreach([['',411],['65537',413]]as[$length,$expected]){
            [$status,$headers]=$this->runAdapter($length);self::assertSame($expected,$status);self::assertArrayNotHasKey('Location',$headers);
        }
        [$status,$headers]=$this->runAdapter('0','//evil.invalid');self::assertSame(400,$status);self::assertArrayNotHasKey('Location',$headers);
    }
}
