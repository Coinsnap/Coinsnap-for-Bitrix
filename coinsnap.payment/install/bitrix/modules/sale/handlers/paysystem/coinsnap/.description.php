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
        'COINSNAP_STORE_ID' => [
            'NAME' => Loc::getMessage('SALE_COINSNAP_STORE_ID'),
            'DESCRIPTION' => Loc::getMessage('SALE_COINSNAP_STORE_ID_DESCR'),
            'SORT' => 100,
            'GROUP' => Loc::getMessage('CONNECT_SETTINGS_COINSNAP')
            
        ],
        'COINSNAP_API_KEY' => [
            'NAME' => Loc::getMessage('SALE_COINSNAP_API_KEY'),
            'DESCRIPTION' => Loc::getMessage('SALE_COINSNAP_API_KEY_DESCR'),
            'SORT' => 110,
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
            'SORT' => 190,
            'GROUP' => Loc::getMessage('COINSNAP_STATUS')
            
        ],
        
        'COINSNAP_STATUS_NEW' => [
            'NAME' => Loc::getMessage('COINSNAP_STATUS_NEW'),
            'DESCRIPTION' => Loc::getMessage('COINSNAP_STATUS_NEW_DESC'),
            'TYPE' => 'SELECT',
            'SORT' => 200,
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
            'SORT' => 210,
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
            'SORT' => 220,
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
            'SORT' => 230,
            'GROUP' => Loc::getMessage('COINSNAP_STATUS'),
            'INPUT' => [
                'TYPE' => 'ENUM',
                'OPTIONS' => $orderStatuses,
                'VALUE' => 'P',
            ],
        ],

        ],

        
];
