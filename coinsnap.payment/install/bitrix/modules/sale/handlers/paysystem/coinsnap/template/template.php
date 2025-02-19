<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true){
    die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

if (isset($payment) && $payment->isPaid()) {
    echo Loc::getMessage('COINSNAP_PAYMENT_PAID');
} elseif(isset($params['message'])) {
    echo Loc::getMessage('COINSNAP_ERROR');
    echo '<br>';
    echo $params['message'];
} else {
    echo '<script>window.location = '/'</script>';
}