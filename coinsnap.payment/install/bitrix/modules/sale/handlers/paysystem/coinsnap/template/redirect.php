<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true){ 
	die();
}
 echo '<script>window.location.href = '.json_encode((string) $params['invoiceURL'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES).'</script>';