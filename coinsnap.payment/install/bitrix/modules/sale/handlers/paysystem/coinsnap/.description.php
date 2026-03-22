<?php
use Bitrix\Main\Loader,
Bitrix\Main\Localization\Loc,
Bitrix\Sale\PaySystem, Bitrix\Sale\OrderStatus;


if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

$isAvailable = PaySystem\Manager::HANDLER_AVAILABLE_TRUE;

$licensePrefix = Loader::includeModule('bitrix24') ? \CBitrix24::getLicensePrefix() : '';
$portalZone = Loader::includeModule('intranet') ? CIntranetUtils::getPortalZone() : '';
$orderStatuses = OrderStatus::getAllStatusesNames();


$data = [
    'NAME' => 'Coinsnap',
    'DESCRIPTION' => 'Coinsnap',
    'SORT' => 500,
    'IS_AVAILABLE' => $isAvailable,
    'CODES' => [
        // settings
        'COINSNAP_PROVIDER' => [
            'NAME' => Loc::getMessage('SALE_COINSNAP_PROVIDER'),
            'DESCRIPTION' => Loc::getMessage('SALE_COINSNAP_PROVIDER_DESCR'),
            'TYPE' => 'SELECT',
            'SORT' => 50,
            'GROUP' => Loc::getMessage('CONNECT_SETTINGS_COINSNAP'),
            'INPUT' => [
                'TYPE' => 'ENUM',
                'OPTIONS' => ['coinsnap' => 'Coinsnap','btcpay' => 'BTCPay'],
                'VALUE' => 'coinsnap',
            ],
            
        ],
        
        'COINSNAP_STORE_ID' => [
            'NAME' => Loc::getMessage('SALE_COINSNAP_STORE_ID'),
            'DESCRIPTION' => Loc::getMessage('SALE_COINSNAP_STORE_ID_DESCR'),
            'SORT' => 100,
            'GROUP' => Loc::getMessage('CONNECT_SETTINGS_COINSNAP')
            
        ],
        'COINSNAP_API_KEY' => [
            'NAME' => Loc::getMessage('SALE_COINSNAP_API_KEY'),
            'DESCRIPTION' => Loc::getMessage('SALE_COINSNAP_API_KEY_DESCR'),
            'SORT' => 105,
            'GROUP' => Loc::getMessage('CONNECT_SETTINGS_COINSNAP')
            
        ],
        'BTCPAY_SERVER_URL' => [
            'NAME' => Loc::getMessage('SALE_BTCPAY_SERVER_URL'),
            'DESCRIPTION' => Loc::getMessage('SALE_BTCPAY_SERVER_URL_DESCR'),
            'SORT' => 210,
            'GROUP' => Loc::getMessage('CONNECT_SETTINGS_COINSNAP')
            
        ],
        'BTCPAY_STORE_ID' => [
            'NAME' => Loc::getMessage('SALE_BTCPAY_STORE_ID'),
            'DESCRIPTION' => Loc::getMessage('SALE_BTCPAY_STORE_ID_DESCR'),
            'SORT' => 215,
            'GROUP' => Loc::getMessage('CONNECT_SETTINGS_COINSNAP')
            
        ],
        'BTCPAY_API_KEY' => [
            'NAME' => Loc::getMessage('SALE_BTCPAY_API_KEY'),
            'DESCRIPTION' => Loc::getMessage('SALE_BTCPAY_API_KEY_DESCR'),
            'SORT' => 220,
            'GROUP' => Loc::getMessage('CONNECT_SETTINGS_COINSNAP')
            
        ],
        
        'COINSNAP_AUTOREDIRECT' => [
            'NAME' => Loc::getMessage('SALE_COINSNAP_AUTOREDIRECT'),
            'DESCRIPTION' => Loc::getMessage('SALE_COINSNAP_AUTOREDIRECT_DESCR'),
            'INPUT' => array(
                'TYPE' => 'Y/N',
                'VALUES' => array('Y', 'N'),
                'VALUE' => 'Y'
            ),
            'DEFAULT' => array(
                'PROVIDER_VALUE' => 'Y',
                'PROVIDER_KEY' => 'INPUT'
            ),
            'SORT' => 230,
            'GROUP' => Loc::getMessage('CONNECT_SETTINGS_COINSNAP')
            
        ],
        
        'COINSNAP_RETURNURL' => [
            'NAME' => Loc::getMessage('SALE_COINSNAP_RETURNURL'),
            'DESCRIPTION' => Loc::getMessage('SALE_COINSNAP_RETURNURL_DESCR'),
            'SORT' => 240,
            'GROUP' => Loc::getMessage('CONNECT_SETTINGS_COINSNAP')
            
        ],
        
        //  DISCOUNT
        
        'COINSNAP_DISCOUNT_ENABLED' => [
            'NAME' => Loc::getMessage('DISCOUNT_ENABLED'),
            'DESCRIPTION' => Loc::getMessage('DISCOUNT_ENABLED_DESCR'),
            'INPUT' => array(
                'TYPE' => 'Y/N',
                'VALUES' => array('Y', 'N'),
                'VALUE' => 'Y'
            ),
            'DEFAULT' => array(
                'PROVIDER_VALUE' => 'N',
                'PROVIDER_KEY' => 'INPUT'
            ),
            'SORT' => 300,
            'GROUP' => Loc::getMessage('COINSNAP_DISCOUNT')
            
        ],
        
        'COINSNAP_DISCOUNT_TYPE' => [
            'NAME' => Loc::getMessage('DISCOUNT_TYPE'),
            'DESCRIPTION' => Loc::getMessage('DISCOUNT_TYPE_DESCR'),
            'TYPE' => 'SELECT',
            'SORT' => 310,
            'GROUP' => Loc::getMessage('COINSNAP_DISCOUNT'),
            'INPUT' => [
                'TYPE' => 'ENUM',
                'OPTIONS' => ['fixed' => Loc::getMessage('DISCOUNT_TYPE_FIXED'),'percentage' => Loc::getMessage('DISCOUNT_TYPE_PERCENTAGE')],
                'VALUE' => 'fixed',
            ],
            
        ],
        
        'COINSNAP_DISCOUNT_AMOUNT' => [
            'NAME' => Loc::getMessage('DISCOUNT_AMOUNT'),
            'DESCRIPTION' => Loc::getMessage('DISCOUNT_AMOUNT_DESCR'),
            'SORT' => 320,
            'TYPE' => 'number',
            'GROUP' => Loc::getMessage('COINSNAP_DISCOUNT')
        ],
        
        'COINSNAP_DISCOUNT_AMOUNT_LIMIT' => [
            'NAME' => Loc::getMessage('DISCOUNT_AMOUNT_LIMIT'),
            'DESCRIPTION' => Loc::getMessage('DISCOUNT_AMOUNT_LIMIT_DESCR'),
            'SORT' => 330,
            'TYPE' => 'number',
            'GROUP' => Loc::getMessage('COINSNAP_DISCOUNT')
        ],
        
        'COINSNAP_DISCOUNT_PERCENTAGE' => [
            'NAME' => Loc::getMessage('DISCOUNT_PERCENTAGE'),
            'DESCRIPTION' => Loc::getMessage('DISCOUNT_PERCENTAGE_DESCR'),
            'SORT' => 340,
            'TYPE' => 'number',
            'GROUP' => Loc::getMessage('COINSNAP_DISCOUNT')
        ],
        
        
        //  STATUSES
        
        'COINSNAP_STATUS_NEW' => [
            'NAME' => Loc::getMessage('COINSNAP_STATUS_NEW'),
            'DESCRIPTION' => Loc::getMessage('COINSNAP_STATUS_NEW_DESC'),
            'TYPE' => 'SELECT',
            'SORT' => 400,
            'GROUP' => Loc::getMessage('COINSNAP_STATUS'),
            'INPUT' => [
                'TYPE' => 'ENUM',
                'OPTIONS' => $orderStatuses,
                'VALUE' => 'N',
            ],
        ],

            'COINSNAP_STATUS_EXP' => [
            'NAME' => Loc::getMessage('COINSNAP_STATUS_EXP'),
            'DESCRIPTION' => Loc::getMessage('COINSNAP_STATUS_EXP_DESC'),
            'TYPE' => 'SELECT',
            'SORT' => 410,
            'GROUP' => Loc::getMessage('COINSNAP_STATUS'),
            'INPUT' => [
                'TYPE' => 'ENUM',
                'OPTIONS' => $orderStatuses,
                'VALUE' => 'D',
            ],
        ],

        'COINSNAP_STATUS_SET' => [
            'NAME' => Loc::getMessage('COINSNAP_STATUS_SET'),
            'DESCRIPTION' => Loc::getMessage('COINSNAP_STATUS_SET_DESC'),
            'TYPE' => 'SELECT',
            'SORT' => 420,
            'GROUP' => Loc::getMessage('COINSNAP_STATUS'),
            'INPUT' => [
                'TYPE' => 'ENUM',
                'OPTIONS' => $orderStatuses,
                'VALUE' => 'P',
            ],
        ],

        'COINSNAP_STATUS_PRO' => [
            'NAME' => Loc::getMessage('COINSNAP_STATUS_PRO'),
            'DESCRIPTION' => Loc::getMessage('COINSNAP_STATUS_PRO_DESC'),
            'TYPE' => 'SELECT',
            'SORT' => 430,
            'GROUP' => Loc::getMessage('COINSNAP_STATUS'),
            'INPUT' => [
                'TYPE' => 'ENUM',
                'OPTIONS' => $orderStatuses,
                'VALUE' => 'P',
            ],
        ],

        ],

        
];
