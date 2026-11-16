<?php
declare(strict_types=1);

namespace Sale\Handlers\PaySystem\CoinsnapWebhook;

// Decides what a webhook may do to a payment. Only the invoice id is taken from the request;
// the invoice's state comes from the payment server's API.
class Processor
{
    public const PAY = 'pay';
    public const PROCESSING = 'processing';
    public const EXPIRE = 'expire';
    public const NONE = 'none';

    /** @var callable(string): array */
    private $fetchInvoice;
    /** @var callable(string): void */
    private $log;

    public function __construct(
        private string $provider,
        private string $secret,
        callable $fetchInvoice,
        callable $log
    ) {
        $this->fetchInvoice = $fetchInvoice;
        $this->log = $log;
    }

    public static function invoiceIdFromBody(string $rawBody): string
    {
        $body = json_decode($rawBody, true);
        $id = (is_array($body) && is_string($body['invoiceId'] ?? null)) ? $body['invoiceId'] : '';
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) ? $id : '';
    }

    /**
     * @param array|null $payment ['invoiceId' => PS_INVOICE_ID, 'orderId', 'sum', 'currency', 'paid' => bool, 'canceled' => bool]
     * @return array{0: int, 1: string} HTTP status and the action to apply
     */
    public function handle(string $rawBody, string $signatureHeader, int $nowSeconds, ?array $payment): array
    {
        $problem = ($this->provider === 'btcpay')
            ? Signature::btcpayProblem($signatureHeader, $rawBody, $this->secret)
            : Signature::coinsnapProblem($signatureHeader, $rawBody, $this->secret, $nowSeconds);
        if ($problem !== null) {
            $this->security($problem);
            return [401, self::NONE];
        }

        $invoiceId = self::invoiceIdFromBody($rawBody);
        if ($invoiceId === '' || $payment === null) {
            $this->info('not ours', $invoiceId);
            return [200, self::NONE];
        }
        // The plugin stores the invoice id on the payment when it creates the invoice.
        if ((string) $payment['invoiceId'] !== $invoiceId) {
            $this->security('invoice not created for this payment', $invoiceId);
            return [200, self::NONE];
        }
        $body = json_decode($rawBody, true);
        $claimsPaid = in_array($body['type'] ?? '', ['Settled', 'InvoiceSettled'], true);

        try {
            $invoice = ($this->fetchInvoice)($invoiceId);
        } catch (\Coinsnap\Exception\RequestException $e) {
            if (in_array($e->getCode(), [400, 404], true)) {
                $this->info('unknown invoice', $invoiceId);
                return [200, self::NONE];
            }
            $this->info('retry: API unavailable', $invoiceId);
            return [503, self::NONE];
        } catch (\Throwable $e) {
            $this->info('retry: API unavailable', $invoiceId);
            return [503, self::NONE];
        }

        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
        $orderId = ($this->provider === 'btcpay') ? ($metadata['orderId'] ?? '') : ($invoice['orderId'] ?? '');
        if (!is_scalar($orderId) || (string) $orderId !== (string) $payment['orderId']) {
            $this->security('invoice of another order', $invoiceId);
            return [200, self::NONE];
        }

        $expectedAmount = number_format(round((float) $payment['sum'], 2), 2, '.', '');
        $expectedCurrency = (string) $payment['currency'];
        // SATS and RUB orders are invoiced in BTC on BTCPay Server; the payment binding above is the check there.
        if ($this->provider === 'btcpay' && ($invoice['currency'] ?? '') === 'BTC' && $expectedCurrency !== 'BTC') {
            $expectedAmount = (string) ($invoice['amount'] ?? '');
            $expectedCurrency = 'BTC';
        }

        $decision = InvoiceDecision::decide($invoice, $this->provider === 'btcpay', $expectedAmount, $expectedCurrency);
        if ($decision !== InvoiceDecision::PAID && ($claimsPaid || $decision === InvoiceDecision::MISMATCH)) {
            $this->security($claimsPaid ? 'forged status' : 'amount mismatch', $invoiceId);
            return [200, self::NONE];
        }

        $action = self::action($decision, (bool) $payment['paid'], (bool) $payment['canceled']);
        $this->info($action === self::NONE ? 'no change' : 'status ' . $decision, $invoiceId);
        return [200, $action];
    }

    // A paid payment is never changed; a late payment may still settle a cancelled order.
    public static function action(string $decision, bool $paid, bool $canceled): string
    {
        if ($paid) {
            return self::NONE;
        }
        if ($decision === InvoiceDecision::PAID) {
            return self::PAY;
        }
        if ($canceled) {
            return self::NONE;
        }
        if ($decision === InvoiceDecision::PROCESSING) {
            return self::PROCESSING;
        }
        return $decision === InvoiceDecision::EXPIRED ? self::EXPIRE : self::NONE;
    }

    private function info(string $reason, string $invoiceId = ''): void
    {
        ($this->log)(self::line('INFO', $reason, $invoiceId));
    }

    // At most 10 security lines a minute, so a flood of fake requests can't fill the log.
    private function security(string $reason, string $invoiceId = ''): void
    {
        $file = sys_get_temp_dir() . '/coinsnap_bitrix_seclog_' . gmdate('YmdHi');
        $count = (int) @file_get_contents($file) + 1;
        @file_put_contents($file, (string) $count, LOCK_EX);
        if ($count === 11) {
            ($this->log)('Coinsnap SECURITY webhook: more rejections this minute, not logged');
        }
        if ($count <= 10) {
            ($this->log)(self::line('SECURITY', $reason, $invoiceId));
        }
    }

    private static function line(string $level, string $reason, string $invoiceId): string
    {
        return sprintf('Coinsnap %s webhook reason=%s invoice=%s', $level, $reason, $invoiceId !== '' ? $invoiceId : '-');
    }
}
