<?php
declare(strict_types=1);

namespace Sale\Handlers\PaySystem\CoinsnapWebhook;

// Order and customer data sent with an invoice, so the merchant can find the order in Coinsnap / BTCPay.
// Reads the Bitrix order through its public API; every read is guarded, so a missing property or an
// older sale module never stops the checkout. Plain values only (no HTML escaping), empty values skipped.
final class InvoiceMetadata
{
    public const PLATFORM = 'bitrix';

    /**
     * @param object $order      Bitrix\Sale\Order
     * @param string $orderUrl   Admin URL of the order, or ''.
     */
    public static function fromOrder($order, string $orderUrl = '', string $pluginVersion = ''): array
    {
        $props = self::get(static fn () => $order->getPropertyCollection());
        $prop = static function (string $method) use ($props) {
            return self::get(static function () use ($props, $method) {
                $item = $props->$method();
                return $item ? $item->getValue() : null;
            });
        };
        $byCode = static function (string $code) use ($props) {
            return self::get(static function () use ($props, $code) {
                $item = $props->getItemByOrderPropertyCode($code);
                return $item ? $item->getValue() : null;
            });
        };

        $id = (string) self::get(static fn () => $order->getId());
        $accountNumber = self::get(static fn () => $order->getField('ACCOUNT_NUMBER'));
        $name = $prop('getPayerName');
        $location = $prop('getDeliveryLocation');

        $meta = [
            // The webhook compares the invoice's orderId (BTCPay: metadata.orderId) with the order ID; keep it.
            'orderId' => $id,
            // The number shown in the admin order list (equal to the ID unless the shop uses a number template).
            'orderNumber' => self::str($accountNumber) !== '' ? $accountNumber : $id,
            'orderUrl' => $orderUrl,
            'platform' => self::PLATFORM,
            'pluginVersion' => $pluginVersion,
            'siteId' => self::get(static fn () => $order->getSiteId()),
            // Personal data: the plugin has no "send customer data" setting, so it is always sent.
            'customerName' => $name,
            'buyerName' => $name,
            'buyerEmail' => $prop('getUserEmail'),
            'buyerPhone' => $prop('getPhone'),
            'buyerCompany' => $byCode('COMPANY'),
            'buyerAddress1' => $prop('getAddress'),
            'buyerCity' => $byCode('CITY'),
            'buyerZip' => self::first([$prop('getDeliveryLocationZip'), $byCode('ZIP')]),
            'buyerLocation' => is_scalar($location) && (string) $location !== '' ? self::locationName((string) $location) : null,
            'buyerMessage' => self::get(static fn () => $order->getField('USER_DESCRIPTION')),
            'currency' => self::get(static fn () => $order->getCurrency()),
            'orderTotal' => self::num(self::get(static fn () => $order->getPrice())),
            'shippingTotal' => self::num(self::get(static fn () => $order->getDeliveryPrice())),
            'taxTotal' => self::num(self::get(static fn () => $order->getTaxValue())),
            'discountTotal' => self::num(self::get(static fn () => $order->getDiscountPrice())),
        ];

        $shipping = [];
        foreach ((self::get(static fn () => $order->getShipmentCollection()) ?? []) as $shipment) {
            if (self::get(static fn () => $shipment->isSystem())) {
                continue;
            }
            $shipping[] = self::str(self::get(static fn () => $shipment->getDeliveryName()));
        }
        $meta['shippingMethod'] = implode(', ', array_filter($shipping, 'strlen'));

        $coupons = self::get(static function () use ($order) {
            $list = $order->getDiscount()->getApplyResult(true)['COUPON_LIST'] ?? [];
            return array_values(array_filter(array_map(static fn ($c) => is_array($c) ? (string) ($c['COUPON'] ?? '') : '', (array) $list), 'strlen'));
        });
        $meta['couponCodes'] = is_array($coupons) ? $coupons : [];

        $lineItems = [];
        $names = [];
        $codes = [];
        foreach ((self::get(static fn () => $order->getBasket()) ?? []) as $item) {
            $qty = self::num(self::get(static fn () => $item->getQuantity()));
            $line = self::clean([
                'name' => self::get(static fn () => $item->getField('NAME')),
                'productId' => self::get(static fn () => $item->getProductId()),
                'quantity' => $qty,
                // Unit price after discounts, in the order currency.
                'price' => self::num(self::get(static fn () => $item->getPrice())),
                'total' => self::num(self::get(static fn () => $item->getFinalPrice())),
            ]);
            if (!isset($line['name']) && !isset($line['productId'])) {
                continue;
            }
            $lineItems[] = $line;
            if (isset($line['name'])) {
                $names[] = ($qty !== null && $qty != 1 ? self::qty($qty) . ' x ' : '') . $line['name'];
            }
            if (isset($line['productId'])) {
                $codes[] = (string) $line['productId'];
            }
        }
        $meta['lineItems'] = $lineItems;
        $meta['itemDesc'] = $names !== [] ? mb_substr(implode(', ', $names), 0, 250) : null;
        $meta['itemCode'] = $codes !== [] ? mb_substr(implode(', ', $codes), 0, 250) : null;

        return self::clean($meta);
    }

    // "Country, region, city" for a location code; null when the location module can't say.
    private static function locationName(string $code): ?string
    {
        return self::get(static function () use ($code) {
            if (!class_exists('\Bitrix\Sale\Location\Admin\LocationHelper')) {
                return null;
            }
            $name = \Bitrix\Sale\Location\Admin\LocationHelper::getLocationPathDisplay($code);
            return is_string($name) && $name !== '' ? $name : null;
        });
    }

    private static function get(callable $read)
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function str($v): string
    {
        return is_scalar($v) ? trim(html_entity_decode((string) $v, ENT_QUOTES, 'UTF-8')) : '';
    }

    private static function num($v): ?float
    {
        return is_numeric($v) ? round((float) $v, 8) : null;
    }

    private static function first(array $values)
    {
        foreach ($values as $v) {
            if (self::str($v) !== '') {
                return $v;
            }
        }
        return null;
    }

    private static function qty(float $q): string
    {
        return floor($q) == $q ? (string) (int) $q : (string) $q;
    }

    private static function clean(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if ($value !== []) {
                    $out[$key] = $value;
                }
            } elseif (is_int($value) || is_float($value)) {
                $out[$key] = $value;
            } elseif (($s = self::str($value)) !== '') {
                $out[$key] = $s;
            }
        }
        return $out;
    }
}
