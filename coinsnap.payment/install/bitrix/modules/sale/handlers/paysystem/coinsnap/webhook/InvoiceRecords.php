<?php
declare(strict_types=1);

namespace Sale\Handlers\PaySystem\CoinsnapWebhook;

// Every invoice the plugin created, one row each, with what it asked for (guide 6.7, 6.9).
// $db is a Bitrix\Main\DB\Connection (query, queryExecute, getSqlHelper()->forSql, getAffectedRowsCount).
class InvoiceRecords
{
    public const TABLE = 'b_coinsnap_payment_invoice';
    // Payments from before these records existed are accepted this long after the table was created.
    public const LEGACY_WINDOW_SECONDS = 86400;

    /** @var object */
    private $db;
    /** @var callable(): string */
    private $getSince;
    /** @var callable(string): void */
    private $setSince;

    public function __construct($db, callable $getSince, callable $setSince)
    {
        $this->db = $db;
        $this->getSince = $getSince;
        $this->setSince = $setSince;
    }

    // Creates the table once and remembers when, which starts the window for older payments.
    public function ensureTable(int $nowSeconds): void
    {
        if ((int) ($this->getSince)() > 0) {
            return;
        }
        $this->db->queryExecute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'INVOICE_ID VARCHAR(64) NOT NULL PRIMARY KEY, '
            . 'PAYMENT_ID INT UNSIGNED NOT NULL, '
            . 'ORDER_ID INT UNSIGNED NOT NULL, '
            . 'PAY_SYSTEM_ID INT UNSIGNED NOT NULL, '
            . "AMOUNT VARCHAR(32) NOT NULL DEFAULT '', "
            . "CURRENCY VARCHAR(8) NOT NULL DEFAULT '', "
            . "PAYMENT_SUM VARCHAR(32) NOT NULL DEFAULT '', "
            . "PAYMENT_CURRENCY VARCHAR(8) NOT NULL DEFAULT '', "
            . 'CREATED_AT DATETIME NOT NULL, '
            . 'PAID_AT DATETIME NULL, '
            . 'KEY IX_COINSNAP_PAYMENT (PAYMENT_ID))'
        );
        ($this->setSince)((string) $nowSeconds);
    }

    public function legacyAllowed(int $nowSeconds): bool
    {
        $since = (int) ($this->getSince)();
        return $since > 0 && $nowSeconds - $since <= self::LEGACY_WINDOW_SECONDS;
    }

    public function add(string $invoiceId, int $paymentId, int $orderId, int $paySystemId, string $amount, string $currency, string $paymentSum, string $paymentCurrency): void
    {
        $this->db->queryExecute(sprintf(
            "INSERT INTO %s (INVOICE_ID, PAYMENT_ID, ORDER_ID, PAY_SYSTEM_ID, AMOUNT, CURRENCY, PAYMENT_SUM, PAYMENT_CURRENCY, CREATED_AT) VALUES ('%s', %d, %d, %d, '%s', '%s', '%s', '%s', NOW())",
            self::TABLE,
            $this->esc($invoiceId),
            $paymentId,
            $orderId,
            $paySystemId,
            $this->esc($amount),
            $this->esc($currency),
            $this->esc($paymentSum),
            $this->esc($paymentCurrency)
        ));
    }

    /** @return array{paymentId: int, orderId: int, paySystemId: int, amount: string, currency: string, paymentSum: string, paymentCurrency: string, paid: bool}|null */
    public function find(string $invoiceId): ?array
    {
        $row = $this->db->query(sprintf(
            "SELECT PAYMENT_ID, ORDER_ID, PAY_SYSTEM_ID, AMOUNT, CURRENCY, PAYMENT_SUM, PAYMENT_CURRENCY, PAID_AT FROM %s WHERE INVOICE_ID = '%s'",
            self::TABLE,
            $this->esc($invoiceId)
        ))->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'paymentId' => (int) $row['PAYMENT_ID'],
            'orderId' => (int) $row['ORDER_ID'],
            'paySystemId' => (int) $row['PAY_SYSTEM_ID'],
            'amount' => (string) $row['AMOUNT'],
            'currency' => (string) $row['CURRENCY'],
            'paymentSum' => (string) $row['PAYMENT_SUM'],
            'paymentCurrency' => (string) $row['PAYMENT_CURRENCY'],
            'paid' => !empty($row['PAID_AT']),
        ];
    }

    // True for exactly one caller per invoice. A payment from before the records existed gets its row here.
    public function claim(string $invoiceId, int $paymentId, int $orderId, int $paySystemId): bool
    {
        $this->db->queryExecute(sprintf(
            "INSERT IGNORE INTO %s (INVOICE_ID, PAYMENT_ID, ORDER_ID, PAY_SYSTEM_ID, CREATED_AT) VALUES ('%s', %d, %d, %d, NOW())",
            self::TABLE,
            $this->esc($invoiceId),
            $paymentId,
            $orderId,
            $paySystemId
        ));
        $this->db->queryExecute(sprintf(
            "UPDATE %s SET PAID_AT = NOW() WHERE INVOICE_ID = '%s' AND PAYMENT_ID = %d AND PAID_AT IS NULL",
            self::TABLE,
            $this->esc($invoiceId),
            $paymentId
        ));
        return (int) $this->db->getAffectedRowsCount() === 1;
    }

    // Undoes a claim when the order could not be saved, so the provider's retry can settle it.
    public function release(string $invoiceId, int $paymentId): void
    {
        $this->db->queryExecute(sprintf(
            "UPDATE %s SET PAID_AT = NULL WHERE INVOICE_ID = '%s' AND PAYMENT_ID = %d",
            self::TABLE,
            $this->esc($invoiceId),
            $paymentId
        ));
    }

    private function esc(string $value): string
    {
        return $this->db->getSqlHelper()->forSql($value);
    }
}
