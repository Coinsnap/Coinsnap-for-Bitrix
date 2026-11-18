<?php
namespace Sale\Handlers\PaySystem;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
	die();
}

use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\Payment;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Sale\PriceMaths;
use Bitrix\Sale\Order as SaleOrder;
use Bitrix\Sale\PaySystem\ServiceResult;
use Bitrix\Sale\PaySystem\Logger;
use Coinsnap\Client\Webhook;
Loc::loadMessages(__FILE__);

if(!defined('COINSNAP_BITRIX_VERSION')){ define( 'COINSNAP_BITRIX_VERSION', '1.2.0' ); }
if(!defined('COINSNAP_BITRIX_REFERRAL_CODE')){ define( 'COINSNAP_BITRIX_REFERRAL_CODE', 'D84007' ); }
if(!defined('COINSNAP_CURRENCIES')){ define( 'COINSNAP_CURRENCIES', array("EUR","USD","SATS","BTC","CAD","JPY","GBP","CHF","RUB") ); }
if(!defined('COINSNAP_SERVER_URL')){ define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' );}
if(!defined('COINSNAP_API_PATH')){define( 'COINSNAP_API_PATH', '/api/v1/');}
if(!defined('COINSNAP_SERVER_PATH')){define( 'COINSNAP_SERVER_PATH', 'stores' );}

require_once(__DIR__  . '/library/loader.php');
require_once(__DIR__ . '/webhook/Signature.php');
require_once(__DIR__ . '/webhook/InvoiceDecision.php');
require_once(__DIR__ . '/webhook/Processor.php');
require_once(__DIR__ . '/webhook/InvoiceRecords.php');
require_once(__DIR__ . '/webhook/BtcpayKey.php');

class CoinsnapHandler extends PaySystem\ServiceHandler {
    public const COINSNAP_WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];
    public const BTCPAY_WEBHOOK_EVENTS = ['InvoiceCreated','InvoiceExpired','InvoiceSettled','InvoiceProcessing'];
    
    public function initiatePay(Payment $payment, Request $request = null){
        $order = $payment->getOrder();
        $order_id = $order->getId();
        $currency_code = $order->getCurrency();
        
        
        $paymentCollection = $order->getPaymentCollection();
        $propertyCollection = $order->getPropertyCollection();
        
        if ($payment->isPaid() || $invoiceURL = $propertyCollection->getItemByOrderPropertyCode('INVOICE_URL')) {
            return $this->showTemplate($payment, 'template');
        }
        
        $paySystemId = $payment->getPaymentSystemId();

        if ($this->getPaymentProvider($payment) === 'btcpay' && !$this->btcpayKeyUsable($payment)) {
            $this->setExtraParams(['message' => Loc::getMessage('SALE_COINSNAP_API_ERROR')]);
            return $this->showTemplate($payment, 'template');
        }

        try {
            $webhookReady = $this->webhookExists($this->getApiUrl($payment), $this->getApiKey($payment), $this->getStoreId($payment), $this->getWebhookSecret($payment))
                || $this->registerWebhook($this->getApiUrl($payment), $this->getApiKey($payment), $this->getStoreId($payment), $this->getPaymentProvider($payment));
        } catch (\Throwable $e) {
            $webhookReady = false;
        }
        if (!$webhookReady) {
            Logger::addError('Coinsnap: the webhook could not be checked or registered');
            $this->setExtraParams(['message' => Loc::getMessage('SALE_COINSNAP_WEBHOOK_ERROR')]);
            return $this->showTemplate($payment, 'template');
        }

        $amount = number_format((float) $payment->getSum(), 2, '.', '');
        
        $client = new \Coinsnap\Client\Invoice( $this->getApiUrl($payment), $this->getApiKey($payment) );
        $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper($currency_code));
        
        
        
        if($checkInvoice['result'] === true){
            
            $buyerName =  is_null($propertyCollection->getPayerName()) ? '' : $propertyCollection->getPayerName()->getValue();
            $buyerEmail = is_null($propertyCollection->getUserEmail()) ? '' : $propertyCollection->getUserEmail()->getValue();

            $redirectUrl = (!empty($this->getBusinessValue($payment, 'COINSNAP_RETURNURL')))? $this->getBusinessValue($payment, 'COINSNAP_RETURNURL') : $this->getReturnUrl($payment);
		
            $metadata = [];
            $metadata['orderNumber'] = $order_id;
            $metadata['customerName'] = $buyerName;
            
            if($this->getPaymentProvider($payment) === 'btcpay'){
                $metadata['orderId'] = $order_id;
            }

            $redirectAutomatically = ($this->getBusinessValue($payment, 'COINSNAP_AUTOREDIRECT') === 'N')? false : true;
            $walletMessage = '';
            
            // Handle currencies non-supported by BTCPay Server, we need to change them BTC and adjust the amount.
            if (($currency_code === 'SATS' || $currency_code === 'RUB') && $this -> getPaymentProvider($payment) === 'btcpay') {
                $currency_code = 'BTC';
                $rate = 1/$checkInvoice['rate'];
                $amountBTC = bcdiv(strval($amount), strval($rate), 8);
                $amount = (float)$amountBTC;
            }
        
            $camount = ($currency_code === 'BTC')? \Coinsnap\Util\PreciseNumber::parseFloat($amount,8) : \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
            try {
                $invoiceData = $client->createInvoice(
                    $this->getStoreId($payment),
                    $currency_code,
                    $camount,
                    $order_id,
                    $buyerEmail,
                    $buyerName,
                    $redirectUrl,
                    COINSNAP_BITRIX_REFERRAL_CODE,
                    $metadata,
                    $redirectAutomatically,
                    $walletMessage
                )->getData();
            } catch (\Throwable $e) {
                Logger::addError(sprintf('Coinsnap: invoice creation failed for order %d (status %d)', (int) $order_id, (int) $e->getCode()));
                $invoiceData = [];
            }

            $invoiceURL = $invoiceData['checkoutLink'] ?? '';
            // Stored on the payment: only this invoice can pay it.
            $invoiceId = $invoiceData['id'] ?? '';
            
            
        

            // Every invoice is kept with what it asked for, so an older one the customer still pays is accepted (guide 6.7).
            if (!empty($invoiceURL) && !empty($invoiceId)) {
                try {
                    $records = self::invoiceRecords();
                    $records->ensureTable(time());
                    $records->add(
                        (string) $invoiceId,
                        (int) $payment->getId(),
                        (int) $order_id,
                        (int) $paySystemId,
                        (string) $camount,
                        (string) $currency_code,
                        number_format((float) $payment->getSum(), 2, '.', ''),
                        (string) $order->getCurrency()
                    );
                } catch (\Throwable $e) {
                    Logger::addError(sprintf('Coinsnap: the invoice record could not be saved for order %d', (int) $order_id));
                    $invoiceURL = '';
                }
            }

            if (!empty($invoiceURL) && !empty($invoiceId)){
                
                if(!$propertyCollection->getItemByOrderPropertyCode('INVOICE_URL')){
                
                    $invoice_url = $propertyCollection->createItem(
                        [
                            'NAME' => Loc::getMessage( 'SALE_COINSNAP_INVOICE_URL' ),
                            'CODE' => 'INVOICE_URL',
                            'TYPE' => 'STRING',
                        ]
                    );

                    $invoice_url->setField('VALUE', $invoiceURL);
                
                }
                
                $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_NEW');
                
                $payment->setFields(
                    [
                        'PS_STATUS_CODE' => $status_id,
                        'PS_INVOICE_ID' => $invoiceId,
                        // What the invoice was created for (BTCPay may have converted it to BTC); the webhook checks it.
                        'PS_STATUS_MESSAGE' => 'expected ' . (string) $camount . ' ' . $currency_code . ' for ' . number_format((float) $payment->getSum(), 2, '.', '') . ' ' . $order->getCurrency(),
                        'PS_STATUS_DESCRIPTION' => Loc::getMessage("SALE_COINSNAP_STATUS_WAITING")
                    ]
                );
                
                $order->save();
                
                $this->setExtraParams([
                    'invoiceURL' => $invoiceURL,
                ]);
                return $this->showTemplate($payment, 'redirect');
            }
            else {
                $errmsg = Loc::getMessage('SALE_COINSNAP_API_ERROR');
                $this->setExtraParams([
                    'message' => $errmsg,
                ]);
                return $this->showTemplate($payment, 'template');
            }
        }
        else {
            
            if($checkInvoice['error'] === 'currencyError'){
                $err_msg = strtoupper($currency_code) .' - '.Loc::getMessage( 'SALE_COINSNAP_CURRENCY_ERROR' );
            }      
            elseif($checkInvoice['error'] === 'amountError'){
                $err_msg = Loc::getMessage( 'SALE_COINSNAP_INVOICE_DATA_ERROR' ). ' '. $checkInvoice['min_value']. ' '. strtoupper( $currency_code);
            }
            else {
                $err_msg = Loc::getMessage('SALE_COINSNAP_API_ERROR');
            }
                        
            $this->setExtraParams(['message' => $err_msg]);
            return $this->showTemplate($payment, 'template');
            
        }
    }

    /**
     * Ours only if the invoice in the body belongs to a payment of this payment system, so with two
     * Coinsnap payment systems each webhook is checked with its own store's secret (guide 6.4).
     * @param Request $request
     * @param int $paySystemId
     * @return bool
     */
    public static function isMyResponse(Request $request, $paySystemId)
    {
        $invoiceId = \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor::invoiceIdFromBody((string) file_get_contents('php://input'));
        if ($invoiceId === '') {
            return false;
        }
        $found = self::findPaymentByInvoice($invoiceId);
        return $found !== null && (int) $found['PAY_SYSTEM_ID'] === (int) $paySystemId;
    }

    /**
     * The request names only an invoice; the payment is the one the plugin recorded for that invoice at checkout.
     * @param Request $request
     * @return mixed
     */
    public function getPaymentIdFromRequest(Request $request){
        $invoiceId = \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor::invoiceIdFromBody((string) file_get_contents('php://input'));
        $found = ($invoiceId === '') ? null : self::findPaymentByInvoice($invoiceId);
        return $found !== null ? (int) $found['ID'] : false;
    }

    /** @return array{ID: int, PAY_SYSTEM_ID: int}|null */
    private static function findPaymentByInvoice(string $invoiceId): ?array
    {
        try {
            $records = self::invoiceRecords();
            $records->ensureTable(time());
            $record = $records->find($invoiceId);
        } catch (\Throwable $e) {
            Logger::addError('Coinsnap: the invoice records could not be read');
            return null;
        }
        $filter = ($record !== null) ? ['=ID' => $record['paymentId']] : ['=PS_INVOICE_ID' => $invoiceId];
        $row = \Bitrix\Sale\Payment::getList([
            'select' => ['ID', 'PAY_SYSTEM_ID'],
            'filter' => $filter,
            'limit' => 1,
        ])->fetch();
        return $row ? ['ID' => (int) $row['ID'], 'PAY_SYSTEM_ID' => (int) $row['PAY_SYSTEM_ID']] : null;
    }

    private static function invoiceRecords(): \Sale\Handlers\PaySystem\CoinsnapWebhook\InvoiceRecords
    {
        return new \Sale\Handlers\PaySystem\CoinsnapWebhook\InvoiceRecords(
            Application::getConnection(),
            static function (): string {
                return (string) Main\Config\Option::get('coinsnap.payment', 'invoice_records_since', '');
            },
            static function (string $value): void {
                Main\Config\Option::set('coinsnap.payment', 'invoice_records_since', $value);
            }
        );
    }

    /**
     * @param Payment $payment
     * @param Request $request
     * @return PaySystem\ServiceResult
     */
    public function processRequest(Payment $payment, Request $request){
        self::reply($this->webhookStatus($payment, (string) file_get_contents('php://input'), $_SERVER));
    }

    // The HTTP status for one webhook delivery.
    public function webhookStatus(Payment $payment, string $rawBody, array $server): int {
        $provider = $this->getPaymentProvider($payment);
        $stored = json_decode($this->getWebhookSecret($payment), true);
        $secret = is_array($stored) ? (string) ($stored['secret'] ?? '') : '';
        $header = ($provider === 'btcpay') ? 'HTTP_BTCPAY_SIG' : 'HTTP_X_COINSNAP_SIGNATURE';
        $signature = (string) ($server[$header] ?? '');

        $processor = new \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor(
            $provider,
            $secret,
            function (string $invoiceId) use ($payment): array {
                return (new \Coinsnap\Client\Invoice($this->getApiUrl($payment), $this->getApiKey($payment)))->getInvoice($this->getStoreId($payment), $invoiceId)->getData();
            },
            static function (string $line): void {
                // Security lines always go to the payment system error log; the rest only in debug mode.
                if (strpos($line, 'SECURITY') !== false) {
                    Logger::addError($line);
                } else {
                    Logger::addDebugInfo($line);
                }
            }
        );
        // The signature comes first: an unsigned request gets no lock and no API call.
        if (!$processor->verify($rawBody, $signature, time())) {
            return 401;
        }

        // One webhook at a time per payment, so two deliveries can't both mark it paid.
        $db = Application::getConnection();
        $lock = $db->getSqlHelper()->forSql('coinsnap_payment_' . (int) $payment->getId());
        $locked = $db->query("SELECT GET_LOCK('" . $lock . "', 10) AS L")->fetch();
        if ((int) ($locked['L'] ?? 0) !== 1) {
            return 503;
        }
        try {
            $order = SaleOrder::load($payment->getOrderId());
            $fresh = $order ? $order->getPaymentCollection()->getItemById($payment->getId()) : null;
            return $this->handleWebhook($processor, $fresh ?: $payment, $rawBody, $signature);
        } catch (\Throwable $e) {
            Logger::addError('Coinsnap: a webhook could not be processed');
            return 503;
        } finally {
            $db->query("SELECT RELEASE_LOCK('" . $lock . "')");
        }
    }

    private static function reply(int $status): void
    {
        http_response_code($status);
        echo $status === 200 ? 'OK' : ($status === 401 ? 'Unauthorized' : 'Retry');
        exit;
    }

    private function handleWebhook(\Sale\Handlers\PaySystem\CoinsnapWebhook\Processor $processor, Payment $payment, string $rawBody, string $signature): int {
        $order = $payment->getOrder();
        $invoiceId = \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor::invoiceIdFromBody($rawBody);
        $records = self::invoiceRecords();
        $now = time();
        try {
            $records->ensureTable($now);
            $record = ($invoiceId === '') ? null : $records->find($invoiceId);
        } catch (\Throwable $e) {
            Logger::addError('Coinsnap: the invoice records could not be read');
            return 503;
        }
        $statusId = (string) $order->getField('STATUS_ID');

        [$status, $action] = $processor->handle($rawBody, $signature, $now, [
            'paymentId' => (string) $payment->getId(),
            'invoiceId' => (string) $payment->getField('PS_INVOICE_ID'),
            'orderId' => (string) $order->getId(),
            'sum' => (string) $payment->getSum(),
            'expected' => (string) $payment->getField('PS_STATUS_MESSAGE'),
            'currency' => (string) $order->getCurrency(),
            'paid' => $payment->isPaid(),
            'canceled' => $order->isCanceled(),
            'record' => $record,
            'legacyAllowed' => $records->legacyAllowed($now),
            'statusIsOurs' => in_array($statusId, $this->pluginStatuses($payment), true),
            'otherPaid' => (float) $order->getPaymentCollection()->getPaidSum() > 0,
        ]);

        if ($action === \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor::NONE) {
            return $status;
        }
        if ($action === \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor::PAY) {
            // Act once per invoice, from our own record: a payment the merchant set back to unpaid stays so.
            try {
                $claimed = $records->claim($invoiceId, (int) $payment->getId(), (int) $order->getId(), (int) $payment->getPaymentSystemId());
            } catch (\Throwable $e) {
                Logger::addError('Coinsnap: the invoice record could not be updated');
                return 503;
            }
            if (!$claimed) {
                Logger::addDebugInfo(sprintf('Coinsnap INFO webhook reason=already processed invoice=%s', $invoiceId));
                return 200;
            }
            if (!$this->applyWebhookAction($action, $payment, $order)) {
                $records->release($invoiceId, (int) $payment->getId());
                return 503;
            }
            return 200;
        }
        $this->applyWebhookAction($action, $payment, $order);
        return $status;
    }

    // Order statuses this plugin sets itself; any other status was set by the merchant and is kept (guide 6.8).
    private function pluginStatuses(Payment $payment): array
    {
        $statuses = ['N'];
        foreach (['COINSNAP_STATUS_NEW', 'COINSNAP_STATUS_PRO', 'COINSNAP_STATUS_EXP'] as $code) {
            $value = (string) $this->getBusinessValue($payment, $code);
            if ($value !== '') {
                $statuses[] = $value;
            }
        }
        return $statuses;
    }

    private function applyWebhookAction(string $action, Payment $payment, $order): bool
    {
        $now = new Main\Type\DateTime();
        if ($action === \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor::PAY) {
            $statusIsOurs = in_array((string) $order->getField('STATUS_ID'), $this->pluginStatuses($payment), true);
            if ($order->isCanceled()) {
                $order->setField('CANCELED', 'N');
            }
            $payment->setPaid('Y');
            $payment->setFields([
                'PS_STATUS' => 'Y',
                'PS_STATUS_CODE' => 'Settled',
                'PS_STATUS_DESCRIPTION' => Loc::getMessage('SALE_COINSNAP_STATUS_SET'),
                'PS_STATUS_MESSAGE' => 'Settled',
                'PS_SUM' => $payment->getSum(),
                'PS_CURRENCY' => $order->getCurrency(),
                'PS_RESPONSE_DATE' => $now,
            ]);
            if ($statusIsOurs) {
                $this->setOrderStatus($order, (string) $this->getBusinessValue($payment, 'COINSNAP_STATUS_SET'));
            }
        }
        elseif ($action === \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor::PROCESSING) {
            $payment->setField('PS_STATUS_DESCRIPTION', Loc::getMessage('SALE_COINSNAP_STATUS_PRO'));
            $statusId = (string) $this->getBusinessValue($payment, 'COINSNAP_STATUS_PRO');
            // "P" is Bitrix's paid status; a payment that isn't confirmed yet must not show as paid.
            if ($statusId !== 'P') {
                $this->setOrderStatus($order, $statusId);
            }
        }
        else {
            $payment->setField('PS_STATUS_DESCRIPTION', Loc::getMessage('SALE_COINSNAP_STATUS_EXP'));
            $this->setOrderStatus($order, (string) $this->getBusinessValue($payment, 'COINSNAP_STATUS_EXP'));
            $order->setField('CANCELED', 'Y');
            $order->setField('REASON_CANCELED', 'Coinsnap invoice expired');
        }
        try {
            $result = $order->save();
        } catch (\Throwable $e) {
            Logger::addError('Coinsnap: the order could not be saved after a webhook');
            return false;
        }
        if (!$result->isSuccess()) {
            Logger::addError('Coinsnap: the order could not be saved after a webhook: ' . implode('; ', $result->getErrorMessages()));
            return false;
        }
        return true;
    }

    private function setOrderStatus($order, string $statusId): void
    {
        if ($statusId !== '' && $statusId !== (string) $order->getField('STATUS_ID')) {
            $order->setField('STATUS_ID', $statusId);
        }
    }
    
    public function getPaymentProvider($payment){
        return ($this->getBusinessValue($payment, 'COINSNAP_PROVIDER') === 'btcpay')? 'btcpay' : 'coinsnap';
    }

    public function getCurrencyList(){
    	return COINSNAP_CURRENCIES;
    }

    public function getApiUrl($payment){
        return ($this->getBusinessValue($payment, 'COINSNAP_PROVIDER') === 'btcpay')? $this->getBusinessValue($payment, 'BTCPAY_SERVER_URL') : 'https://app.coinsnap.io';
    }

    public function getStoreId($payment){
        return ($this->getBusinessValue($payment, 'COINSNAP_PROVIDER') === 'btcpay')? $this->getBusinessValue($payment, 'BTCPAY_STORE_ID') : $this->getBusinessValue($payment, 'COINSNAP_STORE_ID');
    }	

    public function getApiKey($payment){
        return ($this->getBusinessValue($payment, 'COINSNAP_PROVIDER') === 'btcpay')? $this->getBusinessValue($payment, 'BTCPAY_API_KEY') : $this->getBusinessValue($payment, 'COINSNAP_API_KEY');
    }	

    // BTCPay: the key must work on the configured server and be limited to the configured store.
    // The answer is remembered per server, key and store, so BTCPay is asked once per key.
    private function btcpayKeyUsable(Payment $payment): bool
    {
        $url = (string) $this->getApiUrl($payment);
        $key = (string) $this->getApiKey($payment);
        $storeId = (string) $this->getStoreId($payment);
        if (!\Sale\Handlers\PaySystem\CoinsnapWebhook\BtcpayKey::validServerUrl($url) || $key === '' || $storeId === '') {
            Logger::addError('Coinsnap: the BTCPay server URL, API key or store ID is missing or invalid');
            return false;
        }
        $option = 'btcpay_key_' . hash('sha256', rtrim($url, '/') . '|' . $key . '|' . $storeId);
        if (Main\Config\Option::get('coinsnap.payment', $option, '') === 'Y') {
            return true;
        }
        try {
            $permissions = \Sale\Handlers\PaySystem\CoinsnapWebhook\BtcpayKey::permissions(new \Coinsnap\Http\CurlClient(), $url, $key);
        } catch (\Throwable $e) {
            Logger::addError('Coinsnap: BTCPay Server could not be reached to check the API key');
            return false;
        }
        if ($permissions === null || !\Sale\Handlers\PaySystem\CoinsnapWebhook\BtcpayKey::acceptable($permissions, $storeId)) {
            Logger::addError('Coinsnap: the BTCPay API key was refused. Create a key limited to this store with only these permissions: ' . implode(', ', \Sale\Handlers\PaySystem\CoinsnapWebhook\BtcpayKey::NEEDED));
            return false;
        }
        Main\Config\Option::set('coinsnap.payment', $option, 'Y');
        return true;
    }

    // Kept per provider and store, so two Coinsnap payment systems never overwrite each other's secret.
    public function getWebhookSecret($payment){
        $stored = Main\Config\Option::get('coinsnap.payment', self::webhookOptionName((string) $this->getPaymentProvider($payment), (string) $this->getStoreId($payment)), '');
        if ($stored !== '') {
            return $stored;
        }
        // Versions before 1.2.0 kept one secret for all payment systems.
        $legacy = $this->getBusinessValue($payment, 'COINSNAP_WEBHOOK_SECRET');
        return ($legacy !== null && !empty($legacy)) ? $legacy : '';
    }

    private static function webhookOptionName(string $provider, string $storeId): string {
        return 'webhook_' . md5($provider . '|' . $storeId);
    }

    // From the configured server name, never the request's Host header.
    private function getWebhookUrl(){
        $serverName = trim((string) Main\Config\Option::get('main', 'server_name', ''));
        if ($serverName === '' && defined('SITE_SERVER_NAME')) {
            $serverName = trim((string) SITE_SERVER_NAME);
        }
        if ($serverName === '') {
            throw new \RuntimeException('Coinsnap: set the server URL in the main module settings');
        }
        return 'https://' . preg_replace('#^https?://#i', '', rtrim($serverName, '/')) . '/bitrix/tools/sale_ps_result.php';
    }

    private function getReturnUrl(Payment $payment){
        return $this->getBusinessValue($payment, 'COINSNAP_RETURNURL') ? : $this->service->getContext()->getUrl();
    }

    // Throws when the API can't be asked; the caller must not treat that as "no webhook".
    public function webhookExists(string $apiUrl, string $apiKey, string $storeId, string $webhook): bool {
        $whClient = new \Coinsnap\Client\Webhook($apiUrl, $apiKey);
        $stored = json_decode($webhook, true);

        if (is_array($stored) && !empty($stored['id']) && !empty($stored['secret'])) {
            try {
                $existing = $whClient->getWebhook($storeId, (string) $stored['id']);
                if (strpos((string) ($existing->getData()['url'] ?? ''), $this->getWebhookUrl()) !== false) {
                    return true;
                }
            } catch (\Coinsnap\Exception\RequestException $e) {
                if (!in_array($e->getCode(), [400, 404], true)) {
                    throw $e;
                }
            }
        }
        // Remove webhooks of ours whose secret this shop does not hold.
        foreach ($whClient->getWebhooks($storeId) as $existingWebhook) {
            if (strpos((string) $existingWebhook->getData()['url'], $this->getWebhookUrl()) !== false) {
                try {
                    $whClient->deleteWebhook($storeId, $existingWebhook->getData()['id']);
                } catch (\Throwable $e) {
                    Logger::addError('Coinsnap: a stale webhook could not be deleted');
                }
            }
        }
        return false;
    }

    public function registerWebhook(string $apiUrl, $apiKey, $storeId, $provider){
        try {
            $whClient = new Webhook($apiUrl, $apiKey);
            $webhook_events = ($provider === 'btcpay')? self::BTCPAY_WEBHOOK_EVENTS : self::COINSNAP_WEBHOOK_EVENTS;
            $webhook = $whClient->createWebhook($storeId, $this->getWebhookUrl(), $webhook_events, null)->getData();
        }
        catch (\Throwable $e) {
            Logger::addError(sprintf('Coinsnap: the webhook could not be registered (status %d)', (int) $e->getCode()));
            return false;
        }
        if (empty($webhook['id']) || empty($webhook['secret'])) {
            return false;
        }

        $value = json_encode(['id' => $webhook['id'], 'secret' => $webhook['secret'], 'url' => $webhook['url'] ?? $this->getWebhookUrl()]);
        Main\Config\Option::set('coinsnap.payment', self::webhookOptionName((string) $provider, (string) $storeId), $value);
        return true;
    }
}
