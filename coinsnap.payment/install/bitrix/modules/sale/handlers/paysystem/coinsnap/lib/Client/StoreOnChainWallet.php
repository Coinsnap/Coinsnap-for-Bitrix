<?php

declare(strict_types=1);

namespace Coinsnap\Client;

class StoreOnChainWallet extends AbstractClient
{
    public function getStoreOnChainWalletOverview(
        string $storeId,
        string $cryptoCode
    ): \Coinsnap\Result\StoreOnChainWallet {
        $url = $this->getApiUrl() . ''.COINSNAP_SERVER_PATH.'/' .
                    urlencode($storeId) . '/payment-methods' . '/OnChain' . '/' .
                    urlencode($cryptoCode) . '/wallet';

        $headers = $this->getRequestHeaders();
        $method = 'GET';

        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            return new \Coinsnap\Result\StoreOnChainWallet(
                json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)
            );
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

    public function getStoreOnChainWalletFeeRate(
        string $storeId,
        string $cryptoCode,
        ?int $blockTarget = null
    ): \Coinsnap\Result\StoreOnChainWalletFeeRate {
        $url = $this->getApiUrl() . ''.COINSNAP_SERVER_PATH.'/' .
                    urlencode($storeId) . '/payment-methods' . '/OnChain' . '/' .
                    urlencode($cryptoCode) . '/wallet' . '/feeRate';

        if (isset($blockTarget)) {
            $url .= '/?blockTarget=' . $blockTarget;
        }

        $headers = $this->getRequestHeaders();
        $method = 'GET';

        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            return new \Coinsnap\Result\StoreOnChainWalletFeeRate(
                json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)
            );
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

    public function getStoreOnChainWalletAddress(
        string $storeId,
        string $cryptoCode,
        ?string $forceGenerate = 'false'
    ): \Coinsnap\Result\StoreOnChainWalletAddress {
        $url = $this->getApiUrl() . ''.COINSNAP_SERVER_PATH.'/' .
                    urlencode($storeId) . '/payment-methods' . '/OnChain' . '/' .
                    urlencode($cryptoCode) . '/wallet' . '/address';

        if (isset($forceGenerate)) {
            $url .= '/?forceGenerate=' . $forceGenerate;
        }

        $headers = $this->getRequestHeaders();
        $method = 'GET';

        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            return new \Coinsnap\Result\StoreOnChainWalletAddress(
                json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)
            );
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

    public function unReserveLastStoreOnChainWalletAddress(
        string $storeId,
        string $cryptoCode
    ): bool {
        $url = $this->getApiUrl() . ''.COINSNAP_SERVER_PATH.'/' .
                    urlencode($storeId) . '/payment-methods' . '/OnChain' . '/' .
                    urlencode($cryptoCode) . '/wallet' . '/address';

        $headers = $this->getRequestHeaders();
        $method = 'DELETE';

        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            return true;
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

    public function getStoreOnChainWalletTransactions(
        string $storeId,
        string $cryptoCode,
        ?array $statusFilters = null,
        ?int $skip = null,
        ?int $limit = null
    ): \Coinsnap\Result\StoreOnChainWalletTransactionList {
        $url = $this->getApiUrl() . ''.COINSNAP_SERVER_PATH.'/' .
                    urlencode($storeId) . '/payment-methods' . '/OnChain' . '/' .
                    urlencode($cryptoCode) . '/wallet' . '/transactions/?';

        $queryParameters = [
            'skip' => $skip,
            'limit' => $limit
        ];

        $url .= http_build_query($queryParameters);

        // Add each statusFilter to the query if one or more are set.
        if (isset($statusFilters)) {
            foreach ($statusFilters as $statusFilter) {
                $url .= '&statusFilter=' . $statusFilter;
            }
        }

        $headers = $this->getRequestHeaders();
        $method = 'GET';

        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            return new \Coinsnap\Result\StoreOnChainWalletTransactionList(
                json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)
            );
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

    public function createStoreOnChainWalletTransaction(
        string $storeId,
        string $cryptoCode,
        array $destinations,
        ?float $feeRate,
        ?bool $proceedWithPayjoin = true,
        ?bool $proceedWithBroadcast = true,
        ?bool $noChange = false,
        ?bool $rbf = null,
        ?array $selectedInputs = null
    ): \Coinsnap\Result\StoreOnChainWalletTransaction {
        $url = $this->getApiUrl() . ''.COINSNAP_SERVER_PATH.'/' .
                    urlencode($storeId) . '/payment-methods' . '/OnChain' . '/' .
                    urlencode($cryptoCode) . '/wallet' . '/transactions';

        $headers = $this->getRequestHeaders();
        $method = 'POST';

        $body = json_encode(
            [
                'destinations' => $destinations,
                'feeRate' => $feeRate,
                'proceedWithPayjoin' => $proceedWithPayjoin,
                'proceedWithBroadcast' => $proceedWithBroadcast,
                'noChange' => $noChange,
                'rbf' => $rbf,
                'selectedInputs' => $selectedInputs
            ],
            JSON_THROW_ON_ERROR
        );

        $response = $this->getHttpClient()->request($method, $url, $headers, $body);

        if ($response->getStatus() === 200) {
            return new \Coinsnap\Result\StoreOnChainWalletTransaction(
                json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)
            );
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

    public function getStoreOnChainWalletTransaction(
        string $storeId,
        string $cryptoCode,
        string $transactionId
    ): \Coinsnap\Result\StoreOnChainWalletTransaction {
        $url = $this->getApiUrl() . ''.COINSNAP_SERVER_PATH.'/' .
                    urlencode($storeId) . '/payment-methods' . '/OnChain' . '/' .
                    urlencode($cryptoCode) . '/wallet' . '/transactions' . '/' .
                    urlencode($transactionId);

        $headers = $this->getRequestHeaders();
        $method = 'GET';

        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            return new \Coinsnap\Result\StoreOnChainWalletTransaction(
                json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)
            );
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

    public function getStoreOnChainWalletUTXOs(
        string $storeId,
        string $cryptoCode
    ): \Coinsnap\Result\StoreOnChainWalletUTXOList {
        $url = $this->getApiUrl() . ''.COINSNAP_SERVER_PATH.'/' .
                    urlencode($storeId) . '/payment-methods' . '/OnChain' . '/' .
                    urlencode($cryptoCode) . '/wallet' . '/utxos';

        $headers = $this->getRequestHeaders();
        $method = 'GET';

        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            return new \Coinsnap\Result\StoreOnChainWalletUTXOList(
                json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)
            );
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }
}
