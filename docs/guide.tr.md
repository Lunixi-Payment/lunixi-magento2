# Magento 2 / Adobe Commerce

Lunixi Payments 0.1.0, Magento Payment Adapter yapısını ortak PHP SDK 0.29.3 ile birleştirir. Kaynak: [lunixi-magento2](https://github.com/Lunixi-Payment/lunixi-magento2). Hedef API serisi **2.4.9**; gerçek platform testleri Magento Open Source üzerinde çalıştırıldı. Lisanslı Adobe Commerce kurulumu test edilmedi.

## Kurulum

Hazır `lunixi-magento2-0.1.0.zip` dosyasının SHA-256 değerini doğrulayıp mağaza köküne çıkarın. Modül `app/code/Lunixi/Payments` altında bulunur. ZIP, ad alanı ayrılmış SDK içerir; modül için Composer bağımlılığı indirmeniz gerekmez.

```bash
php bin/magento module:enable Lunixi_Payments
php bin/magento setup:upgrade --safe-mode=1
php -d memory_limit=2G bin/magento setup:di:compile
php bin/magento setup:static-content:deploy en_US tr_TR
php bin/magento cache:clean
```

Kaynak koddan paketlemek için `composer install --working-dir=tools --no-plugins --no-scripts`, ardından `php tools/build.php` çalıştırılır. Bağımlılık tam olarak `lunixi/php-sdk:0.29.3` sürümüdür. Güvenilir yerel SDK klasörü `php tools/build.php /mutlak/yol/lunixi-php` ile verilebilir. Kaynak çalışma kopyasını etkinleştirmeden önce paketleyin. GitHub kaynağı, modülün Composer kayıt sisteminde veya Adobe Marketplace'te yayımlandığı anlamına gelmez.

Canlı ortamda Adobe'nin güncel PHP, veritabanı, servis gereksinimlerini ve güvenlik yamalarını uygulayın. Yerel test ortamı PHP 8.4.1, MariaDB 11.8.6/InnoDB, resmi Magento Open Source 2.4.9 ve 63 yerleşik modülden oluştu; bunlara Lunixi eklendi. Finansal test kurulumunda arama modülleri kapalıydı. Bu sonuç, gerçek servis/veritabanı kabulüdür; mağazanın tarayıcı akışı, arama ve lisanslı Commerce özellikleri ayrıca test edilmelidir. Adobe'nin güncel şirket içi 2.4.9 tablosunda PHP 8.5 ve MariaDB 12.3/MySQL 8.4 yer alır. → [Sistem gereksinimleri](https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements)

## Erişim bilgileri

Tüm web ve cron sunucularında `LUNIXI_MAGENTO_ENCRYPTION_KEY` değişkenini, bağımsız üretilmiş rastgele 32 baytlık anahtarın base64 karşılığı olarak ayarlayın. Veritabanı ve web kökü dışında güvenli yedeğini tutun. Anahtarı kaybetmek veya eski kayıtları taşımadan değiştirmek geçmiş profilleri okunamaz hale getirir.

**Mağazalar → Yapılandırma → Satış → Ödeme Yöntemleri → Lunixi Ödemeler** bölümünü açın:

| Alan | İşlev |
| --- | --- |
| Lunixi etkin | Yeni ödeme denemelerini açar |
| İş yeri kimliği | Kimliği doğrulanmış ödeme kaydında beklenen iş yeri |
| İş yeri SECRET anahtar kimliği | Sunucu API anahtarı; TEST/LIVE ortamını belirler |
| Ed25519 PKCS8 özel anahtarı | Base64 DER veya gerçek satır sonları yerine `\n` içeren PEM |
| Webhook imza sırrı | Ham gövde v2 imza doğrulaması |
| pay.lunixi.com adresine yönlendir | Açık: yönlendirmeli; kapalı: gömülü form |
| Şimdi ön provizyon al, daha sonra tahsil et | Tahsilata kadar siparişi ödeme bekliyor durumunda tutar |

Platformun şifreli alanları kayıtlı sırları maskeler. Değişmez profiller eski ödemelerin iade ve uzlaştırma erişimini saklar. Mevcut denemelerin profili, isteği, dönüş adresi, baz para birimi ve form gösterim biçimi korunur. Gateway kökü sabit olarak `https://api-gateway.lunixi.com` kullanılır.

Gösterilen **HTTPS webhook adresini** doğru ortam ve ödeme olayları için Lunixi paneline kaydedin. Adresin sonu `lunixi/notification/webhook/profile/<profil-kimliği>` biçimindedir. Geçmiş ödemeler için eski profilleri ve webhook sırlarını koruyun. Önce bir test mağazasında TEST bilgileriyle doğrulayın.

## Yönlendirmeli veya gömülü form

Yönlendirmeli akış müşteriyi `https://pay.lunixi.com/payment/<token>` adresine götürür. Gömülü akış, yalnızca resmi `https://cdn.lunixi.com/lunixi-checkout.min.js` paketini yükleyip mevcut Lunixi formunu açar. Tarayıcı token'ı gösterilmeden önce müşteri/oturum ve sipariş sahipliği denetlenir. Dönüş sayfası ödeme başarısına karar vermez.

Yöntemler, taksit, sigorta ve bölünmüş ödeme uygunluğu **Lunixi panelinden** yönetilir; modülde aynı yöntemleri tekrar açıp kapatan ayarlar bulunmaz. Ürün özellikleri iş yeri/form aktivasyonuna ve gerekli ürün bağlamına bağlıdır. Modül ham kart verisi toplamaz ve gateway'den gelen HTML'i basmaz. HTTPS, güvenilir proxy başlıkları ve resmi form kaynaklarına izin veren CSP kullanın.

## Doğrulanmış ödeme sonucu

Tutarlar, siparişe kaydedilmiş **baz para birimi ve baz genel toplam** üzerinden tam sayı alt birimlerle gönderilir. Gösterim para birimi dönüşümü Magento'da kalır. Ek taksit ücreti, kimliği doğrulanmış metadata içindeki kayıtlı sipariş parmak iziyle doğrulanır ve sepetten ayrı saklanır.

Ağ isteğinden önce kalıcı deneme/anahtar ayrılır. Belirsiz yanıtta aynı anahtar korunur. Oluşturma yanıtındaki gerçek `paymentId` ve token bağlanır. Kayıp yanıt, yalnızca imzalı olay ve ilk rezervasyonun gizli bağını doğrulayan gateway sorgusu birlikte varsa kurtarılabilir. Modülde otomatik deneme sıfırlama yolu yoktur; `FAILED` tek başına kesin başarısızlık kanıtı değildir.

Webhook alıcısı ham baytları, olay/zaman damgası imza bağını, süreyi ve tekrar çakışmalarını doğrular. Yalnızca bilinen sipariş bulunur ve sunucu erişim bilgileriyle ödeme sorgulanır. İş yeri, ödeme kimliği, sipariş, para birimi, tutar ve tüm tahsilat/iade sayaçları eşleşmelidir. Inbox, sipariş, fatura, iade belgesi ve bildirim kuyruğu aynı yerleşik satış veritabanı işlemi ve sipariş kilidi içinde yazılır. InnoDB ve satış/varsayılan bağlantının aynı olması zorunludur; modül bunları kontrol eder.

| Doğrulanmış sonuç | Platform davranışı |
| --- | --- |
| Tam tahsilat | `InvoiceService` üzerinden ödenmiş fatura |
| Tahsilatsız ön provizyon | Ödeme bekliyor; ödenmiş fatura oluşmaz |
| Dengeli, tam tahsil edilmiş, telafi/uzlaştırma gerektirmeyen bölünmüş ödeme üst kaydı | Ana sipariş tamamlanabilir |
| Yalnızca bir alt ödeme, kısmi/belirsiz durum veya senet düzenlenmesi | Otomatik teslimat açılmaz |
| Doğrulanmış iade bakiyesi | Platformda parasal düzeltme iade belgesi |
| Mevcut sahtecilik/manuel bekletme veya iptal | Bekletme korunur |

Bölünmüş ödeme kodunun bulunması, gateway dağıtımı veya iş yeri aktivasyonu anlamına gelmez. Senet düzenlenmesi nakit tahsilatı değildir. Kullanıcı platformdaki bekletmeyi açıkça kaldırınca uzlaştırma, tahsil edilmiş siparişin faturasını uygulayabilir.

## Tahsilat, iade, iptal ve uzlaştırma

**Satış → Siparişler** içinde **Lunixi ödemesini yönet** seçeneğini açın. Tahsilat, iade, ön provizyon iptali ve uzlaştırma için özel yerleşik yetki ve CSRF/form anahtarlı POST denetimi uygulanır. İşlemler ağdan önce kaydedilen kalıcı kimlik kullanır; aynı işlem tekrarında aynı tutar ve anahtar korunur. Gateway çağrısının platform finansal yazımından önce başarı dönmesini önlemek için standart çevrimiçi işlem bayrakları kapalıdır.

Yalnızca mutasyon yanıtı başarı sayılmaz; sorgulanan ödeme bakiyeleri sonucu kanıtlamalıdır. Kayıp yanıt, ikinci para hareketi olmadan uzlaştırılabilir. SDK, gateway'in 24 saatlik tekrar önleme sınırından önce yeniden gönderimi durdurur; salt okunur sorgu açık kalır.

İade tutarı baz para birimiyle girilir. Kısmi/tam iadeler **parasal düzeltme iade belgesi** oluşturur; ürün adedi iade edilmiş gibi gösterilmez ve stoğa ekleme yapılmaz. Ürün iadesi/RMA ayrı yürütülür. Son baz sepet iadesi kalan doğrulanmış ücreti de içerir. Aynı Lunixi iadesi için ayrıca ikinci bir çevrimdışı iade belgesi oluşturmayın.

Magento cron'u çalıştırın. Ayrı dönen bütçeler, bekleyen ödemeleri ve tahsil edilmiş ödemelerde yeni iadeleri sorgular. E-posta bildirimleri commit sonrası kuyruktan gönderilir. Sonucu belirsiz gönderimler tekrar körlemesine gönderilmez; `review`/`sending` durumunda operatör incelemesini bekler. Harici yan etki oluşturan üçüncü taraf olay dinleyicileri kendi kalıcı outbox yapısını kullanmalıdır.

## Saklama ve kapsam

İade/itiraz saklama süresince `lunixi_payment_*` tablolarını, geçmiş profilleri, Magento crypt anahtarını ve ayrı sunucu anahtarını koruyun. Finansal kayıtları silen kaldırma kancası veya yıkıcı şema whitelist dosyası sağlanmaz. 0.1.0 sürümünde abonelik, kart saklama/vault, Hyvä/headless ödeme arayüzleri, otomatik RMA/stok iadesi ve yalnızca Adobe Commerce'e ait özellikler uygulanmadı.

Depodaki doğrulama kaydı, gerçekten çalıştırılan testleri ve sınırlarını listeler. Canlı tahsilat, marketplace onayı veya eksiksiz tarayıcı mağaza kabulü iddia edilmez.

Yerel finansal test altyapısına resmi Temmuz–Ekim 2026 CE yamaları ve ayrı VULN-39341 düzeltmesi, sabit SHA-256 değerleriyle uygulandı. Bu, platformun üçüncü taraf bağımlılık taramasının temiz olduğu anlamına gelmez; ayrıntılar depodaki `docs/UPSTREAM-SECURITY.md` kaydındadır. Standart tarayıcı POST dönüşü yalnızca sınırlı, alanları yok sayan aynı köken 303 adaptörüne gider; sonraki GET yerleşik oturum/sahipliği denetler.
