<?php
// Security tests for the Coinsnap Bitrix payment handler.
//
//   COINSNAP_TEST_DB=127.0.0.1:3306:user:password:database php tests/security-unit-test.php
//
// Runs the real handler.php, webhook/*.php and the bundled library against stubs of the Bitrix
// classes the handler touches (ServiceHandler, Payment, Order, Option, Logger, Application),
// a real MySQL/MariaDB database (invoice records, GET_LOCK) and a fake BTCPay API served by `php -S`.
// The Coinsnap provider's API host is fixed (app.coinsnap.io), so its webhook path is tested on the
// Processor with a fake invoice lookup.
declare(strict_types=1);

namespace Bitrix\Main {
    class Request {}
    class Error {}
    class Result
    {
        public function __construct(private bool $ok = true) {}
        public function isSuccess(): bool { return $this->ok; }
        public function getErrorMessages(): array { return $this->ok ? [] : ['save failed']; }
    }
    class Application
    {
        public static $connection;
        public static function getConnection() { return self::$connection; }
        public static function getDocumentRoot(): string { return '/tmp'; }
    }
}

namespace Bitrix\Main\Config {
    class Option
    {
        public static array $values = [];
        public static function get($module, $name, $default = '') { return self::$values[$module . ':' . $name] ?? $default; }
        public static function set($module, $name, $value): void { self::$values[$module . ':' . $name] = (string) $value; }
    }
}

namespace Bitrix\Main\Localization {
    class Loc
    {
        public static function loadMessages($file): void {}
        public static function getMessage($code) { return $code; }
    }
}

namespace Bitrix\Main\Type {
    class DateTime {}
}

namespace Bitrix\Sale\PaySystem {
    class Logger
    {
        public static array $lines = [];
        public static function addError($line): void { self::$lines[] = 'ERROR ' . $line; }
        public static function addDebugInfo($line): void { self::$lines[] = 'DEBUG ' . $line; }
    }
    class ServiceResult {}
    class ServiceHandler
    {
        public array $extra = [];
        public function __construct(public array $values = []) {}
        public function getBusinessValue($payment, $code) { return $this->values[$code] ?? null; }
        public function setExtraParams(array $p): void { $this->extra = $p; }
        public function showTemplate($payment, $template) { return $template; }
    }
}

namespace Bitrix\Sale {
    class ListResult
    {
        public function __construct(private array $rows) {}
        public function fetch() { return array_shift($this->rows) ?: false; }
    }
    class PaymentCollection
    {
        public array $items = [];
        public function getItemById($id) { return $this->items[(int) $id] ?? null; }
        public function getPaidSum(): float
        {
            $sum = 0.0;
            foreach ($this->items as $p) {
                if ($p->isPaid()) { $sum += $p->getSum(); }
            }
            return $sum;
        }
    }
    class Payment
    {
        public static array $all = [];
        public int $setPaidCalls = 0;
        public function __construct(public int $id, public Order $order, public float $sum, public int $paySystemId, public array $fields = [])
        {
            $this->fields += ['PAID' => 'N', 'PS_INVOICE_ID' => '', 'PS_STATUS_MESSAGE' => ''];
            self::$all[$id] = $this;
            $order->payments->items[$id] = $this;
        }
        public static function getList(array $p): ListResult
        {
            $rows = [];
            foreach (self::$all as $payment) {
                $ok = true;
                foreach ($p['filter'] as $k => $v) {
                    $field = ltrim($k, '=');
                    $value = $field === 'ID' ? $payment->id : ($payment->fields[$field] ?? null);
                    $ok = $ok && (string) $value === (string) $v;
                }
                if ($ok) { $rows[] = ['ID' => $payment->id, 'PAY_SYSTEM_ID' => $payment->paySystemId]; }
            }
            return new ListResult($rows);
        }
        public function getId() { return $this->id; }
        public function getOrderId() { return $this->order->id; }
        public function getOrder() { return $this->order; }
        public function getSum() { return $this->sum; }
        public function getPaymentSystemId() { return $this->paySystemId; }
        public function isPaid(): bool { return $this->fields['PAID'] === 'Y'; }
        public function setPaid($v): void { $this->setPaidCalls++; $this->fields['PAID'] = $v; }
        public function getField($k) { return $this->fields[$k] ?? null; }
        public function setField($k, $v): void { $this->fields[$k] = $v; }
        public function setFields(array $f): void { $this->fields = $f + $this->fields; }
    }
    class Order
    {
        public static array $all = [];
        public PaymentCollection $payments;
        public bool $saveFails = false;
        public bool $saveThrows = false;
        public int $saves = 0;
        public function __construct(public int $id, public string $currency, public array $fields = [])
        {
            $this->fields += ['STATUS_ID' => 'N', 'CANCELED' => 'N'];
            $this->payments = new PaymentCollection();
            self::$all[$id] = $this;
        }
        public static function load($id) { return self::$all[(int) $id] ?? null; }
        public function getId() { return $this->id; }
        public function getCurrency() { return $this->currency; }
        public function isCanceled(): bool { return $this->fields['CANCELED'] === 'Y'; }
        public function getField($k) { return $this->fields[$k] ?? null; }
        public function setField($k, $v): void { $this->fields[$k] = $v; }
        public function getPaymentCollection() { return $this->payments; }
        public function getPropertyCollection() { return new class { public function getItemByOrderPropertyCode($c) { return null; } }; }
        public function save()
        {
            if ($this->saveThrows) { throw new \RuntimeException('db gone'); }
            $this->saves++;
            return new \Bitrix\Main\Result(!$this->saveFails);
        }
    }
}

namespace {
    use Bitrix\Main\Config\Option;
    use Bitrix\Sale\Order;
    use Bitrix\Sale\Payment;
    use Bitrix\Sale\PaySystem\Logger;
    use Sale\Handlers\PaySystem\CoinsnapHandler;
    use Sale\Handlers\PaySystem\CoinsnapWebhook\BtcpayKey;
    use Sale\Handlers\PaySystem\CoinsnapWebhook\InvoiceDecision;
    use Sale\Handlers\PaySystem\CoinsnapWebhook\InvoiceRecords;
    use Sale\Handlers\PaySystem\CoinsnapWebhook\Processor;
    use Sale\Handlers\PaySystem\CoinsnapWebhook\Signature;

    // Bitrix\Main\DB\Connection on top of mysqli: the four methods the plugin uses.
    class MysqliConnection
    {
        public function __construct(public mysqli $m) {}
        public function query(string $sql) { return new class($this->m->query($sql)) {
            public function __construct(private $r) {}
            public function fetch() { return ($this->r instanceof mysqli_result) ? ($this->r->fetch_assoc() ?: false) : false; }
        }; }
        public function queryExecute(string $sql): void { $this->m->query($sql); }
        public function getAffectedRowsCount(): int { return (int) $this->m->affected_rows; }
        public function getSqlHelper() { return new class($this->m) {
            public function __construct(private mysqli $m) {}
            public function forSql(string $v): string { return $this->m->real_escape_string($v); }
        }; }
    }

    define('B_PROLOG_INCLUDED', true);
    $root = dirname(__DIR__) . '/coinsnap.payment/install/bitrix/modules/sale/handlers/paysystem/coinsnap';
    require $root . '/handler.php';

    $pass = 0;
    $fail = 0;
    function check(string $name, bool $ok): void
    {
        global $pass, $fail;
        $ok ? $pass++ : $fail++;
        echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    }

    // Security lines are capped at 10 a minute (Processor::security); start each run with a fresh count.
    array_map('unlink', glob(sys_get_temp_dir() . '/coinsnap_bitrix_seclog_*') ?: []);

    // ---------- database ----------
    [$dbHost, $dbPort, $dbUser, $dbPass, $dbName] = explode(':', (string) (getenv('COINSNAP_TEST_DB') ?: '127.0.0.1:3306:root:root:test'));
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int) $dbPort);
    $mysqli->query('DROP TABLE IF EXISTS ' . InvoiceRecords::TABLE);
    \Bitrix\Main\Application::$connection = new MysqliConnection($mysqli);

    // ---------- fake BTCPay ----------
    $port = (int) (getenv('COINSNAP_FAKE_PORT') ?: random_int(20000, 29999));
    $stateFile = tempnam(sys_get_temp_dir(), 'csbx');
    $writeState = function (array $s) use ($stateFile): void { file_put_contents($stateFile, json_encode($s)); };
    $readState = function () use ($stateFile): array { return json_decode((string) file_get_contents($stateFile), true) ?: []; };
    $writeState(['store' => 'STORE1', 'invoices' => [], 'keys' => [], 'requests' => []]);
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fake-btcpay.php'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        ['COINSNAP_FAKE_STATE' => $stateFile]
    );
    register_shutdown_function(function () use ($server, $stateFile) { proc_terminate($server); @unlink($stateFile); });
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }

    $SECRET = 'whsec-test-secret-value';
    $API_KEY = 'btcpay-test-api-key-0001';
    $values = [
        'COINSNAP_PROVIDER' => 'btcpay',
        'BTCPAY_SERVER_URL' => 'http://127.0.0.1:' . $port,
        'BTCPAY_STORE_ID' => 'STORE1',
        'BTCPAY_API_KEY' => $API_KEY,
        'COINSNAP_STATUS_NEW' => 'N',
        'COINSNAP_STATUS_PRO' => 'P',
        'COINSNAP_STATUS_EXP' => 'D',
        'COINSNAP_STATUS_SET' => 'P',
    ];
    Option::set('coinsnap.payment', 'webhook_' . md5('btcpay|STORE1'), json_encode(['id' => 'wh1', 'secret' => $SECRET, 'url' => 'https://shop.example/bitrix/tools/sale_ps_result.php']));
    $handler = new CoinsnapHandler($values);

    $records = new InvoiceRecords(\Bitrix\Main\Application::$connection,
        fn () => (string) Option::get('coinsnap.payment', 'invoice_records_since', ''),
        fn (string $v) => Option::set('coinsnap.payment', 'invoice_records_since', $v));
    $records->ensureTable(time());

    $nextId = 100;
    // An order with one Coinsnap payment and the invoices created for it (the last one is PS_INVOICE_ID).
    $makeOrder = function (float $sum, array $invoiceIds, string $currency = 'EUR', bool $record = true) use (&$nextId, $records): Payment {
        $order = new Order(++$nextId, $currency);
        $payment = new Payment($nextId + 5000, $order, $sum, 7, ['PS_INVOICE_ID' => end($invoiceIds)]);
        if ($record) {
            foreach ($invoiceIds as $inv) {
                $records->add($inv, $payment->id, $order->id, 7, number_format($sum, 2, '.', ''), $currency, number_format($sum, 2, '.', ''), $currency);
            }
        }
        return $payment;
    };
    $invoice = function (string $id, Payment $p, string $status, ?string $amount = null, string $currency = 'EUR', string $additional = 'None') use ($readState, $writeState): void {
        $s = $readState();
        $s['invoices'][$id] = ['status' => $status, 'additionalStatus' => $additional, 'amount' => $amount ?? number_format($p->sum, 2, '.', ''), 'currency' => $currency, 'metadata' => ['orderId' => (string) $p->order->id]];
        $writeState($s);
    };
    $send = function (Payment $p, string $invoiceId, string $type = 'InvoiceSettled', ?string $sig = null) use ($handler, $SECRET): int {
        $body = json_encode(['type' => $type, 'invoiceId' => $invoiceId]);
        $sig ??= 'sha256=' . hash_hmac('sha256', $body, $SECRET);
        return $handler->webhookStatus($p, $body, ['HTTP_BTCPAY_SIG' => $sig]);
    };
    $requests = function () use ($readState): int { return count($readState()['requests'] ?? []); };
    $logHas = function (string $needle): bool { foreach (Logger::$lines as $l) { if (str_contains($l, $needle)) { return true; } } return false; };

    // ---------- signature (BTCPay, real handler) ----------
    $p = $makeOrder(25.00, ['inv_sig']);
    $invoice('inv_sig', $p, 'Settled');
    $before = $requests();
    check('BTCPay: no signature -> 401', $send($p, 'inv_sig', 'InvoiceSettled', '') === 401);
    check('BTCPay: wrong signature -> 401', $send($p, 'inv_sig', 'InvoiceSettled', 'sha256=' . str_repeat('0', 64)) === 401);
    check('BTCPay: signature without sha256= prefix -> 401', $send($p, 'inv_sig', 'InvoiceSettled', hash_hmac('sha256', json_encode(['type' => 'InvoiceSettled', 'invoiceId' => 'inv_sig']), $SECRET)) === 401);
    check('rejected webhooks made no API call', $requests() === $before);
    check('rejected webhooks left the payment unpaid', !$p->isPaid());
    check('SECURITY bad signature logged', $logHas('Coinsnap SECURITY webhook reason=bad signature'));
    $emptyHandler = new CoinsnapHandler(['BTCPAY_STORE_ID' => 'OTHER'] + $values);
    $body = json_encode(['type' => 'InvoiceSettled', 'invoiceId' => 'inv_sig']);
    check('empty secret: webhook signed with an empty key -> 401', $emptyHandler->webhookStatus($p, $body, ['HTTP_BTCPAY_SIG' => 'sha256=' . hash_hmac('sha256', $body, '')]) === 401);
    $otherStore = new CoinsnapHandler(['BTCPAY_STORE_ID' => 'STORE2'] + $values);
    Option::set('coinsnap.payment', 'webhook_' . md5('btcpay|STORE2'), json_encode(['id' => 'wh2', 'secret' => 'store2-secret', 'url' => 'x']));
    check('6.4: store 1 webhook checked with store 2 secret -> 401', $otherStore->webhookStatus($p, $body, ['HTTP_BTCPAY_SIG' => 'sha256=' . hash_hmac('sha256', $body, $SECRET)]) === 401);
    check('6.4: store 2 signature accepted by store 2 config', $otherStore->webhookStatus($p, $body, ['HTTP_BTCPAY_SIG' => 'sha256=' . hash_hmac('sha256', $body, 'store2-secret')]) !== 401);

    // ---------- Coinsnap signature (Processor) ----------
    $now = time();
    $csBody = json_encode(['type' => 'Settled', 'invoiceId' => 'cs_1']);
    $csSig = fn (int $t, string $secret = 'cs-secret') => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $csBody, $secret);
    check('Coinsnap: valid t,v1 signature', Signature::coinsnapProblem($csSig($now), $csBody, 'cs-secret', $now) === null);
    check('Coinsnap: t 10 minutes old -> stale timestamp', Signature::coinsnapProblem($csSig($now - 600), $csBody, 'cs-secret', $now) === Signature::STALE_TIMESTAMP);
    check('Coinsnap: wrong secret -> bad signature', Signature::coinsnapProblem($csSig($now, 'x'), $csBody, 'cs-secret', $now) === Signature::BAD_SIGNATURE);
    check('Coinsnap: empty secret -> bad signature', Signature::coinsnapProblem($csSig($now, ''), $csBody, '', $now) === Signature::BAD_SIGNATURE);
    check('Coinsnap: old X-Coinsnap-Sig style value -> bad signature', Signature::coinsnapProblem('sha256=' . hash_hmac('sha256', $csBody, 'cs-secret'), $csBody, 'cs-secret', $now) === Signature::BAD_SIGNATURE);

    // ---------- invoice records (MariaDB) ----------
    $records->add('rec_1', 1, 2, 7, '10.00', 'EUR', '10.00', 'EUR');
    $records->add('rec_2', 1, 2, 7, '0.00012345', 'BTC', '10.00', 'RUB');
    check('records: two invoices kept for one payment', ($records->find('rec_1')['paymentId'] ?? 0) === 1 && ($records->find('rec_2')['currency'] ?? '') === 'BTC');
    check('records: claim with another payment id -> false', $records->claim('rec_1', 99, 2, 7) === false);
    check('records: first claim -> true', $records->claim('rec_1', 1, 2, 7) === true);
    check('records: second claim -> false', $records->claim('rec_1', 1, 2, 7) === false);
    $records->release('rec_1', 1);
    check('records: claim again after release -> true', $records->claim('rec_1', 1, 2, 7) === true);
    check('records: legacy window open right after the table was created', $records->legacyAllowed(time()));
    check('records: legacy window closed after 24 h', !$records->legacyAllowed(time() + 86401));

    // ---------- lookup and routing ----------
    $find = new ReflectionMethod(CoinsnapHandler::class, 'findPaymentByInvoice');
    $found = $find->invoke(null, 'inv_sig');
    check('invoice record finds its payment and payment system', $found === ['ID' => $p->id, 'PAY_SYSTEM_ID' => 7]);
    check('unknown invoice finds no payment (dashboard test webhook)', $find->invoke(null, 'test_123') === null);
    check("quotes in the invoice id don't break the lookup", $find->invoke(null, "x' OR '1'='1") === null);

    // ---------- API answers ----------
    $p = $makeOrder(25.00, ['inv_unknown']);
    check('invoice unknown to the API (404) -> 200', $send($p, 'inv_unknown') === 200 && !$p->isPaid());
    check('INFO unknown invoice logged', $logHas('INFO webhook reason=unknown invoice invoice=inv_unknown'));
    $s = $readState(); $s['down'] = true; $writeState($s);
    $invoice('inv_unknown', $p, 'Settled');
    check('API down (500) -> 503', $send($p, 'inv_unknown') === 503 && !$p->isPaid());
    $s = $readState(); $s['down'] = false; $writeState($s);

    // ---------- what counts as paid ----------
    $p = $makeOrder(100.00, ['inv_forged']);
    $invoice('inv_forged', $p, 'New');
    check('forged Settled (API says New) -> 200, unpaid', $send($p, 'inv_forged') === 200 && !$p->isPaid());
    check('SECURITY forged status logged', $logHas('SECURITY webhook reason=forged status invoice=inv_forged'));
    $p = $makeOrder(100.00, ['inv_under']);
    $invoice('inv_under', $p, 'Settled', '1.00');
    check('underpaid Settled -> unpaid', $send($p, 'inv_under') === 200 && !$p->isPaid());
    $p = $makeOrder(100.00, ['inv_cur']);
    $invoice('inv_cur', $p, 'Settled', '100.00', 'USD');
    check('Settled in another currency -> unpaid', $send($p, 'inv_cur') === 200 && !$p->isPaid());
    $p = $makeOrder(100.00, ['inv_marked']);
    $invoice('inv_marked', $p, 'Settled', null, 'EUR', 'Marked');
    check('BTCPay Marked (settled by hand) -> unpaid', $send($p, 'inv_marked') === 200 && !$p->isPaid());
    $p = $makeOrder(100.00, ['inv_other']);
    $other = $makeOrder(100.00, ['inv_x']);
    $invoice('inv_other', $other, 'Settled');
    check('invoice whose metadata names another order -> unpaid', $send($p, 'inv_other') === 200 && !$p->isPaid());
    check('webhook for an invoice recorded for another payment -> unpaid', $send($p, 'inv_x') === 200 && !$p->isPaid() && !$other->isPaid());
    $p = $makeOrder(100.00, ['inv_sum']);
    $invoice('inv_sum', $p, 'Settled');
    $p->sum = 150.00;
    check('payment sum changed after the invoice was created -> unpaid', $send($p, 'inv_sum') === 200 && !$p->isPaid());

    $p = $makeOrder(1234.56, ['inv_ok']);
    $invoice('inv_ok', $p, 'Settled', '1234.56');
    check('matching Settled -> paid', $send($p, 'inv_ok') === 200 && $p->isPaid() && $p->order->fields['STATUS_ID'] === 'P');
    check('same webhook twice -> paid once', $send($p, 'inv_ok') === 200 && $p->setPaidCalls === 1 && $p->order->saves === 1);
    $p->fields['PAID'] = 'N'; // the merchant sets it back to unpaid, e.g. after a refund
    check('refunded payment: resent Settled changes nothing (own record wins)', $send($p, 'inv_ok') === 200 && !$p->isPaid() && $p->setPaidCalls === 1);

    // ---------- 6.7 two invoices ----------
    $p = $makeOrder(50.00, ['inv_a', 'inv_b']);
    $invoice('inv_a', $p, 'Settled');
    check('6.7: two invoices, paying the first (older) one marks the payment paid', $send($p, 'inv_a') === 200 && $p->isPaid());
    $invoice('inv_b', $p, 'Expired');
    check('6.8: the second invoice expiring afterwards leaves it paid', $send($p, 'inv_b', 'InvoiceExpired') === 200 && $p->isPaid() && !$p->order->isCanceled());

    $p = $makeOrder(50.00, ['inv_c', 'inv_d']);
    $invoice('inv_c', $p, 'Expired');
    check('older invoice expiring while a newer one is open does not cancel', $send($p, 'inv_c', 'InvoiceExpired') === 200 && !$p->order->isCanceled());
    $invoice('inv_d', $p, 'Expired');
    check('newest invoice expiring cancels the unpaid order', $send($p, 'inv_d', 'InvoiceExpired') === 200 && $p->order->isCanceled() && $p->order->fields['STATUS_ID'] === 'D');
    $invoice('inv_c', $p, 'Settled');
    check('late payment of the older invoice still settles the order', $send($p, 'inv_c') === 200 && $p->isPaid() && !$p->order->isCanceled());

    // ---------- 6.8 manual status ----------
    $p = $makeOrder(40.00, ['inv_manual']);
    $p->order->fields['STATUS_ID'] = 'F'; // set by the merchant
    $invoice('inv_manual', $p, 'Expired');
    check('6.8: status set by hand, Expired webhook -> status kept, not cancelled', $send($p, 'inv_manual', 'InvoiceExpired') === 200 && $p->order->fields['STATUS_ID'] === 'F' && !$p->order->isCanceled());
    $invoice('inv_manual', $p, 'Processing');
    check('6.8: status set by hand, Processing webhook -> status kept', $send($p, 'inv_manual', 'InvoiceProcessing') === 200 && $p->order->fields['STATUS_ID'] === 'F');
    $invoice('inv_manual', $p, 'Settled');
    check('6.8: real payment on a hand-set status records the payment, keeps the status', $send($p, 'inv_manual') === 200 && $p->isPaid() && $p->order->fields['STATUS_ID'] === 'F');
    $p = $makeOrder(40.00, ['inv_partial']);
    new Payment(++$nextId + 9000, $p->order, 10.00, 3, ['PAID' => 'Y']);
    $invoice('inv_partial', $p, 'Expired');
    check('Expired does not cancel an order with another paid payment', $send($p, 'inv_partial', 'InvoiceExpired') === 200 && !$p->order->isCanceled());

    // ---------- act once when saving fails ----------
    $p = $makeOrder(30.00, ['inv_fail']);
    $invoice('inv_fail', $p, 'Settled');
    $p->order->saveFails = true;
    check('order save fails -> 503 so the provider retries', $send($p, 'inv_fail') === 503);
    $p->order->saveFails = false;
    $p->fields['PAID'] = 'N';
    check('the retry settles it (claim was released)', $send($p, 'inv_fail') === 200 && $p->isPaid());
    $p = $makeOrder(30.00, ['inv_throw']);
    $invoice('inv_throw', $p, 'Settled');
    $p->order->saveThrows = true;
    check('order save throws -> 503, no stack trace', $send($p, 'inv_throw') === 503);
    $p->order->saveThrows = false;
    $p->fields['PAID'] = 'N';
    check('the retry after an exception settles it', $send($p, 'inv_throw') === 200 && $p->isPaid());

    // ---------- concurrency ----------
    $p = $makeOrder(30.00, ['inv_lock']);
    $invoice('inv_lock', $p, 'Settled');
    $other = new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int) $dbPort);
    $other->query("SELECT GET_LOCK('coinsnap_payment_" . $p->id . "', 0)");
    $t = microtime(true);
    check('another delivery holds the payment lock -> 503 (retry), unpaid', $send($p, 'inv_lock') === 503 && !$p->isPaid());
    $other->query("SELECT RELEASE_LOCK('coinsnap_payment_" . $p->id . "')");
    check('after the lock is released the retry settles it', $send($p, 'inv_lock') === 200 && $p->isPaid());
    $other->close();

    // ---------- 6.7 payments from before the records ----------
    $p = $makeOrder(20.00, ['inv_legacy'], 'EUR', false);
    $invoice('inv_legacy', $p, 'Settled');
    check('pre-upgrade payment (PS_INVOICE_ID only) found by its invoice', $find->invoke(null, 'inv_legacy') === ['ID' => $p->id, 'PAY_SYSTEM_ID' => 7]);
    check('pre-upgrade payment inside the 24 h window -> paid', $send($p, 'inv_legacy') === 200 && $p->isPaid());
    check('pre-upgrade payment got a record when paid (acts once)', ($records->find('inv_legacy')['paid'] ?? false) === true);
    $p = $makeOrder(20.00, ['inv_legacy2'], 'EUR', false);
    $invoice('inv_legacy2', $p, 'Settled');
    Option::set('coinsnap.payment', 'invoice_records_since', (string) (time() - 2 * 86400));
    check('pre-upgrade payment after the 24 h window -> unchanged', $send($p, 'inv_legacy2') === 200 && !$p->isPaid());
    check('INFO no invoice record logged', $logHas('INFO webhook reason=no invoice record invoice=inv_legacy2'));
    $p = $makeOrder(20.00, ['inv_new_after']);
    $invoice('inv_new_after', $p, 'Settled');
    check('recorded payment after the window -> paid', $send($p, 'inv_new_after') === 200 && $p->isPaid());
    Option::set('coinsnap.payment', 'invoice_records_since', (string) time());

    // ---------- Processor (Coinsnap provider) ----------
    $csInvoice = ['status' => 'Settled', 'additionalStatus' => 'None', 'amount' => 54321, 'invoiceAmount' => 10.00, 'currency' => 'EUR', 'orderId' => '9'];
    $lines = [];
    $proc = new Processor('coinsnap', 'cs-secret', function ($id) use (&$csInvoice) { return $csInvoice; }, function ($l) use (&$lines) { $lines[] = $l; });
    $ctx = ['paymentId' => '1', 'invoiceId' => 'cs_1', 'orderId' => '9', 'sum' => '10.00', 'currency' => 'EUR', 'expected' => '', 'paid' => false, 'canceled' => false,
        'record' => ['paymentId' => 1, 'amount' => '10.00', 'currency' => 'EUR', 'paymentSum' => '10.00', 'paymentCurrency' => 'EUR', 'paid' => false],
        'legacyAllowed' => false, 'statusIsOurs' => true, 'otherPaid' => false];
    check('Coinsnap: settled invoice in the order currency -> pay', $proc->handle($csBody, $csSig($now), $now, $ctx) === [200, Processor::PAY]);
    $csInvoice['invoiceAmount'] = 0.10;
    check('Coinsnap: invoiceAmount below the price -> no pay', $proc->handle($csBody, $csSig($now), $now, $ctx) === [200, Processor::NONE]);
    unset($csInvoice['invoiceAmount']);
    $csInvoice['fiatAmount'] = 10.00;
    check('Coinsnap: fiatAmount fallback -> pay', $proc->handle($csBody, $csSig($now), $now, $ctx) === [200, Processor::PAY]);
    check('Coinsnap: stale signature -> 401', $proc->handle($csBody, $csSig($now - 600), $now, $ctx)[0] === 401);
    $csInvoice['orderId'] = '10';
    check('Coinsnap: invoice for another order -> no pay', $proc->handle($csBody, $csSig($now), $now, $ctx) === [200, Processor::NONE]);
    check('Coinsnap: zero price is never paid', InvoiceDecision::decide(['status' => 'Settled', 'invoiceAmount' => 0, 'currency' => 'EUR'], false, '0.00', 'EUR') !== InvoiceDecision::PAID);
    check('Coinsnap: Overpaid still pays', InvoiceDecision::decide(['status' => 'Settled', 'additionalStatus' => 'Overpaid', 'invoiceAmount' => '10.00', 'currency' => 'EUR'], false, '10.00', 'EUR') === InvoiceDecision::PAID);
    check('action: Invalid/unknown status changes nothing', Processor::action(InvoiceDecision::NONE, ['paid' => false, 'canceled' => false, 'latest' => true, 'statusIsOurs' => true, 'otherPaid' => false])[0] === Processor::NONE);

    // ---------- BTCPay key acceptance ----------
    check('key: store-limited with the needed rights -> accepted', BtcpayKey::acceptable(['btcpay.store.cancreateinvoice:S1', 'btcpay.store.canviewinvoices:S1', 'btcpay.store.webhooks.canmodifywebhooks:S1'], 'S1'));
    check('key: unrestricted -> refused', !BtcpayKey::acceptable(['unrestricted'], 'S1'));
    check('key: store rights without a store (all stores) -> refused', !BtcpayKey::acceptable(['btcpay.store.cancreateinvoice', 'btcpay.store.canviewinvoices', 'btcpay.store.webhooks.canmodifywebhooks'], 'S1'));
    check('key: another store -> refused', !BtcpayKey::acceptable(['btcpay.store.cancreateinvoice:S2', 'btcpay.store.canviewinvoices:S2', 'btcpay.store.webhooks.canmodifywebhooks:S2'], 'S1'));
    check('key: plus a server right -> refused', !BtcpayKey::acceptable(['btcpay.store.cancreateinvoice:S1', 'btcpay.store.canviewinvoices:S1', 'btcpay.store.webhooks.canmodifywebhooks:S1', 'btcpay.server.canmodifyserversettings'], 'S1'));
    check('key: missing webhook right -> refused', !BtcpayKey::acceptable(['btcpay.store.cancreateinvoice:S1', 'btcpay.store.canviewinvoices:S1'], 'S1'));
    check('key: empty -> refused', !BtcpayKey::acceptable([], 'S1'));
    check('server URL: file:// refused', !BtcpayKey::validServerUrl('file:///etc/passwd'));
    check('server URL: https accepted', BtcpayKey::validServerUrl('https://btcpay.example.com'));

    $usable = new ReflectionMethod(CoinsnapHandler::class, 'btcpayKeyUsable');
    $dummy = $makeOrder(1.00, ['inv_dummy']);
    $s = $readState();
    $s['keys'] = [
        'unrestricted-key' => ['unrestricted'],
        'good-key' => ['btcpay.store.cancreateinvoice:STORE1', 'btcpay.store.canviewinvoices:STORE1', 'btcpay.store.webhooks.canmodifywebhooks:STORE1'],
    ];
    $writeState($s);
    check('checkout: unrestricted BTCPay key refused (asked api-keys/current)', !$usable->invoke(new CoinsnapHandler(['BTCPAY_API_KEY' => 'unrestricted-key'] + $values), $dummy) && in_array('GET /api/v1/api-keys/current', $readState()['requests'], true));
    check('checkout: key unknown to BTCPay refused', !$usable->invoke(new CoinsnapHandler(['BTCPAY_API_KEY' => 'nope'] + $values), $dummy));
    check('checkout: store-limited key accepted', $usable->invoke(new CoinsnapHandler(['BTCPAY_API_KEY' => 'good-key'] + $values), $dummy));
    $before = $requests();
    check('checkout: accepted key remembered, BTCPay not asked again', $usable->invoke(new CoinsnapHandler(['BTCPAY_API_KEY' => 'good-key'] + $values), $dummy) && $requests() === $before);
    check('checkout: same key for another store ID refused', !$usable->invoke(new CoinsnapHandler(['BTCPAY_API_KEY' => 'good-key', 'BTCPAY_STORE_ID' => 'STORE9'] + $values), $dummy));
    $before = $requests();
    check('checkout: non-http server URL refused without sending the key', !$usable->invoke(new CoinsnapHandler(['BTCPAY_API_KEY' => 'good-key', 'BTCPAY_SERVER_URL' => 'file:///tmp/x'] + $values), $dummy) && $requests() === $before);
    $initiated = $handler->initiatePay($dummy, new \Bitrix\Main\Request()) ?? null;
    check('initiatePay with the (unknown) configured key shows an error, creates nothing', $initiated === 'template' && ($handler->extra['message'] ?? '') === 'SALE_COINSNAP_API_ERROR');

    // ---------- 6.3 webhook URL ----------
    $_SERVER['HTTP_HOST'] = 'attacker.example';
    $_SERVER['SERVER_NAME'] = 'attacker.example';
    Option::set('main', 'server_name', 'shop.example');
    $url = (new ReflectionMethod(CoinsnapHandler::class, 'getWebhookUrl'))->invoke($handler);
    check('6.3: webhook URL from the main module server name, not the Host header', $url === 'https://shop.example/bitrix/tools/sale_ps_result.php');

    // ---------- logs ----------
    $all = implode("\n", Logger::$lines) . "\n" . implode("\n", $lines);
    check('logs contain no webhook secret', !str_contains($all, $SECRET) && !str_contains($all, 'cs-secret') && !str_contains($all, 'store2-secret'));
    check('logs contain no API key', !str_contains($all, $API_KEY) && !str_contains($all, 'good-key'));
    check('logs contain no store ID (6.13)', !str_contains($all, 'STORE1') && !str_contains($all, 'STORE2'));
    check('logs contain no signature header', !preg_match('/sha256=[0-9a-f]{64}|v1=[0-9a-f]{64}/', $all));

    $mysqli->query('DROP TABLE IF EXISTS ' . InvoiceRecords::TABLE);
    echo "\n$pass passed, $fail failed\n";
    exit($fail === 0 ? 0 : 1);
}
