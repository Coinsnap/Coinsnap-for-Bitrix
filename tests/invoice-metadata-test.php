<?php
// Checks the create-invoice request initiatePay() sends (orderId and metadata), for Coinsnap and for BTCPay.
//
//   php tests/invoice-metadata-test.php      (no Bitrix, no database, no network)
//
// Runs the real handler.php and bundled library against stubs of the Bitrix order API
// (Order, Payment, PropertyValueCollection, Basket, ShipmentCollection, Discount) and a fake HTTP client.
declare(strict_types=1);

namespace Coinsnap\Http {
    $lib = dirname(__DIR__) . '/coinsnap.payment/install/bitrix/modules/sale/handlers/paysystem/coinsnap/library';
    foreach (['ResponseInterface', 'ClientInterface', 'Response'] as $file) {
        require_once $lib . '/Http/' . $file . '.php';
    }

    // Replaces the library's cURL client before the loader can load it: answers every call, keeps every request.
    class CurlClient implements ClientInterface
    {
        public static array $calls = [];
        public function setCurlOptions(array $options) {}
        public function request(string $method, string $url, array $headers = [], string $body = ''): ResponseInterface
        {
            self::$calls[] = [$method, $url, $body];
            $routes = [
                ['GET', '#coingecko#', '{"rates":{"eur":{"value":90000},"usd":{"value":100000}}}'],
                ['GET', '#/api-keys/current$#', '{"permissions":["btcpay.store.cancreateinvoice:BTC-STORE-1","btcpay.store.canviewinvoices:BTC-STORE-1","btcpay.store.webhooks.canmodifywebhooks:BTC-STORE-1"]}'],
                ['GET', '#/webhooks/WH-1$#', '{"id":"WH-1","url":"https://shop.example/bitrix/tools/sale_ps_result.php"}'],
                ['POST', '#/invoices$#', '{"id":"INV-1","checkoutLink":"https://pay.example/i/INV-1"}'],
            ];
            foreach ($routes as [$m, $pattern, $responseBody]) {
                if ($m === $method && preg_match($pattern, $url)) {
                    return new Response(200, $responseBody, []);
                }
            }
            return new Response(404, '{}', []);
        }
    }
}

namespace Bitrix\Main {
    class Request {}
    class Error {}
    class Application
    {
        public static function getConnection()
        {
            return new class {
                public array $sql = [];
                public function queryExecute(string $sql): void { $this->sql[] = $sql; }
                public function query(string $sql) { return new class { public function fetch() { return false; } }; }
                public function getSqlHelper() { return new class { public function forSql(string $v): string { return addslashes($v); } }; }
                public function getAffectedRowsCount(): int { return 1; }
            };
        }
    }
}

namespace Bitrix\Main\Config {
    class Option
    {
        public static array $values = [];
        public static function get($module, $name, $default = '') { return self::$values[$module . ':' . $name] ?? $default; }
        public static function set($module, $name, $value): void { self::$values[$module . ':' . $name] = (string) $value; }
    }
}

namespace Bitrix\Main\Localization {
    class Loc
    {
        public static function loadMessages($file): void {}
        public static function getMessage($code) { return $code; }
    }
}

namespace Bitrix\Main\Type { class DateTime {} }
namespace Bitrix\Main\Web { class HttpClient {} }

namespace Bitrix\Sale\Location\Admin {
    class LocationHelper
    {
        public static function getLocationPathDisplay($code) { return $code === '0000073738' ? 'Deutschland, Berlin' : ''; }
    }
}

namespace Bitrix\Sale\PaySystem {
    class Logger
    {
        public static array $lines = [];
        public static function addError($line): void { self::$lines[] = 'ERROR ' . $line; }
        public static function addDebugInfo($line): void { self::$lines[] = 'DEBUG ' . $line; }
    }
    class ServiceResult {}
    class ServiceHandler
    {
        public array $extra = [];
        public function __construct(public array $values = []) {}
        public function getBusinessValue($payment, $code) { return $this->values[$code] ?? null; }
        public function setExtraParams(array $p): void { $this->extra = $p; }
        public function showTemplate($payment, $template) { return $template; }
    }
}

namespace Bitrix\Sale {
    class PriceMaths {}
    class Value
    {
        public function __construct(private $v) {}
        public function getValue() { return $this->v; }
        public function setField($k, $v): void {}
    }
    class PropertyValueCollection
    {
        public function __construct(private array $byCode) {}
        private function v(string $code) { return array_key_exists($code, $this->byCode) ? new Value($this->byCode[$code]) : null; }
        public function getPayerName() { return $this->v('FIO'); }
        public function getUserEmail() { return $this->v('EMAIL'); }
        public function getPhone() { return $this->v('PHONE'); }
        public function getAddress() { return $this->v('ADDRESS'); }
        public function getDeliveryLocationZip() { return $this->v('ZIP'); }
        public function getDeliveryLocation() { return $this->v('LOCATION'); }
        public function getItemByOrderPropertyCode($code) { return $code === 'INVOICE_URL' ? null : $this->v($code); }
        public function createItem(array $f) { return new Value(null); }
    }
    class BasketItem
    {
        public function __construct(private array $f) {}
        public function getField($k) { return $this->f[$k] ?? null; }
        public function getProductId() { return $this->f['PRODUCT_ID']; }
        public function getQuantity() { return $this->f['QUANTITY']; }
        public function getPrice() { return $this->f['PRICE']; }
        public function getFinalPrice() { return $this->f['PRICE'] * $this->f['QUANTITY']; }
    }
    class Shipment
    {
        public function __construct(private string $name, private bool $system) {}
        public function isSystem() { return $this->system; }
        public function getDeliveryName() { return $this->name; }
    }
    class Order
    {
        public array $props = [];
        public array $basket = [];
        public array $coupons = [];
        public function __construct(public int $id, public string $currency, public array $fields = []) {}
        public function getId() { return $this->id; }
        public function getCurrency() { return $this->currency; }
        public function getField($k) { return $this->fields[$k] ?? null; }
        public function getSiteId() { return 's1'; }
        public function getPrice() { return $this->fields['PRICE']; }
        public function getDeliveryPrice() { return $this->fields['PRICE_DELIVERY']; }
        public function getTaxValue() { return $this->fields['TAX_VALUE']; }
        public function getDiscountPrice() { return $this->fields['DISCOUNT_VALUE']; }
        public function getPaymentCollection() { return null; }
        public function getPropertyCollection() { return new PropertyValueCollection($this->props); }
        public function getBasket() { return array_map(fn ($i) => new BasketItem($i), $this->basket); }
        public function getShipmentCollection() { return [new Shipment('', true), new Shipment('DHL Paket', false)]; }
        public function getDiscount()
        {
            $coupons = $this->coupons;
            return new class($coupons) {
                public function __construct(private array $c) {}
                public function getApplyResult($extended = false) { return ['COUPON_LIST' => $this->c]; }
            };
        }
        public function save() {}
    }
    class Payment
    {
        public array $fields = [];
        public function __construct(public int $id, public Order $order, public float $sum) {}
        public function getId() { return $this->id; }
        public function getOrder() { return $this->order; }
        public function getOrderId() { return $this->order->id; }
        public function getSum() { return $this->sum; }
        public function getPaymentSystemId() { return 4; }
        public function isPaid(): bool { return false; }
        public function setFields(array $f): void { $this->fields = $f + $this->fields; }
    }
}

namespace {
    use Bitrix\Main\Config\Option;
    use Bitrix\Sale\Order;
    use Bitrix\Sale\Payment;
    use Coinsnap\Http\CurlClient;
    use Sale\Handlers\PaySystem\CoinsnapHandler;

    error_reporting(E_ALL & ~E_DEPRECATED);
    set_error_handler(function (int $no, string $str, string $file, int $line) {
        if ($no === E_DEPRECATED) {
            return true;
        }
        throw new ErrorException($str, 0, $no, $file, $line);
    });

    define('B_PROLOG_INCLUDED', true);
    define('LANGUAGE_ID', 'de');
    require dirname(__DIR__) . '/coinsnap.payment/install/bitrix/modules/sale/handlers/paysystem/coinsnap/handler.php';

    $passed = 0;
    $failed = 0;
    function check(string $name, bool $ok, $detail = null): void
    {
        global $passed, $failed;
        $ok ? $passed++ : $failed++;
        echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === null ? '' : ' -- got ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    }

    function invoiceRequest(string $provider, Order $order): array
    {
        Option::$values = ['main:server_name' => 'shop.example'];
        $values = ['COINSNAP_RETURNURL' => 'https://shop.example/personal/order/', 'COINSNAP_STATUS_NEW' => 'N'];
        if ($provider === 'btcpay') {
            $values += ['COINSNAP_PROVIDER' => 'btcpay', 'BTCPAY_SERVER_URL' => 'https://btcpay.example', 'BTCPAY_STORE_ID' => 'BTC-STORE-1', 'BTCPAY_API_KEY' => 'k'];
            Option::set('coinsnap.payment', 'webhook_' . md5('btcpay|BTC-STORE-1'), json_encode(['id' => 'WH-1', 'secret' => 's']));
        } else {
            $values += ['COINSNAP_PROVIDER' => 'coinsnap', 'COINSNAP_STORE_ID' => 'CS-STORE-1', 'COINSNAP_API_KEY' => 'k'];
            Option::set('coinsnap.payment', 'webhook_' . md5('coinsnap|CS-STORE-1'), json_encode(['id' => 'WH-1', 'secret' => 's']));
        }
        CurlClient::$calls = [];
        $handler = new CoinsnapHandler($values);
        $template = $handler->initiatePay(new Payment(9, $order, (float) $order->fields['PRICE']));
        foreach (CurlClient::$calls as [$method, $url, $body]) {
            if ($method === 'POST' && preg_match('#/invoices$#', $url)) {
                return ['template' => $template, 'body' => json_decode($body, true)];
            }
        }
        return ['template' => $template, 'body' => null, 'log' => Bitrix\Sale\PaySystem\Logger::$lines];
    }

    $makeOrder = function (): Order {
        $order = new Order(57, 'EUR', ['ACCOUNT_NUMBER' => '2026-57', 'USER_DESCRIPTION' => 'Bitte klingeln', 'PRICE' => 108.9,
            'PRICE_DELIVERY' => 4.9, 'TAX_VALUE' => 18.15, 'DISCOUNT_VALUE' => 5.0]);
        $order->props = ['FIO' => 'Erika Mustermann', 'EMAIL' => 'erika@example.com', 'PHONE' => '+49 30 123456', 'COMPANY' => 'Muster &amp; Co',
            'ADDRESS' => 'Hauptstr. 5', 'CITY' => 'Berlin', 'ZIP' => '10115', 'LOCATION' => '0000073738'];
        $order->basket = [
            ['NAME' => 'Tasse', 'PRODUCT_ID' => 7, 'QUANTITY' => 2, 'PRICE' => 19.5],
            ['NAME' => 'T-Shirt', 'PRODUCT_ID' => 12, 'QUANTITY' => 1, 'PRICE' => 70.0],
        ];
        $order->coupons = ['SUMMER5' => ['COUPON' => 'SUMMER5']];
        return $order;
    };

    foreach (['coinsnap', 'btcpay'] as $provider) {
        $r = invoiceRequest($provider, $makeOrder());
        $b = $r['body'] ?? [];
        $m = $b['metadata'] ?? [];
        check("$provider: invoice created", $r['template'] === 'redirect', $r);
        check("$provider: top-level orderId is the order ID (matched by the webhook)", ($b['orderId'] ?? null) === '57', $b['orderId'] ?? null);
        check("$provider: metadata.orderId is the order ID (matched by the BTCPay webhook)", ($m['orderId'] ?? null) === '57', $m['orderId'] ?? null);
        check("$provider: orderNumber is the account number", ($m['orderNumber'] ?? null) === '2026-57', $m['orderNumber'] ?? null);
        check("$provider: orderUrl", ($m['orderUrl'] ?? null) === 'https://shop.example/bitrix/admin/sale_order_view.php?ID=57&lang=de', $m['orderUrl'] ?? null);
        check("$provider: top-level buyerEmail", ($b['buyerEmail'] ?? null) === 'erika@example.com');
        check("$provider: buyer contact", ($m['customerName'] ?? null) === 'Erika Mustermann' && ($m['buyerName'] ?? null) === 'Erika Mustermann'
            && ($m['buyerEmail'] ?? null) === 'erika@example.com' && ($m['buyerPhone'] ?? null) === '+49 30 123456', $m);
        check("$provider: address", ($m['buyerCompany'] ?? null) === 'Muster & Co' && ($m['buyerAddress1'] ?? null) === 'Hauptstr. 5'
            && ($m['buyerCity'] ?? null) === 'Berlin' && ($m['buyerZip'] ?? null) === '10115' && ($m['buyerLocation'] ?? null) === 'Deutschland, Berlin', $m);
        check("$provider: customer note", ($m['buyerMessage'] ?? null) === 'Bitte klingeln');
        // JSON numbers: 2.0 comes back as 2, so numbers are compared by value.
        check("$provider: line items", ($m['lineItems'] ?? null) == [
            ['name' => 'Tasse', 'productId' => 7, 'quantity' => 2, 'price' => 19.5, 'total' => 39],
            ['name' => 'T-Shirt', 'productId' => 12, 'quantity' => 1, 'price' => 70, 'total' => 70],
        ], $m['lineItems'] ?? null);
        check("$provider: itemDesc / itemCode", ($m['itemDesc'] ?? null) === '2 x Tasse, T-Shirt' && ($m['itemCode'] ?? null) === '7, 12', [$m['itemDesc'] ?? null, $m['itemCode'] ?? null]);
        check("$provider: totals / currency", ($m['orderTotal'] ?? null) == 108.9 && ($m['shippingTotal'] ?? null) == 4.9 && ($m['taxTotal'] ?? null) == 18.15
            && ($m['discountTotal'] ?? null) == 5 && ($m['currency'] ?? null) === 'EUR', $m);
        check("$provider: shipping method / coupons / platform", ($m['shippingMethod'] ?? null) === 'DHL Paket' && ($m['couponCodes'] ?? null) === ['SUMMER5']
            && ($m['platform'] ?? null) === 'bitrix' && ($m['pluginVersion'] ?? null) === COINSNAP_BITRIX_VERSION, $m);
        check("$provider: no empty values", $m !== [] && !in_array('', $m, true) && !in_array(null, $m, true));
    }

    // An order without properties, basket or account number still gets an invoice with its ID.
    $bare = new Order(58, 'EUR', ['PRICE' => 10.0]);
    $r = invoiceRequest('coinsnap', $bare);
    $m = $r['body']['metadata'] ?? [];
    check('sparse order: invoice created, orderNumber falls back to the ID', $r['template'] === 'redirect' && ($r['body']['orderId'] ?? null) === '58'
        && ($m['orderNumber'] ?? null) === '58' && !isset($m['customerName']) && !isset($m['lineItems']), $m);

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
