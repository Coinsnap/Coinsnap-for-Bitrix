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

Loc::loadMessages(__FILE__);
if(!defined( 'COINSNAP_BITRIX_REFERRAL_CODE' )){
    define( 'COINSNAP_BITRIX_REFERRAL_CODE', 'D84007' );
}

require_once(__DIR__  . '/library/loader.php');	
class CoinsnapHandler extends PaySystem\ServiceHandler
{
    public function initiatePay(Payment $payment, Request $request = null)
    {
        $busValues = $this->getParamsBusValue($payment);
        $order = $payment->getOrder();
        $order_id = $order->getId();
        $currency_code = $order->getCurrency();
        if ($payment->isPaid()) {
            return $this->showTemplate($payment, 'template');
        }

        $paySystemId = $payment->getPaymentSystemId();        
        $url =  $this->getWebhookUrl();			
        
        if (! $this->webhookExists($this->getStoreId($payment), $this->getApiKey($payment), $url)){
            if (! $this->registerWebhook($this->getStoreId($payment), $this->getApiKey($payment), $url)){
                $err_msg = Loc::getMessage('COINSNAP_PAYMENT_PAID');
                $this->setExtraParams([
                    'message' => $err_msg,
		]);
		return $this->showTemplate($payment, 'template');
            }
	}

        $amount = number_format($payment->getSum(),2);
        $redirectUrl = $this->getReturnUrl($payment);
		
        $propertyCollection = $order->getPropertyCollection();

	$buyerName =  $propertyCollection->getPayerName()->getValue();
	$buyerEmail = $propertyCollection->getUserEmail()->getValue();
                
        $metadata = [];
	$metadata['orderNumber'] = $order_id;
	$metadata['customerName'] = $buyerName;
		
	$checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
		
	//$checkoutOptions->setRedirectURL( $redirectUrl );
	$client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey($payment) );			
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
			$checkoutOptions
		);
		
	$payurl = $invoice->getData()['checkoutLink'] ;
				
	if (!empty($payurl)){
            $this->setExtraParams([
                'url' => $payurl,
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
	public function getPaymentIdFromRequest(Request $request)
	{
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
    public function processRequest(Payment $payment, Request $request)
    {
		
		$notify_json = file_get_contents('php://input');	
		$notify_ar = json_decode($notify_json, true);
		$invoice_id =  $notify_ar['invoiceId'];
		$status = 'New';

		try {
			$client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey($payment) );			
			$invoice = $client->getInvoice($this->getStoreId($payment), $invoice_id);
			$status = $invoice->getData()['status'] ;
			$orderId = $invoice->getData()['orderId'] ;
			
	
		}catch (\Throwable $e) {			
			echo "Fail";
			exit;
		}

		$order = $payment->getOrder();        
        $currency_code = $order->getCurrency();
				
                if ($status == 'New'){ $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_NEW'); }
                else if ($status == 'Expired'){ $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_EXP'); }
                else if ($status == 'Processing'){ $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_SET'); }
                else if ($status == 'Settled'){ $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_PRO');	}

		
		
        $description = Loc::getMessage("SALE_COINSNAP_TRANSACTION").$invoice_id;        
		
		$arFields = array(
			'STATUS_ID' =>$status_id,
			'PS_STATUS_DESCRIPTION' => $description,
			'PS_STATUS_MESSAGE' => $status,			
		);
		if ($status_id == 'P') {
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
		if ($status == 'Expired') {     
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

    /**
     * @return array
     */
    public function getCurrencyList()
    {
    	return ['RUB', 'KZT', 'USD', 'EUR'];
    }




	public function getApiUrl()
	{
		return 'https://app.coinsnap.io';
	}

	public function getStoreId($payment)
	{
		return $this->getBusinessValue($payment, 'COINSNAP_STORE_ID');
	}	

	public function getApiKey($payment)
	{
		return $this->getBusinessValue($payment, 'COINSNAP_API_KEY');
	}	

    private function getWebhookUrl()
    {        
        return 'https://'.$_SERVER['HTTP_HOST'].'/bitrix/tools/sale_ps_result.php';
    }

    private function getReturnUrl(Payment $payment)
    {
        return $this->getBusinessValue($payment, 'RESPONSE_URL') ?: $this->service->getContext()->getUrl();
    }

    public function webhookExists(string $storeId, string $apiKey, string $webhook): bool {	
		try {		
			$whClient = new \Coinsnap\Client\Webhook( $this->getApiUrl(), $apiKey );		
			$Webhooks = $whClient->getWebhooks( $storeId );
			
			foreach ($Webhooks as $Webhook){					
				//$this->deleteWebhook($storeId,$apiKey, $Webhook->getData()['id']);
				if ($Webhook->getData()['url'] == $webhook) return true;	
			}
		}catch (\Throwable $e) {						
			return false;
		}
	
		return false;
	}
	public function registerWebhook(string $storeId, string $apiKey, string $webhook): bool {	
		try {			
			$whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);
			
			$webhook = $whClient->createWebhook(
				$storeId,   //$storeId
				$webhook, //$url
				['New','Expired','Settled','Processing'],   //$specificEvents
				null    //$secret
			);								
			return true;
		} catch (\Throwable $e) {
			print_r($e);
			exit;
			return false;	
		}

		return false;
	}

	public function deleteWebhook(string $storeId, string $apiKey, string $webhookid): bool {	    
		
		try {			
			$whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);
			
			$webhook = $whClient->deleteWebhook(
				$storeId,   //$storeId
				$webhookid, //$url			
			);					
			return true;
		} catch (\Throwable $e) {
			
			return false;	
		}


    }
 
}
