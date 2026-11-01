<?php
declare(strict_types=1);
namespace Lunixi\Payments\Tests;

use Lunixi\Payments\Model\{Runtime, Ledger, Settings, TokenCache, ClientFactory, Profiles, NativeOrders};
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Lunixi\Payments\Vendor\Lunixi\Sdk\Http\{HttpClientInterface,HttpResponse};

final class NativeFixture
{
    public readonly ObjectManagerInterface $om;
    public readonly Ledger $ledger;
    public readonly Runtime $runtime;
    public readonly Order $order;
    public readonly NativeHttp $http;
    public readonly Profiles $profiles;
    public static function manager(): ObjectManagerInterface
    {
        putenv('LUNIXI_MAGENTO_ENCRYPTION_KEY='.base64_encode(str_repeat(chr(9),32)));
        static $native;
        if (!$native) {
            require_once getenv('MAGENTO_ROOT').'/app/bootstrap.php';
            $native = Bootstrap::create(BP, $_SERVER)->getObjectManager();
            $native->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
            $native->get(\Magento\Framework\App\AreaList::class)->getArea('frontend')->load(\Magento\Framework\App\Area::PART_CONFIG);
        }
        return $native;
    }
    public function __construct()
    {
        $native = self::manager();
        $this->om=$native;
        $db=$native->get(ResourceConnection::class)->getConnection();
        if($db->fetchOne('SELECT DATABASE()')!=='lunixi_magento')throw new \RuntimeException('Use only the isolated lunixi_magento TEST schema.');
        putenv('LUNIXI_MAGENTO_ENCRYPTION_KEY='.base64_encode(str_repeat(chr(9),32)));
        $label='PRIVATE KEY';$private="-----BEGIN {$label}-----\n".chunk_split(base64_encode(hex2bin('302e020100300506032b657004220420').str_repeat(chr(7),32)),64,"\n")."-----END {$label}-----\n";
        $writer=$native->get(WriterInterface::class);$encrypt=$native->get(EncryptorInterface::class);
        foreach(['active'=>'1','hosted'=>'0','preauth'=>'0','merchant_id'=>'merchant-'.bin2hex(random_bytes(8)), 'key_id'=>$encrypt->encrypt('mk_test_synthetic'), 'private_key'=>$encrypt->encrypt($private),'webhook_secret'=>$encrypt->encrypt('synthetic-webhook-secret')] as $key=>$value){$writer->save('payment/lunixi/'.$key,$value);}
        $native->get(ReinitableConfigInterface::class)->reinit();
        $settings=$native->get(Settings::class);$this->ledger=$native->get(Ledger::class);
        $this->http=new NativeHttp($settings->value('merchant_id',1));$factory=new ClientFactory($native->get(TokenCache::class),$this->http);
        $this->profiles=new Profiles($this->ledger,$settings,$factory);
        $this->runtime=new Runtime($this->ledger,$settings,$this->profiles,$factory,$native->get(NativeOrders::class),$native->get(\Magento\Framework\UrlInterface::class),$native->get(\Magento\Framework\Locale\ResolverInterface::class));
        $product=$native->create(\Magento\Catalog\Model\Product::class);
        $product->setSku('lunixi-native-'.bin2hex(random_bytes(6)))->setName('Synthetic native test')->setUrlKey('lunixi-'.bin2hex(random_bytes(8)))->setAttributeSetId(4)->setTypeId('virtual')->setStatus(1)->setVisibility(4)->setPrice('100.50')->setWebsiteIds([1]);
        $product=$native->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->save($product);
        $order=$native->create(Order::class);$order->setStoreId(1)->setIncrementId('LNX-'.bin2hex(random_bytes(8)));
        $order->setData('is_virtual',1)->setCustomerIsGuest(true)->setCustomerEmail('native-test@example.invalid')->setCustomerFirstname('Synthetic')->setCustomerLastname('Buyer');
        foreach(['grand_total','base_grand_total','subtotal','base_subtotal','subtotal_incl_tax','base_subtotal_incl_tax'] as $field){$order->setData($field,'100.5000');}
        foreach(['shipping_amount','base_shipping_amount','tax_amount','base_tax_amount','discount_amount','base_discount_amount'] as $field){$order->setData($field,'0.0000');}
        foreach(['order_currency_code','base_currency_code','store_currency_code','global_currency_code'] as $field){$order->setData($field,'TRY');}
        $order->setData('base_to_order_rate','1.0000')->setData('store_to_order_rate','1.0000');
        $address=$native->create(\Magento\Sales\Model\Order\Address::class);$address->setData(['firstname'=>'Synthetic','lastname'=>'Buyer','street'=>'Test fixture address','city'=>'Istanbul','country_id'=>'TR','postcode'=>'34000','telephone'=>'+905350000000','address_type'=>'billing','email'=>'native-test@example.invalid']);$order->setBillingAddress($address);
        $payment=$native->create(\Magento\Sales\Model\Order\Payment::class);$payment->setMethod('lunixi');$order->setPayment($payment);
        $item=$native->create(\Magento\Sales\Model\Order\Item::class);$item->setProductId($product->getId())->setProductType('virtual')->setSku($product->getSku())->setName('Synthetic native test')->setQtyOrdered(1)->setPrice('100.50')->setBasePrice('100.50')->setRowTotal('100.50')->setBaseRowTotal('100.50')->setPriceInclTax('100.50')->setBasePriceInclTax('100.50')->setRowTotalInclTax('100.50')->setBaseRowTotalInclTax('100.50');$order->addItem($item);
        $order->setTotalQtyOrdered(1)->setTotalItemCount(1);$order->place();$native->get(\Magento\Sales\Model\ResourceModel\Order::class)->save($order);$this->order=$order;
    }
    public function start(): void { $this->runtime->start((int)$this->order->getId()); }
    public function fresh(): Order { return $this->om->get(NativeOrders::class)->load((int)$this->order->getId()); }
    public function deliver(string $eventId): void
    {
        $context=$this->runtime->context((int)$this->order->getId());
        $raw=json_encode(['id'=>$eventId,'type'=>'payment.captured','data'=>['intentId'=>$this->http->paymentId,'orderId'=>$context['orderKey']]],JSON_THROW_ON_ERROR);$timestamp=gmdate('Y-m-d\TH:i:s\Z');
        $headers=['x-lunixi-event-id'=>$eventId,'x-lunixi-signature-timestamp'=>$timestamp,'x-lunixi-signature'=>'v2='.hash_hmac('sha256',$eventId.'.'.$timestamp.'.'.$raw,'synthetic-webhook-secret')];
        $this->runtime->receive($context['profileId'],$raw,$headers);
    }
}

final class NativeHttp implements HttpClientInterface
{
    public string $paymentId;
    public string $status='CAPTURED';
    public ?string $paymentPageUrl='https://pay.lunixi.com/payment/separate-backend-hosted-token';
    public string $fee='0';
    public string $refunded='0';
    public bool $uncertainRefund=false;
    public bool $uncertainCreate=false;
    public bool $wrongMerchant=false;
    public array $createKeys=[];
    public array $mutationKeys=[];
    private array $body=[];
    public function __construct(private string $merchantId){ $this->paymentId='pay-native-'.bin2hex(random_bytes(8)); }
    public function send(string $method,string $url,array $headers,?string $body,float $timeout):HttpResponse
    {
        if(parse_url($url,PHP_URL_HOST)!=='api-gateway.lunixi.com')throw new \RuntimeException('Forbidden network');
        $path=parse_url($url,PHP_URL_PATH);
        if(isset($headers['X-Signature'])){
            $digest=$body===null?null:'SHA-256='.base64_encode(hash('sha256',$body,true));
            if(($headers['Digest']??null)!==$digest)throw new \RuntimeException('Incorrect signed-body digest');
            $canonical=strtoupper($method)."\n".$path."\nX-Date:".$headers['X-Date']."\nX-Nonce:".$headers['X-Nonce'].($digest===null?'':"\nDigest:".$digest);
            $public=sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(str_repeat(chr(7),32)));
            if(!sodium_crypto_sign_verify_detached(base64_decode($headers['X-Signature'],true),$canonical,$public))throw new \RuntimeException('Invalid Ed25519 signature');
        }elseif($method!=='GET'){throw new \RuntimeException('Unsigned financial request');}

        if(str_ends_with($path,'/auth/token'))return new HttpResponse(200,[],json_encode(['access_token'=>'synthetic-token','expires_in'=>1800]));
        if(str_ends_with($path,'/intents')){
            $this->body=json_decode($body,true,32,JSON_THROW_ON_ERROR);$this->createKeys[]=$headers['Idempotency-Key'];
            if($this->uncertainCreate){$this->uncertainCreate=false;throw new \RuntimeException('Synthetic lost create response');}
            return new HttpResponse(200,[],json_encode(['data'=>['paymentId'=>$this->paymentId,'token'=>'synthetic-browser-token']+($this->paymentPageUrl===null?[]:['paymentPageUrl'=>$this->paymentPageUrl])]));
        }
        $amount=\Lunixi\Payments\Vendor\Lunixi\Sdk\Commerce\Money::add($this->body['amount'],$this->fee);
        if($method==='GET')return new HttpResponse(200,[],json_encode(['data'=>['id'=>$this->paymentId,'merchantId'=>$this->wrongMerchant?'wrong-merchant':$this->merchantId,'orderId'=>$this->body['orderId'],'amount'=>$amount,'currency'=>$this->body['currency'],'status'=>$this->status,'metadata'=>$this->body['metadata']], 'details'=>['capturedAmount'=>in_array($this->status,['AUTHORIZED','VOIDED','FAILED','EXPIRED'],true)?'0':$amount,'refundedAmount'=>$this->refunded]]));
        $this->mutationKeys[]=$headers['Idempotency-Key'];
        if(str_ends_with($path,'/capture'))$this->status='CAPTURED';
        if(str_ends_with($path,'/void'))$this->status='VOIDED';
        if(str_ends_with($path,'/refund')){
            if(count(array_keys($this->mutationKeys,end($this->mutationKeys),true))===1){$input=json_decode($body,true);$this->refunded=\Lunixi\Payments\Vendor\Lunixi\Sdk\Commerce\Money::add($this->refunded,$input['amount']);}
            $this->status=$this->refunded===$amount?'REFUNDED':'PARTIALLY_REFUNDED';
            if($this->uncertainRefund){$this->uncertainRefund=false;throw new \RuntimeException('Synthetic lost response');}
        }
        return new HttpResponse(200,[],json_encode(['data'=>['status'=>'PENDING']]));
    }
    public function restore(array $record,array $context):void
    {
        $this->paymentId=$record['paymentId'];$this->merchantId=$context['snapshot']['merchantId'];
        $this->body=['amount'=>$context['snapshot']['amount'],'currency'=>$context['snapshot']['currency'],'orderId'=>$context['orderKey'],
            'metadata'=>['lunixiCommerce'=>['fingerprint'=>\Lunixi\Payments\Vendor\Lunixi\Sdk\Commerce\OrderSnapshot::fromArray($context['snapshot'])->fingerprint(),'attempt'=>hash('sha256',$record['key'])]]];
    }
}
