<?php

declare(strict_types=1);

namespace Coinsnap\Client;

/**
 * Global class to handle OnChain and LightningNetwork payment methods.
 *
 * Adds some boilerplate and makes sure the results for the global endpoint and
 * the specific OnChain and LN endpoint are the same.
 */
class StorePaymentMethod extends AbstractClient
{
    public function getPaymentMethods(string $storeId): array
    {
        $url = $this->getApiUrl() . ''.COINSNAP_SERVER_PATH.'/' . urlencode($storeId) . '/payment-methods';
        $headers = $this->getRequestHeaders();
        $method = 'GET';
        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            $pm = new \Coinsnap\Result\StorePaymentMethodCollection(json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
            return $pm->getPaymentMethods();
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

    public function getPaymentMethod(string $storeId, string $paymentMethod): \Coinsnap\Result\AbstractStorePaymentMethodResult
    {
        $paymentType = $this->determinePaymentType($paymentMethod);
        $pmObject = $this->getInstance($paymentType['type']);
        return $pmObject->getPaymentMethod($storeId, $paymentType['code']);
    }

    /**
     * Updates OnChain or LightningNetwork payment methods. You can enable/disable
     * them or change their settings.
     *
     * @param string $storeId
     * @param string $paymentMethod Payment method
     * @param array $settings See updatePaymentMethod functions of
     *                        StorePaymentMethodLightningNetwork and
     *                        StorePaymentMethodOnChain classes for what you can
     *                        pass on each of them.
     *
     * @return \Coinsnap\Result\AbstractStorePaymentMethodResult
     *
     * @see StorePaymentMethodOnChain::updatePaymentMethod()
     * @see StorePaymentMethodLightningNetwork::updatePaymentMethod()
     */
    public function updatePaymentMethod(string $storeId, string $paymentMethod, array $settings): \Coinsnap\Result\AbstractStorePaymentMethodResult
    {
        $paymentType = $this->determinePaymentType($paymentMethod);
        $pmObject = $this->getInstance($paymentType['type']);
        return $pmObject->updatePaymentMethod($storeId, $paymentType['code'], $settings);
    }

    /**
     * Disable the corresponding payment method. For OnChain payment methods
     * this will also delete your configured xpub and/or hot wallet.
     *
     * @param string $storeId
     * @param string $paymentMethod Payment method
     *
     * @return bool
     */
    public function removePaymentMethod(string $storeId, string $paymentMethod): bool
    {
        $paymentType = $this->determinePaymentType($paymentMethod);
        $pmObject = $this->getInstance($paymentType['type']);
        return $pmObject->removePaymentMethod($storeId, $paymentType['code']);
    }

    /**
     * Helper function to extract cryptoCode and payment type from the string.
     * @param string $paymentMethod Payment method
     * @return array
     */
    private function determinePaymentType(string $paymentMethod): array
    {
        $parts = explode('-', $paymentMethod, 2);

        switch (count($parts)) {
            case 1:
                return [
                  'code' => $parts[0],
                  'type' => 'OnChain'
                ];
                break;
            case 2:
                return [
                  'code' => $parts[0],
                  'type' => $parts[1]
                ];
                break;
            default:
                return [];
        }
    }

    /**
     * Instantiate the needed payment client object.
     */
    private function getInstance(string $paymentType): AbstractStorePaymentMethodClient
    {
        $className = '\Coinsnap\Client\StorePaymentMethod' . $paymentType;
        return new $className($this->getBaseUrl(), $this->getApiKey());
    }
}
