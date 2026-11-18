<?php
declare(strict_types=1);

namespace Sale\Handlers\PaySystem\CoinsnapWebhook;

use Coinsnap\Http\ClientInterface;

// A pasted BTCPay key is used only if BTCPay says it is limited to the configured store.
class BtcpayKey
{
    public const NEEDED = [
        'btcpay.store.cancreateinvoice',
        'btcpay.store.canviewinvoices',
        'btcpay.store.webhooks.canmodifywebhooks',
    ];

    public static function validServerUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    // Every permission must be a store permission for $storeId, and the ones the plugin uses must be there.
    public static function acceptable(array $permissions, string $storeId): bool
    {
        if ($permissions === [] || $storeId === '') {
            return false;
        }
        foreach ($permissions as $permission) {
            [$policy, $scope] = array_pad(explode(':', (string) $permission, 2), 2, '');
            if (strpos($policy, 'btcpay.store.') !== 0 || $scope !== $storeId) {
                return false;
            }
        }
        foreach (self::NEEDED as $needed) {
            if (!in_array($needed . ':' . $storeId, $permissions, true)) {
                return false;
            }
        }
        return true;
    }

    // The key's permissions as BTCPay reports them (GET /api/v1/api-keys/current), null if BTCPay refuses the key.
    // Throws when BTCPay can't be reached.
    public static function permissions(ClientInterface $http, string $serverUrl, string $apiKey): ?array
    {
        $response = $http->request('GET', rtrim($serverUrl, '/') . '/api/v1/api-keys/current', [
            'Accept' => 'application/json',
            'Authorization' => 'token ' . $apiKey,
        ]);
        $status = (int) $response->getStatus();
        if ($status >= 500) {
            throw new \RuntimeException('BTCPay Server unavailable (' . $status . ')');
        }
        if ($status !== 200) {
            return null;
        }
        $data = json_decode($response->getBody(), true);
        return (is_array($data) && is_array($data['permissions'] ?? null)) ? array_values($data['permissions']) : null;
    }
}
