<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments\Asaas;

use Argws\ElectronicPayments\{Charge, ChargeId, ChargeRequest, ChargeStatus, PaymentEvent, PaymentException, PaymentMethod};
use Argws\ElectronicPayments\Asaas\Reports\AsaasReports;
use Argws\ElectronicPayments\Contracts\{BankSlipInstructions, FinancialReports, PaymentProvider, PaymentWebhooks, PixInstructions, ProvidesReports, Refunds};
use Asaas\Sdk\AsaasSdk;
use Asaas\Sdk\Exception\{ApiException, TransportException};
use Asaas\Sdk\Webhook\WebhookToken;

final readonly class AsaasProvider implements PaymentProvider, Refunds, PixInstructions, BankSlipInstructions, PaymentWebhooks, ProvidesReports
{
    public const NAME = 'asaas';

    public function __construct(private Connection $connection) {}

    public function methods(): array
    {
        return [PaymentMethod::Pix, PaymentMethod::BankSlip, PaymentMethod::CreditCard];
    }

    public function reports(): FinancialReports
    {
        return new AsaasReports($this->connection);
    }

    public function createCustomer(string $name, string $taxId, string $reference, ?string $email = null): string
    {
        if (trim($name) === '' || trim($taxId) === '' || trim($reference) === '') {
            throw new \InvalidArgumentException('Informe nome, CPF/CNPJ e referência do cliente.');
        }
        $data = array_filter(['name' => $name, 'cpfCnpj' => $taxId, 'externalReference' => $reference, 'email' => $email],
            static fn ($value) => $value !== null);
        $result = $this->request(fn ($sdk) => $sdk->customer->createNewCustomer(payload: $data), true);
        return $this->field($result, 'id', true);
    }

    /** @return list<string> IDs externos. Múltiplas correspondências exigem conferência. */
    public function findCustomers(string $reference, int $offset = 0, int $limit = 100): array
    {
        $query = $this->referenceQuery($reference, $offset, $limit);
        $response = $this->request(fn ($sdk) => $sdk->customer->listCustomers(query: $query));
        return array_map(fn ($row) => $this->field($row, 'id'), $this->matchedRows($response, $reference));
    }

    public function create(ChargeRequest $request): Charge
    {
        $payload = [
            'customer' => $request->customerId,
            'externalReference' => $request->reference,
            'dueDate' => $request->dueDate->format('Y-m-d'),
            'billingType' => match ($request->method) {
                PaymentMethod::Pix => 'PIX',
                PaymentMethod::BankSlip => 'BOLETO',
                PaymentMethod::CreditCard => 'CREDIT_CARD',
            },
        ];
        if ($request->description !== null) {
            $payload['description'] = $request->description;
        }
        if ($request->installments === 1) {
            $payload['value'] = $request->amountCents / 100;
        } else {
            $payload['installmentCount'] = $request->installments;
            $payload['totalValue'] = $request->amountCents / 100;
        }
        $result = $this->request(fn (AsaasSdk $sdk) => $request->method === PaymentMethod::CreditCard
            ? $sdk->payment->createHostedCardPayment($payload)
            : $sdk->payment->createNewPayment(payload: $payload), true);
        $charge = $this->charge($result, write: true);
        if ($charge->reference !== $request->reference || ($request->installments === 1 && $charge->amountCents !== $request->amountCents)) {
            throw new PaymentException('A referência ou o valor devolvido diverge da cobrança solicitada.', true);
        }
        return $charge;
    }

    public function get(ChargeId $charge): Charge
    {
        $path = $this->path($charge);
        return $this->charge($this->request(fn ($sdk) => $sdk->payment->retrieveASinglePayment($path)), $charge);
    }

    public function findByReference(string $reference, int $offset = 0, int $limit = 100): array
    {
        $query = $this->referenceQuery($reference, $offset, $limit);
        $response = $this->request(fn ($sdk) => $sdk->payment->listPayments(query: $query));
        return array_map(fn ($row) => $this->charge($row), $this->matchedRows($response, $reference));
    }

    public function update(ChargeId $charge, int $amountCents, \DateTimeImmutable $dueDate): Charge
    {
        $path = $this->path($charge);
        $payload = ['value' => $this->amount($amountCents), 'dueDate' => $dueDate->format('Y-m-d')];
        $result = $this->request(fn ($sdk) => $sdk->payment->updateExistingPayment($path, payload: $payload), true);
        return $this->charge($result, $charge, true);
    }

    public function cancel(ChargeId $charge): void
    {
        $path = $this->path($charge);
        $result = $this->request(fn ($sdk) => $sdk->payment->deletePayment($path), true);
        if (($result['id'] ?? null) !== $charge->id || ($result['deleted'] ?? null) !== true) {
            throw new PaymentException('Exclusão sem confirmação. Consulte a cobrança antes de repetir.', true);
        }
    }

    public function restore(ChargeId $charge): Charge
    {
        $path = $this->path($charge);
        $result = $this->request(fn ($sdk) => $sdk->payment->restoreRemovedPayment($path), true);
        $restored = $this->charge($result, $charge, true);
        if ($restored->status === ChargeStatus::Cancelled) {
            throw new PaymentException('Restauração sem confirmação.', true);
        }
        return $restored;
    }

    /** Estorno de Pix/cartão. Para boleto, a API oferece requestBankSlipRefund(). */
    public function refund(ChargeId $charge, ?int $amountCents = null, ?string $reason = null): Charge
    {
        $path = $this->path($charge);
        $payload = [];
        if ($amountCents !== null) {
            $payload['value'] = $this->amount($amountCents);
        }
        if ($reason !== null) {
            $payload['description'] = $reason;
        }
        $result = $this->request(fn ($sdk) => $sdk->payment->refundPayment($path, payload: $payload ?: null), true);
        return $this->charge($result, $charge, true);
    }

    public function requestBankSlipRefund(ChargeId $charge): string
    {
        $path = $this->path($charge);
        $result = $this->request(fn ($sdk) => $sdk->paymentRefund->refundBankSlip($path), true);
        return $this->field($result, 'requestUrl', true);
    }

    public function pixInstructions(ChargeId $charge): array
    {
        $path = $this->path($charge);
        $result = $this->request(fn ($sdk) => $sdk->payment->getQrCodeForPixPayments($path));
        return [
            'copyPaste' => $this->field($result, 'payload'),
            'imageBase64' => $this->field($result, 'encodedImage'),
            'expiresAt' => $result['expirationDate'] ?? null,
        ];
    }

    public function bankSlipInstructions(ChargeId $charge): array
    {
        $path = $this->path($charge);
        $result = $this->request(fn ($sdk) => $sdk->payment->getDigitableBillLine($path));
        return [
            'digitableLine' => $this->field($result, 'identificationField'),
            'barCode' => $result['barCode'] ?? null,
            'ourNumber' => $result['nossoNumero'] ?? null,
        ];
    }

    public function webhook(string $body, #[\SensitiveParameter] string $providedToken, #[\SensitiveParameter] string $configuredSecret): ?PaymentEvent
    {
        if (!WebhookToken::matches($configuredSecret, $providedToken)) {
            throw new \DomainException('Segredo do webhook inválido.');
        }
        return $this->parseWebhook($body);
    }

    /** Store only this fingerprint of a randomly generated webhook token. */
    public static function webhookTokenHash(#[\SensitiveParameter] string $token): string
    {
        if ($token === '') {
            throw new \DomainException('Token do webhook ausente.');
        }
        return hash('sha256', $token);
    }

    public function webhookWithTokenHash(string $body, #[\SensitiveParameter] string $providedToken, string $storedHash): ?PaymentEvent
    {
        if (!hash_equals($storedHash, self::webhookTokenHash($providedToken))) {
            throw new \DomainException('Token do webhook inválido.');
        }
        return $this->parseWebhook($body);
    }

    private function parseWebhook(string $body): ?PaymentEvent
    {
        $this->connection->authorize();
        $event = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new \UnexpectedValueException('Evento Asaas inválido.');
        }
        $eventType = $this->field($event, 'event');
        if (!str_starts_with($eventType, 'PAYMENT_')) {
            return null;
        }
        $payment = $event['payment'] ?? null;
        if (!is_array($payment)) {
            throw new \UnexpectedValueException('Evento sem cobrança.');
        }
        $status = $this->status($payment);
        if ($eventType === 'PAYMENT_DELETED') {
            $status = ChargeStatus::Cancelled;
        } elseif ($eventType === 'PAYMENT_PARTIALLY_REFUNDED') {
            $status = ChargeStatus::PartiallyRefunded;
        }
        return new PaymentEvent($this->field($event, 'id'), $eventType, $this->id($payment), $status, $payment['externalReference'] ?? null);
    }

    private function path(ChargeId $charge): array
    {
        return ['id' => $charge->forAccount($this->connection->account, self::NAME)];
    }

    private function id(array $row): ChargeId
    {
        return new ChargeId($this->connection->account, self::NAME, $this->field($row, 'id'));
    }

    private function status(array $row): ChargeStatus
    {
        if (($row['deleted'] ?? false) === true) {
            return ChargeStatus::Cancelled;
        }
        if (in_array($row['status'] ?? null, ['RECEIVED', 'CONFIRMED'], true)) {
            foreach ($row['refunds'] ?? [] as $refund) {
                if (($refund['status'] ?? '') === 'DONE') {
                    // Mesmo com estado comercial recebido, um estorno efetivo exige conciliação.
                    return ChargeStatus::PartiallyRefunded;
                }
            }
        }
        return match ($row['status'] ?? null) {
            'PENDING', 'AWAITING_RISK_ANALYSIS' => ChargeStatus::Pending,
            'AUTHORIZED' => ChargeStatus::Authorized,
            'CONFIRMED' => ChargeStatus::Confirmed,
            'RECEIVED' => ChargeStatus::Settled,
            'RECEIVED_IN_CASH' => ChargeStatus::ReceivedExternally,
            'OVERDUE' => ChargeStatus::Overdue,
            'REFUND_REQUESTED', 'REFUND_IN_PROGRESS' => ChargeStatus::RefundPending,
            'REFUNDED' => ChargeStatus::Refunded,
            'PARTIALLY_REFUNDED' => ChargeStatus::PartiallyRefunded,
            'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'AWAITING_CHARGEBACK_REVERSAL' => ChargeStatus::Disputed,
            default => ChargeStatus::Unknown,
        };
    }

    private function charge(array $row, ?ChargeId $expected = null, bool $write = false): Charge
    {
        $value = $row['value'] ?? null;
        $id = $this->field($row, 'id', $write);
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/', $id) || ($expected && $expected->id !== $id)
            || !is_numeric($value) || !is_finite((float) $value) || $value < 0 || $value > 999999999999.99) {
            throw new PaymentException('Resposta de cobrança inconsistente. Consulte a referência original.', $write);
        }
        return new Charge($this->id($row), $row['externalReference'] ?? null, $this->status($row),
            $this->field($row, 'status', $write), (int) round((float) $value * 100), $row['invoiceUrl'] ?? null,
            $row['bankSlipUrl'] ?? null, $row['installment'] ?? null);
    }

    private function amount(int $cents): float
    {
        if ($cents < 1 || $cents > 99999999999999) {
            throw new \InvalidArgumentException('Valor em centavos fora do intervalo.');
        }
        return $cents / 100;
    }

    private function referenceQuery(string $reference, int $offset, int $limit): array
    {
        if (trim($reference) === '' || $offset < 0 || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Referência ou paginação inválida.');
        }
        return ['externalReference' => $reference, 'offset' => $offset, 'limit' => $limit];
    }

    private function matchedRows(array $response, string $reference): array
    {
        $rows = $response['data'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new PaymentException('Lista de resultados incompleta.');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['externalReference'] ?? null) !== $reference) {
                throw new PaymentException('Referência divergente na consulta ao provedor.');
            }
        }
        return $rows;
    }

    private function field(array $data, string $field, bool $write = false): string
    {
        if (!is_string($data[$field] ?? null) || $data[$field] === '') {
            throw new PaymentException('Campo obrigatório ausente na resposta do provedor: ' . $field, $write);
        }
        return $data[$field];
    }

    private function request(\Closure $operation, bool $write = false): array
    {
        $sdk = $this->connection->sdk();
        try {
            $response = $operation($sdk);
        } catch (ApiException $error) {
            $status = $error->getStatusCode();
            throw new PaymentException('A operação recebeu HTTP ' . $status . ' do Asaas.', $write && ($status >= 500 || $status === 408), $status);
        } catch (TransportException) {
            throw new PaymentException('Falha de comunicação com o Asaas.', $write);
        }
        if (!is_array($response) || $response === []) {
            throw new PaymentException('Resposta vazia ou inválida do Asaas.', $write);
        }
        return $response;
    }
}
