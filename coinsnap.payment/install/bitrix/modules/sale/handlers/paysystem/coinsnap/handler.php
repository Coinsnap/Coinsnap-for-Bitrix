<?php
namespace Sale\Handlers\PaySystem;

use Bitrix\Main;
use Bitrix\Main\Request;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\Payment;
use Bitrix\Main\Application;
use Bitrix\Sale\PriceMaths;
use Bitrix\Sale\Order as SaleOrder;
use Bitrix\Sale\PaySystem\ServiceResult;
use Bitrix\Sale\PaySystem\Logger;
use Coinsnap\Client\Webhook;
Loc::loadMessages(__FILE__);

if(!defined('COINSNAP_BITRIX_VERSION')){ define( 'COINSNAP_BITRIX_VERSION', '1.0.0' ); }
if(!defined('COINSNAP_BITRIX_REFERRAL_CODE')){ define( 'COINSNAP_BITRIX_REFERRAL_CODE', 'D84007' ); }
if(!defined('COINSNAP_CURRENCIES')){ define( 'COINSNAP_CURRENCIES', array("EUR","USD","SATS","BTC","CAD","JPY","GBP","CHF","RUB") ); }
if(!defined('COINSNAP_SERVER_URL')){ define( 'COINSNAP_GIVEWP_SERVER_URL', 'https://app.coinsnap.io' );}
if(!defined('COINSNAP_API_PATH')){define( 'COINSNAP_API_PATH', '/api/v1/');}
if(!defined('COINSNAP_SERVER_PATH')){define( 'COINSNAP_SERVER_PATH', 'stores' );}

require_once(__DIR__  . '/library/loader.php');	
class CoinsnapHandler extends PaySystem\ServiceHandler
{
    public const WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];
    
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
        
        if (! $this->webhookExists($this->getApiUrl(), $this->getApiKey($payment), $this->getStoreId($payment))){
            if (! $this->registerWebhook($this->getApiUrl(), $this->getApiKey($payment), $this->getStoreId($payment))){
                $err_msg = Loc::getMessage('SALE_COINSNAP_WEBHOOK_ERROR');
                $this->setExtraParams([
                    'message' => $err_msg,
		]);
                return $this->showTemplate($payment, 'template');
            }
	}

        $amount = number_format($payment->getSum(),2);
        
        $client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey($payment) );
        $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper($currency_code));
        
        if($checkInvoice['result'] === true){
            
            $buyerName =  is_null($propertyCollection->getPayerName()) ? '' : $propertyCollection->getPayerName()->getValue();
            $buyerEmail = is_null($propertyCollection->getUserEmail()) ? '' : $propertyCollection->getUserEmail()->getValue();

            $redirectUrl = $this->getReturnUrl($payment);
		
            $metadata = [];
            $metadata['orderNumber'] = $order_id;
            $metadata['customerName'] = $buyerName;

            $redirectAutomatically = ($this->getBusinessValue($payment, 'COINSNAP_AUTOREDIRECT') === 'N')? false : true;
            $walletMessage = '';
        
            $camount = \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
            $invoice = $client->createInvoice(
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
            );

            $invoiceURL = $invoice->getData()['checkoutLink'];
            $invoiceId = $invoice->getData()['id'];

            if (!empty($invoiceURL)){
                
                if(!$propertyCollection->getItemByOrderPropertyCode('INVOICE_URL')){
                
                $invoice_url = $propertyCollection->createItem(
                    [
                        'NAME' => 'INVOICE URL',
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
	$inputStream = file_get_contents('php://input');
        if ($inputStream){			
            $data = json_decode($inputStream, true);
            if ($data !== false && isset($data["invoiceId"])){ return true;}
        }
        return false;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPaymentIdFromRequest(Request $request){
        $inputStream = file_get_contents('php://input');	
        Logger::addDebugInfo("Coinsnap Webhook Response: " . $inputStream);
        $data = json_decode($inputStream, true);
        $orderNumber =  $data['metadata']['orderNumber'];
        return $orderNumber;
    }

    /**
     * @param Payment $payment
     * @param Request $request
     * @return PaySystem\ServiceResult
     */
    public function processRequest(Payment $payment, Request $request){
		
        try {
            // First check if we have any input
            $rawPostData = file_get_contents("php://input");
            if (!$rawPostData) {
                    wp_die('No raw post data received', '', ['response' => 400]);
            }

            // Get headers and check for signature
            $headers = getallheaders();
            $signature = null; $payloadKey = null;
            $_provider = ($this->get_payment_provider() === 'btcpay')? 'btcpay' : 'coinsnap';
                
            foreach ($headers as $key => $value) {
                if ((strtolower($key) === 'x-coinsnap-sig' && $_provider === 'coinsnap') || (strtolower($key) === 'btcpay-sig' && $_provider === 'btcpay')) {
                        $signature = $value;
                        $payloadKey = strtolower($key);
                }
            }

            // Handle missing or invalid signature
            if (!isset($signature)) {
                wp_die('Authentication required', '', ['response' => 401]);
            }

            // Validate the signature
            $webhookSecret = $this->getWebhookSecret($payment);
            if (!Webhook::isIncomingWebhookRequestValid($rawPostData, $signature, $webhookSecret)) {
                wp_die('Invalid authentication signature', '', ['response' => 401]);
            }

            // Parse the JSON payload
            $postData = json_decode($rawPostData, false, 512, JSON_THROW_ON_ERROR);

            if (!isset($postData->invoiceId)) {
                wp_die('No Coinsnap invoiceId provided', '', ['response' => 400]);
            }
            
            $invoice_id = $postData->invoiceId;
            $status = 'New';
            
            if(strpos($invoice_id,'test_') !== false){
                wp_die('Successful webhook test', '', ['response' => 200]);
            }
            
            $client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey($payment) );			
            $invoice = $client->getInvoice($this->getStoreId($payment), $invoice_id);
            $status = $invoice->getData()['status'] ;
            $orderId = $invoice->getData()['orderId'];
            
            $order = $payment->getOrder();        
            $currency_code = $order->getCurrency();
            
            $order_status = 'pending';
            if ($status == 'Expired'){ $order_status = give_get_option('coinsnap_expired_status'); }
            else if ($status == 'Processing'){ $order_status = give_get_option('coinsnap_processing_status'); }
            else if ($status == 'Settled'){ $order_status = give_get_option('coinsnap_settled_status'); }
            
            $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_'.strtoupper(substr($status,0,3)));
            /*
            if ($status == 'New'){ $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_NEW'); }
            else if ($status == 'Expired'){ $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_EXP'); }
            else if ($status == 'Processing'){ $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_PRO'); }
            else if ($status == 'Settled'){ $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_SET');	}
            */

            $description = Loc::getMessage("SALE_COINSNAP_TRANSACTION").$invoice_id;        

            $arFields = array(
                'STATUS_ID' =>$status_id,
                'PS_STATUS_DESCRIPTION' => $description,
                'PS_STATUS_MESSAGE' => $status,			
            );

            //  If payment is received
            if ($status_id === 'P') {
                $arFields = array(
                    'STATUS_ID' => $status_id,
                    'PAYED' =>  'Y',
                    'PS_STATUS' => 'Y',
                    'PS_STATUS_CODE' => $status_id,
                    'PS_STATUS_DESCRIPTION' => $description,
                    'PS_STATUS_MESSAGE' => $status,
                    'PS_SUM' => $payment->getSum(),
                    'PS_CURRENCY' => $currency_code,
                    'PS_RESPONSE_DATE' => new Main\Type\DateTime(),
                );
            }

            if ($status === 'Expired') {     
                $arFields['PAYED'] =  'N';
                $arFields['PS_STATUS'] = 'N';
                $arFields['CANCELED'] = 'Y';
                $arFields['DATE_CANCELED'] = new Main\Type\DateTime();
                $arFields['REASON_CANCELED'] = $status;	        
            }

            \CSaleOrder::Update($orderId, $arFields);
            echo "OK";
            exit;
        }
        catch (JsonException $e) {
            wp_die('Invalid JSON payload', '', ['response' => 400]);
        }
        catch (\Throwable $e) {
            wp_die('Internal server error', '', ['response' => 500]);
        }
    }

    public function getCurrencyList(){
    	return COINSNAP_CURRENCIES;
    }

    public function getApiUrl(){
        return 'https://app.coinsnap.io';
    }

    public function getStoreId($payment){
        return $this->getBusinessValue($payment, 'COINSNAP_STORE_ID');
    }	

    public function getApiKey($payment){
        return $this->getBusinessValue($payment, 'COINSNAP_API_KEY');
    }	

    public function getWebhookSecret($payment){
        return $this->getBusinessValue($payment, 'COINSNAP_WEBHOOK_SECRET');
    }	

    private function getWebhookUrl(){        
        $server = \Bitrix\Main\Application::getInstance()->getContext()->getServer();
        $server_uri = $server->getRequestScheme().'://'.$server->getServerName();
        return $server_uri.'/bitrix/tools/sale_ps_result.php';
    }

    private function getReturnUrl(Payment $payment){
        return $this->getBusinessValue($payment, 'RESPONSE_URL') ?: $this->service->getContext()->getUrl();
    }

    public function webhookExists(string $apiUrl, string $apiKey, string $storeId): bool {
	$whClient = new Webhook( $apiUrl, $apiKey );
        $storedWebhookSecret = $this->getWebhookSecret($payment);
	if (!empty($storedWebhookSecret)) {
            
            try {
		$existingWebhook = $whClient->getWebhook( $storeId, $storedWebhook['id'] );
                
                if($existingWebhook->getData()['secret'] === $storedWebhookSecret && strpos( $existingWebhook->getData()['url'], $this->getWebhookUrl() ) !== false){
                    return true;
		}
            }
            catch (\Throwable $e) {
		$errorMessage = Loc::getMessage('SALE_COINSNAP_WEBHOOK_FETCHING_ERROR');
                $this->setExtraParams([
                    'message' => $errorMessage . $e->getMessage(),
		]);
            }
	}
        try {
            $storeWebhooks = $whClient->getWebhooks( $storeId );
            foreach($storeWebhooks as $webhook){
                if(strpos( $webhook->getData()['url'], $this->getWebhookUrl() ) !== false){
                    $whClient->deleteWebhook( $storeId, $webhook->getData()['id'] );
                }
            }
        }
        catch (\Throwable $e) {
            $errorMessage = Loc::getMessage('SALE_COINSNAP_WEBHOOKS_LIST_FETCHING_ERROR');
                $this->setExtraParams([
                    'message' => $errorMessage . $e->getMessage(),
		]);
        }
        
	return false;
    }
    
    public function registerWebhook(string $apiUrl, $apiKey, $storeId){
        
        
        
        
            
        try {
            $whClient = new Webhook( $apiUrl, $apiKey );
            $webhook = $whClient->createWebhook(
                $storeId,   //$storeId
		$this->getWebhookUrl(), //$url
		self::WEBHOOK_EVENTS,   //$specificEvents
		null    //$secret
            );
            
            $db = new \CDatabase();
            $db->PrepareFields("b_sale_bizval");
            $db->StartTransaction();
            $db->Update('b_sale_bizval',["PROVIDER_VALUE" => "'".$webhook->getData()['secret']]."'", "WHERE `CODE_KEY` = `COINSNAP_WEBHOOK_SECRET`");
            $db->Commit();
            return $webhook;
                        
	}
        catch (\Throwable $e) {
            
            $errorMessage = Loc::getMessage('SALE_COINSNAP_WEBHOOK_CREATING_ERROR');
                $this->setExtraParams([
                    'message' => $errorMessage . $e->getMessage(),
		]);
	}

	return null;
    }
}
