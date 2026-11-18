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

    // The signature check alone, logged like in handle(). The handler calls it before taking any lock.
    public function verify(string $rawBody, string $signatureHeader, int $nowSeconds): bool
    {
        $problem = $this->signatureProblem($rawBody, $signatureHeader, $nowSeconds);
        if ($problem !== null) {
            $this->security($problem);
            return false;
        }
        return true;
    }

    private function signatureProblem(string $rawBody, string $signatureHeader, int $nowSeconds): ?string
    {
        return ($this->provider === 'btcpay')
            ? Signature::btcpayProblem($signatureHeader, $rawBody, $this->secret)
            : Signature::coinsnapProblem($signatureHeader, $rawBody, $this->secret, $nowSeconds);
    }

    /**
     * @param array|null $payment [
     *   'paymentId', 'invoiceId' => PS_INVOICE_ID (the newest invoice), 'orderId', 'sum', 'currency',
     *   'expected' => PS_STATUS_MESSAGE, 'paid' => bool, 'canceled' => bool,
     *   'record' => InvoiceRecords::find() of this invoice or null, 'legacyAllowed' => bool,
     *   'statusIsOurs' => bool (the order status is one this plugin sets), 'otherPaid' => bool (another payment of the order is paid)
     * ]
     * @return array{0: int, 1: string} HTTP status and the action to apply
     */
    public function handle(string $rawBody, string $signatureHeader, int $nowSeconds, ?array $payment): array
    {
        $problem = $this->signatureProblem($rawBody, $signatureHeader, $nowSeconds);
        if ($problem !== null) {
            $this->security($problem);
            return [401, self::NONE];
        }

        $invoiceId = self::invoiceIdFromBody($rawBody);
        if ($invoiceId === '' || $payment === null) {
            $this->info('not ours', $invoiceId);
            return [200, self::NONE];
        }
        $record = is_array($payment['record'] ?? null) ? $payment['record'] : null;
        // Every invoice the plugin creates gets a record naming its payment (since 1.2.0).
        if ($record !== null) {
            if ((string) $record['paymentId'] !== (string) ($payment['paymentId'] ?? '')) {
                $this->security('invoice not created for this payment', $invoiceId);
                return [200, self::NONE];
            }
        }
        // Older payments only stored their newest invoice id on the payment; accepted for a short time after the upgrade.
        elseif ((string) $payment['invoiceId'] !== $invoiceId) {
            $this->security('invoice not created for this payment', $invoiceId);
            return [200, self::NONE];
        }
        elseif (empty($payment['legacyAllowed'])) {
            $this->info('no invoice record', $invoiceId);
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
        if ($record !== null && $record['amount'] !== '') {
            // The invoice pays the payment only while its sum is the one the invoice was created for.
            if (InvoiceDecision::toMinorUnits($record['paymentSum']) !== InvoiceDecision::toMinorUnits($expectedAmount) || $record['paymentCurrency'] !== $expectedCurrency) {
                $this->security('payment sum changed', $invoiceId);
                return [200, self::NONE];
            }
            $expectedAmount = $record['amount'];
            $expectedCurrency = $record['currency'];
        }
        // Recorded on the payment by the first 1.2.0 builds, BTC conversion included, with the payment sum it was made for.
        elseif (preg_match('/^expected ([0-9]+(?:\.[0-9]+)?) ([A-Z]{3,4}) for ([0-9]+(?:\.[0-9]+)?) ([A-Z]{3,4})$/', (string) ($payment['expected'] ?? ''), $recorded)) {
            if (InvoiceDecision::toMinorUnits($recorded[3]) !== InvoiceDecision::toMinorUnits($expectedAmount) || $recorded[4] !== $expectedCurrency) {
                $this->security('payment sum changed', $invoiceId);
                return [200, self::NONE];
            }
            $expectedAmount = $recorded[1];
            $expectedCurrency = $recorded[2];
        }
        // Older payments invoiced in BTC (SATS and RUB on BTCPay Server) have no record; the payment binding is the check there.
        elseif ($this->provider === 'btcpay' && ($invoice['currency'] ?? '') === 'BTC' && $expectedCurrency !== 'BTC') {
            $expectedAmount = (string) ($invoice['amount'] ?? '');
            $expectedCurrency = 'BTC';
        }

        $decision = InvoiceDecision::decide($invoice, $this->provider === 'btcpay', $expectedAmount, $expectedCurrency);
        if ($decision !== InvoiceDecision::PAID && ($claimsPaid || $decision === InvoiceDecision::MISMATCH)) {
            $this->security($claimsPaid ? 'forged status' : 'amount mismatch', $invoiceId);
            return [200, self::NONE];
        }

        $state = [
            'paid' => !empty($payment['paid']) || !empty($record['paid']),
            'canceled' => !empty($payment['canceled']),
            'latest' => (string) $payment['invoiceId'] === $invoiceId,
            'statusIsOurs' => !empty($payment['statusIsOurs']),
            'otherPaid' => !empty($payment['otherPaid']),
        ];
        [$action, $reason] = self::action($decision, $state);
        $this->info($reason, $invoiceId);
        return [200, $action];
    }

    /**
     * Forward only. A paid payment never changes; only a real payment changes an order whose status
     * the merchant set by hand; an older invoice expiring never cancels the order.
     * @param array{paid: bool, canceled: bool, latest: bool, statusIsOurs: bool, otherPaid: bool} $state
     * @return array{0: string, 1: string} action and the reason for the log
     */
    public static function action(string $decision, array $state): array
    {
        if ($decision === InvoiceDecision::NONE) {
            return [self::NONE, 'status ignored'];
        }
        if ($state['paid']) {
            return [self::NONE, 'already processed'];
        }
        if ($decision === InvoiceDecision::PAID) {
            return [self::PAY, 'status paid'];
        }
        if ($state['canceled']) {
            return [self::NONE, 'order cancelled'];
        }
        if (!$state['statusIsOurs']) {
            return [self::NONE, 'manual status kept'];
        }
        if ($decision === InvoiceDecision::PROCESSING) {
            return [self::PROCESSING, 'status processing'];
        }
        if (!$state['latest']) {
            return [self::NONE, 'older invoice expired'];
        }
        if ($state['otherPaid']) {
            return [self::NONE, 'order partly paid'];
        }
        return [self::EXPIRE, 'status expired'];
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
