<?php
declare(strict_types=1);
namespace Lunixi\Payments\Tests;

use PHPUnit\Framework\TestCase;
use Magento\Sales\Model\Order;

require_once __DIR__.'/NativeFixture.php';

final class NativeIntegrationTest extends TestCase
{
    public function testRealNativeOrderPlaceSignedSettlementInvoiceAndReplay():void
    {
        $fixture=new NativeFixture();self::assertSame(Order::STATE_PENDING_PAYMENT,$fixture->order->getState());$fixture->start();
        $fixture->deliver('native-event-'.bin2hex(random_bytes(8)));$order=$fixture->fresh();
        self::assertTrue($order->hasInvoices());self::assertSame('100.5000',(string)$order->getData('total_paid'));
        self::assertSame($fixture->http->paymentId,$order->getPayment()->getAdditionalInformation('lunixi_payment_id'));
        self::assertSame(1,$order->getInvoiceCollection()->count());
        $fixture->runtime->reconcile((int)$order->getId());self::assertSame(1,$fixture->fresh()->getInvoiceCollection()->count());
    }
    public function testRealNativeAuthorizationCaptureAndPartialFinalFeeRefunds():void
    {
        $fixture=new NativeFixture();$fixture->http->status='AUTHORIZED';$fixture->http->fee='300';$fixture->start();
        $id=(int)$fixture->order->getId();$fixture->runtime->reconcile($id);
        self::assertFalse($fixture->fresh()->hasInvoices());
        $capture=$fixture->runtime->operationId($id);$fixture->runtime->mutate($id,$capture,'capture',null);
        self::assertSame(1,$fixture->fresh()->getInvoiceCollection()->count());
        $first=$fixture->runtime->operationId($id);$fixture->runtime->mutate($id,$first,'refund','1000');
        self::assertSame('10.0000',(string)$fixture->fresh()->getData('base_total_refunded'));
        $final=$fixture->runtime->operationId($id);$fixture->runtime->mutate($id,$final,'refund','9050');
        self::assertSame(10350,$fixture->http->refunded);
        self::assertSame('100.5000',(string)$fixture->fresh()->getData('base_total_refunded'));
        self::assertSame(2,$fixture->fresh()->getCreditmemosCollection()->count());
        $fixture->runtime->mutate($id,$final,'refund','9050');self::assertCount(3,$fixture->http->mutationKeys);
    }
    public function testLostRefundResponseReconcilesWithoutAnotherMutation():void
    {
        $fixture=new NativeFixture();$fixture->start();$id=(int)$fixture->order->getId();$fixture->runtime->reconcile($id);
        $operation=$fixture->runtime->operationId($id);$fixture->http->uncertainRefund=true;
        try{$fixture->runtime->mutate($id,$operation,'refund','1000');self::fail('Expected unknown outcome');}catch(\RuntimeException){}
        $fixture->runtime->reconcile($id);self::assertSame('10.0000',(string)$fixture->fresh()->getData('base_total_refunded'));
        $fixture->runtime->mutate($id,$operation,'refund','1000');self::assertCount(1,$fixture->http->mutationKeys);
    }
    public function testNativeFinancialFailureRollsBackInvoiceOrderOutboxAndInbox():void
    {
        $fixture=new NativeFixture();$fixture->start();$db=$fixture->ledger->db();
        $before=$db->fetchOne('SELECT COUNT(*) FROM lunixi_payment_event');
        $db->query("CREATE TRIGGER lunixi_test_outbox_failure BEFORE INSERT ON lunixi_payment_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic transaction failure'");
        try{
            try{$fixture->deliver('rollback-'.bin2hex(random_bytes(8)));self::fail('Expected native write failure');}catch(\Exception){}
            self::assertFalse($fixture->fresh()->hasInvoices());self::assertNull($fixture->fresh()->getData('total_paid'));
            self::assertSame($before,$db->fetchOne('SELECT COUNT(*) FROM lunixi_payment_event'));
        }finally{$db->query('DROP TRIGGER IF EXISTS lunixi_test_outbox_failure');}
        $fixture->runtime->reconcile((int)$fixture->order->getId());self::assertTrue($fixture->fresh()->hasInvoices());
    }
    public function testGuestOwnershipAndChangedNativeTotalsFailClosed():void
    {
        $fixture=new NativeFixture();$id=(int)$fixture->order->getId();
        try{$fixture->runtime->owned($id,0,$id+1,1);self::fail('Expected ownership rejection');}catch(\RuntimeException){}
        $fixture->start();$fixture->ledger->db()->update('sales_order',['base_grand_total'=>'101.00'],['entity_id = ?'=>$id]);
        $this->expectException(\RuntimeException::class);$fixture->runtime->reconcile($id);
    }
    public function testNativeManualHoldSurvivesCaptureAndCanApplyAfterExplicitUnhold():void
    {
        $fixture=new NativeFixture();$fixture->start();$id=(int)$fixture->order->getId();
        $order=$fixture->fresh();$order->hold();$fixture->om->get(\Magento\Sales\Model\ResourceModel\Order::class)->save($order);
        $fixture->runtime->reconcile($id);self::assertSame(Order::STATE_HOLDED,$fixture->fresh()->getState());self::assertFalse($fixture->fresh()->hasInvoices());
        $order=$fixture->fresh();$order->unhold();$fixture->om->get(\Magento\Sales\Model\ResourceModel\Order::class)->save($order);
        $fixture->runtime->reconcile($id);self::assertTrue($fixture->fresh()->hasInvoices());
    }

    public function testConcurrentSignedDeliveriesAcrossProcessesCreateOneNativeInvoice():void
    {
        $fixture=new NativeFixture();$fixture->start();$id=(int)$fixture->order->getId();$context=$fixture->runtime->context($id);
        $event='parallel-'.bin2hex(random_bytes(8));$raw=json_encode(['id'=>$event,'type'=>'payment.captured','data'=>['intentId'=>$fixture->http->paymentId,'orderId'=>$context['orderKey']]],JSON_THROW_ON_ERROR);$timestamp=gmdate('Y-m-d\TH:i:s\Z');
        $data=['context'=>$context,'raw'=>$raw,'headers'=>['x-lunixi-event-id'=>$event,'x-lunixi-signature-timestamp'=>$timestamp,'x-lunixi-signature'=>'v2='.hash_hmac('sha256',$event.'.'.$timestamp.'.'.$raw,'synthetic-webhook-secret')]];
        $file=tempnam(sys_get_temp_dir(),'lunixi-native-worker-');file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));chmod($file,0600);
        try{
            $children=[];
            for($i=0;$i<3;$i++){$process=proc_open([PHP_BINARY,__DIR__.'/native-worker.php',$file],[STDIN,['pipe','w'],['pipe','w']],$pipes);$children[]=[$process,$pipes];}
            foreach($children as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);self::assertSame(0,proc_close($process),$error);self::assertSame('OK',$out);}
        }finally{unlink($file);}
        self::assertSame(1,$fixture->fresh()->getInvoiceCollection()->count());self::assertSame('100.5000',(string)$fixture->fresh()->getData('total_paid'));
    }

    public function testSignedWebhookRecoversLostCreateResponseOnlyFromBoundReservation():void
    {
        $fixture=new NativeFixture();$fixture->http->uncertainCreate=true;
        try{$fixture->start();self::fail('Expected uncertain create');}catch(\RuntimeException){}
        $fixture->deliver('recover-'.bin2hex(random_bytes(8)));
        self::assertTrue($fixture->fresh()->hasInvoices());self::assertCount(1,$fixture->http->createKeys);
    }
    public function testAuthenticatedMerchantMismatchCannotConsumeEventOrInvoice():void
    {
        $fixture=new NativeFixture();$fixture->start();$fixture->http->wrongMerchant=true;$db=$fixture->ledger->db();$before=$db->fetchOne('SELECT COUNT(*) FROM lunixi_payment_event');
        try{$fixture->deliver('mismatch-'.bin2hex(random_bytes(8)));self::fail('Expected merchant rejection');}catch(\RuntimeException){}
        self::assertFalse($fixture->fresh()->hasInvoices());self::assertSame($before,$db->fetchOne('SELECT COUNT(*) FROM lunixi_payment_event'));
    }

    public function testVerifiedVoidCancelsNativeAuthorizationWithoutInvoice():void
    {
        $fixture=new NativeFixture();$fixture->http->status='AUTHORIZED';$fixture->start();$id=(int)$fixture->order->getId();$fixture->runtime->reconcile($id);
        $operation=$fixture->runtime->operationId($id);$fixture->runtime->mutate($id,$operation,'void',null);
        self::assertSame(Order::STATE_CANCELED,$fixture->fresh()->getState());self::assertFalse($fixture->fresh()->hasInvoices());
    }
    public function testStaleAuthorizationCannotDowngradeNativePaidInvoice():void
    {
        $fixture=new NativeFixture();$fixture->start();$id=(int)$fixture->order->getId();$fixture->runtime->reconcile($id);
        $fixture->http->status='AUTHORIZED';$fixture->runtime->reconcile($id);
        self::assertSame('100.5000',(string)$fixture->fresh()->getData('base_total_paid'));self::assertSame(1,$fixture->fresh()->getInvoiceCollection()->count());
    }
    public function testCredentialAndDisplayRotationPreservesOriginalAttempt():void
    {
        $fixture=new NativeFixture();$fixture->start();$id=(int)$fixture->order->getId();$before=$fixture->runtime->context($id);
        $writer=$fixture->om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
        $writer->save('payment/lunixi/merchant_id','new-configured-merchant');$writer->save('payment/lunixi/hosted','1');
        $fixture->om->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
        $fixture->runtime->start($id);self::assertSame($before,$fixture->runtime->context($id));self::assertCount(1,$fixture->http->createKeys);
        $fixture->runtime->reconcile($id);self::assertTrue($fixture->fresh()->hasInvoices());
    }

    public function testEmbeddedDefaultAndIndependentBackendUrlSurviveNativeSessionReload():void
    {
        $fixture=new NativeFixture();$fixture->start();$id=(int)$fixture->order->getId();
        self::assertFalse($fixture->runtime->context($id)['hosted']);
        $session=$fixture->runtime->browser($id);
        self::assertSame('synthetic-browser-token',$session->token);
        self::assertSame('https://pay.lunixi.com/payment/separate-backend-hosted-token',$session->hostedUrl());
        $key=$fixture->runtime->context($id)['orderKey'];$record=$fixture->ledger->transaction($key,fn()=>$fixture->ledger->load($key));
        self::assertStringNotContainsString('separate-backend-hosted-token',$record['paymentPageUrl']);
        $fixture->runtime->start($id);self::assertCount(1,$fixture->http->createKeys);
        self::assertSame($session->hostedUrl(),$fixture->runtime->browser($id)->hostedUrl());
    }
    public function testMissingAndLegacyBackendUrlNeverCreateReplacementIntent():void
    {
        $fixture=new NativeFixture();$fixture->http->paymentPageUrl=null;$fixture->start();$id=(int)$fixture->order->getId();
        $session=$fixture->runtime->browser($id);self::assertSame('synthetic-browser-token',$session->token);self::assertSame('',$session->paymentPageUrl);
        $context=$fixture->runtime->context($id);
        $fixture->ledger->transaction($context['orderKey'],function()use($fixture,$context):void{$record=$fixture->ledger->load($context['orderKey']);unset($record['paymentPageUrl']);$fixture->ledger->save($context['orderKey'],$record);});
        $fixture->runtime->start($id);self::assertCount(1,$fixture->http->createKeys);
        $legacy=$fixture->runtime->browser($id);self::assertSame('synthetic-browser-token',$legacy->token);
        $this->expectException(\RuntimeException::class);$legacy->hostedUrl();
    }
    public function testStoreMismatchCannotExposeAnOwnedGuestPayment():void
    {
        $fixture=new NativeFixture();$id=(int)$fixture->order->getId();
        $this->expectException(\RuntimeException::class);$fixture->runtime->owned($id,0,$id,2);
    }

    public function testReturnGetUsesRestoredNativeSessionAndStoreOwnershipOnly():void
    {
        $fixture=new NativeFixture();
        $checkout=$this->getMockBuilder(\Magento\Checkout\Model\Session::class)->disableOriginalConstructor()->onlyMethods(['getLastRealOrder'])->getMock();$checkout->method('getLastRealOrder')->willReturn($fixture->order);
        $customer=$this->getMockBuilder(\Magento\Customer\Model\Session::class)->disableOriginalConstructor()->onlyMethods(['getCustomerId'])->getMock();$customer->method('getCustomerId')->willReturn(0);
        $store=$fixture->om->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore(1);
        $stores=$this->createMock(\Magento\Store\Model\StoreManagerInterface::class);$stores->method('getStore')->willReturnCallback(fn()=>$store);
        $page=$this->getMockBuilder(\Magento\Framework\View\Result\Page::class)->disableOriginalConstructor()->getMock();
        $pages=$this->getMockBuilder(\Magento\Framework\View\Result\PageFactory::class)->disableOriginalConstructor()->onlyMethods(['create'])->getMock();$pages->expects(self::once())->method('create')->willReturn($page);
        $redirect=$this->getMockBuilder(\Magento\Framework\Controller\Result\Redirect::class)->disableOriginalConstructor()->onlyMethods(['setPath'])->getMock();$redirect->expects(self::once())->method('setPath')->with('checkout/cart')->willReturnSelf();
        $redirects=$this->getMockBuilder(\Magento\Framework\Controller\Result\RedirectFactory::class)->disableOriginalConstructor()->onlyMethods(['create'])->getMock();$redirects->method('create')->willReturn($redirect);
        $controller=new \Lunixi\Payments\Controller\Checkout\Returned($pages,$redirects,$fixture->runtime,$checkout,$customer,$stores);
        self::assertSame($page,$controller->execute());
        $store->setId(2);try{self::assertSame($redirect,$controller->execute());}finally{$store->setId(1);}
        self::assertSame([],$fixture->http->createKeys);
    }

}
