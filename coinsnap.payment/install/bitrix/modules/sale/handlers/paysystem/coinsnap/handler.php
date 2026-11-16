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
     * @param Request $request
     * @param int $paySystemId
     * @return bool
     */
    public static function isMyResponse(Request $request, $paySystemId)
    {
        return \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor::invoiceIdFromBody((string) file_get_contents('php://input')) !== '';
    }

    /**
     * The request names only an invoice; the payment is the one that stored that invoice id at checkout.
     * @param Request $request
     * @return mixed
     */
    public function getPaymentIdFromRequest(Request $request){
        $invoiceId = \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor::invoiceIdFromBody((string) file_get_contents('php://input'));
        if ($invoiceId === '') {
            return false;
        }
        $row = \Bitrix\Sale\Payment::getList([
            'select' => ['ID'],
            'filter' => ['=PS_INVOICE_ID' => $invoiceId],
            'limit' => 1,
        ])->fetch();
        return $row ? (int) $row['ID'] : false;
    }

    /**
     * @param Payment $payment
     * @param Request $request
     * @return PaySystem\ServiceResult
     */
    public function processRequest(Payment $payment, Request $request){
        $provider = $this->getPaymentProvider($payment);
        $stored = json_decode($this->getWebhookSecret($payment), true);
        $secret = is_array($stored) ? (string) ($stored['secret'] ?? '') : '';
        $header = ($provider === 'btcpay') ? 'HTTP_BTCPAY_SIG' : 'HTTP_X_COINSNAP_SIGNATURE';
        $order = $payment->getOrder();

        $processor = new \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor(
            $provider,
            $secret,
            function (string $invoiceId) use ($payment): array {
                return (new \Coinsnap\Client\Invoice($this->getApiUrl($payment), $this->getApiKey($payment)))->getInvoice($this->getStoreId($payment), $invoiceId)->getData();
            },
            static function (string $line): void {
                Logger::addDebugInfo($line);
            }
        );
        [$status, $action] = $processor->handle(
            (string) file_get_contents('php://input'),
            (string) ($_SERVER[$header] ?? ''),
            time(),
            [
                'invoiceId' => (string) $payment->getField('PS_INVOICE_ID'),
                'orderId' => (string) $order->getId(),
                'sum' => (string) $payment->getSum(),
                'currency' => (string) $order->getCurrency(),
                'paid' => $payment->isPaid(),
                'canceled' => $order->isCanceled(),
            ]
        );

        if ($action !== \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor::NONE) {
            $this->applyWebhookAction($action, $payment, $order);
        }
        http_response_code($status);
        echo $status === 200 ? 'OK' : ($status === 401 ? 'Unauthorized' : 'Retry');
        exit;
    }

    private function applyWebhookAction(string $action, Payment $payment, $order): void
    {
        $now = new Main\Type\DateTime();
        if ($action === \Sale\Handlers\PaySystem\CoinsnapWebhook\Processor::PAY) {
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
            $this->setOrderStatus($order, (string) $this->getBusinessValue($payment, 'COINSNAP_STATUS_SET'));
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
        $result = $order->save();
        if (!$result->isSuccess()) {
            Logger::addError('Coinsnap: the order could not be saved after a webhook: ' . implode('; ', $result->getErrorMessages()));
        }
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

    public function getWebhookSecret($payment){
        return ($this->getBusinessValue($payment, 'COINSNAP_WEBHOOK_SECRET') !== null && !empty($this->getBusinessValue($payment, 'COINSNAP_WEBHOOK_SECRET')))? 
                $this->getBusinessValue($payment, 'COINSNAP_WEBHOOK_SECRET') : '';
    }	

    private function getWebhookUrl(){        
        $server = \Bitrix\Main\Application::getInstance()->getContext()->getServer();
        $server_uri = $server->getRequestScheme().'://'.$server->getServerName();
        return $server_uri.'/bitrix/tools/sale_ps_result.php';
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
        $db = \Bitrix\Main\Application::getConnection();
        $helper = $db->getSqlHelper();
        $exists = $db->query("SELECT `CODE_KEY` FROM b_sale_bizval WHERE CODE_KEY = 'COINSNAP_WEBHOOK_SECRET'")->fetch();
        if ($exists) {
            $db->queryExecute("UPDATE b_sale_bizval SET PROVIDER_VALUE = '" . $helper->forSql($value) . "' WHERE CODE_KEY = 'COINSNAP_WEBHOOK_SECRET'");
        }
        else {
            $db->add('b_sale_bizval', [
                'PROVIDER_VALUE' => $value,
                'CODE_KEY' => 'COINSNAP_WEBHOOK_SECRET'
            ]);
        }
        return true;
    }
}
