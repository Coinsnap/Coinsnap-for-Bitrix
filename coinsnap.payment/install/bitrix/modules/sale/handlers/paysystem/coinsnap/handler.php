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

require_once(__DIR__  . '/lib/autoload.php');	
class CoinsnapHandler extends PaySystem\ServiceHandler
{

    public const WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];
   
    public function initiatePay(Payment $payment, Request $request = null)
    {
        $busValues = $this->getParamsBusValue($payment);
        $order = $payment->getOrder();
        $order_id = $order->getId();
        $currency_code = $order->getCurrency();
        if ($payment->isPaid()) {
            return $this->showTemplate($payment, 'template');
        }

		$url =  $this->getWebhookUrl();
		
			
		if (! $this->webhookExists($this->getStoreId($payment), $this->getApiKey($payment), $url)){
			if (! $this->registerWebhook($this->getStoreId($payment), $this->getApiKey($payment), $url)){
				$err_msg = 'Unable to Set Webhook, Check Store ID and API Key';
				$this->setExtraParams([
					'message' => $err_msg,
				]);
				return $this->showTemplate($payment, 'template');
			}
		}

        $amount = number_format($payment->getSum(),2);
						
		$redirectUrl = $this->getReturnUrl($payment);
		$notifyURL  = $this->getWebhookUrl($payment);

        $propertyCollection = $order->getPropertyCollection();

		$buyerName =   $propertyCollection->getPayerName()->getValue();
		$buyerEmail = $order->getPropertyCollection()->getUserEmail()->getValue();

		$metadata = [];
		$metadata['orderNumber'] = $order_id;
		$metadata['customerName'] = $buyerName;
		
		$checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
		
		$checkoutOptions->setRedirectURL( $redirectUrl );
		$client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getStoreId($payment) );			
		$camount = \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
		$invoice = $client->createInvoice(
			$this->getStoreId($payment),  
			$currency_code,
			$camount,
			$order_id,
			$buyerEmail,
			$buyerName, 
			$redirectUrl,
			'',     
			$metadata,
			$checkoutOptions
		);
		
		$payurl = $invoice->getData()['checkoutLink'] ;
				
		
		if (!empty($payurl)){				
			$invoice_id = $invoice->getData()['id'] ;			            
            return LocalRedirect($payurl, true);
		}
		else {
			$errmsg = $this->l("API Error");	
            $this->setExtraParams([
                'message' => $errmsg,
            ]);
            return $this->showTemplate($payment, 'template');
		}                
    }


	/**
	 * @param Request $request
	 * @return mixed
	 */
	public function getPaymentIdFromRequest(Request $request)
	{
		$inputStream = self::readFromStream();
		$data = self::decode($inputStream);

		return $data['invoiceId'];
	}
   
    /**
     * @param Payment $payment
     * @param Request $request
     * @return PaySystem\ServiceResult
     */
    public function processRequest(Payment $payment, Request $request)
    {
		$notify_json = self::readFromStream();
		$notify_ar = self::decode($inputStream);
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
		
		if ($status == 'New') $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_NEW');
		else if ($status == 'Expired') $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_EXP');
		else if ($status == 'Processing') $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_SET');
		else if ($status == 'Settled') $status_id = $this->getBusinessValue($payment, 'COINSNAP_STATUS_PRO');	
		
		$datetimeFormat = $GLOBALS['DB']->DateFormatToPHP(CSite::GetDateFormat('FULL'));
        $description = sprintf('Coinsnap Invoice ID: %s Status: %s', $invoice,$status);
        $orderStatusApproved = $this->getOrderStatusApproved();
		if ($status_id == 'P') {
        	$arFields = array(
            	'STATUS_ID' => $orderStatus,
            	'PAYED' =>  'Y',
            	'PS_STATUS' => 'Y',
            	'PS_STATUS_CODE' => $status_id,
            	'PS_STATUS_DESCRIPTION' => $description,
            	'PS_STATUS_MESSAGE' => $status_id,
            	'PS_SUM' => $data['amount'],
            	'PS_CURRENCY' => $data['currency'],
            	'PS_RESPONSE_DATE' => date($datetimeFormat),
        	);
		}
		if ($status_id == 'D') {
        	if ($frisbeeService->isOrderDeclined()) {
            	$arFields['CANCELED'] = 'Y';
        	    $arFields['DATE_CANCELED'] = date($datetimeFormat);
    	        $arFields['REASON_CANCELED'] = $status;
	        }
		}

        CSaleOrder::Update($orderId, $arFields);

		
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


/**
	 * @param Request $request
	 * @param int $paySystemId
	 * @return bool
	 */
	public static function isMyResponse(Request $request, $paySystemId)
	{
		$inputStream = self::readFromStream();
		if ($inputStream)
		{
			$data = self::decode($inputStream);
			if ($data !== false && isset($data["invoiceId"])) return true;			
		}

		return false;
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
			print_r($Webhooks);
			exit;
			
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
				self::WEBHOOK_EVENTS,   //$specificEvents
				null    //$secret
			);								
			return true;
		} catch (\Throwable $e) {
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
