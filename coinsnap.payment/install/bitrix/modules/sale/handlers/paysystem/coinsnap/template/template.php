<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true){
	die();
}

use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);

//  If payment is received
if (isset($payment) && $payment->isPaid()) {
    echo Loc::getMessage('COINSNAP_PAYMENT_PAID');
}

//  If message is sent
elseif(isset($params['message'])) {
    echo '<h3>'.Loc::getMessage('COINSNAP_ERROR').'</h3>';
    echo '<p>'.$params['message'].'</p>';
}