<?php
declare(strict_types=1);
namespace Lunixi\Payments\Tests;

use Lunixi\Payments\Gateway\Initialize;
use Lunixi\Payments\Model\NativeOrders;
use Lunixi\Payments\Vendor\Lunixi\Sdk\Commerce\OrderSnapshot;
use Magento\Framework\DataObject;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

final class NativeContractTest extends TestCase
{
    public function testNativeCommandPlacesOnlyPendingOrder(): void
    {
        $command = new Initialize(); $state = new DataObject();
        self::assertInstanceOf(CommandInterface::class, $command); $command->execute(['stateObject' => $state]);
        self::assertSame(Order::STATE_PENDING_PAYMENT, $state->getData('state'));
        self::assertFalse($state->getData('is_notified'));
    }
    public function testOrderMoneyUsesPersistedNativeDecimalAndBaseCurrency(): void
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $order->method('getId')->willReturn(123);
        $order->setData(['entity_id'=>123, 'store_id'=>2, 'base_grand_total'=>'100.5000', 'base_currency_code'=>'TRY', 'grand_total'=>'3.0000', 'order_currency_code'=>'EUR']);
        $snapshot = NativeOrders::snapshot($order,'merchant');
        self::assertSame('10050',$snapshot->amount()); self::assertSame('magento:2:123',$snapshot->orderId());
        self::assertSame('1.234',NativeOrders::decimal('1234','KWD'));
        self::assertSame('9007199254740.993',NativeOrders::decimal('9007199254740993','KWD'));
        $order->setData('base_grand_total','100.51');
        $this->expectException(\RuntimeException::class); NativeOrders::assertSnapshot($order,$snapshot);
    }
    public function testNativeDecimalRejectsNumericMinorUnits(): void
    {
        $this->expectException(\TypeError::class);
        NativeOrders::decimal(1234,'KWD');
    }
    public function testAllProductionClassesLoadAgainstRealNativeSignatures(): void
    {
        foreach (['Gateway','Model','Block','Controller','Plugin'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__).'/'.$directory,\FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension()!=='php') { continue; }
                $relative=substr($file->getPathname(),strlen(dirname(__DIR__)) + 1,-4);
                self::assertTrue(class_exists('Lunixi\\Payments\\'.str_replace('/','\\',$relative)),$relative);
            }
        }
    }
    public function testNativeAdminAndMachineOnlyCsrfContracts(): void
    {
        self::assertSame('Lunixi_Payments::payment',\Lunixi\Payments\Controller\Adminhtml\Payment\Apply::ADMIN_RESOURCE);
        self::assertTrue(is_subclass_of(\Lunixi\Payments\Controller\Adminhtml\Payment\Apply::class,\Magento\Framework\App\Action\HttpPostActionInterface::class));
        self::assertFalse(is_subclass_of(\Lunixi\Payments\Controller\Adminhtml\Payment\Apply::class,\Magento\Framework\App\CsrfAwareActionInterface::class));
        self::assertTrue(is_subclass_of(\Lunixi\Payments\Controller\Notification\Webhook::class,\Magento\Framework\App\CsrfAwareActionInterface::class));
    }
    public function testSdkBrowserJsonIsBoundAsAnObjectInNativeMageInitialization():void
    {
        $block=$this->getMockBuilder(\Lunixi\Payments\Block\Checkout::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $block->bind(new \Lunixi\Payments\Vendor\Lunixi\Sdk\Commerce\CheckoutSession('pay-native','synthetic-browser-token'));
        $init=json_decode($block->mageInitJson(),true,32,JSON_THROW_ON_ERROR);
        self::assertIsArray($init['Lunixi_Payments/js/checkout']);
        self::assertSame('synthetic-browser-token',$init['Lunixi_Payments/js/checkout']['options']['token']);
    }

}
