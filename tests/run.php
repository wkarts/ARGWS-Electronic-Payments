<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Argws\ElectronicPayments\{Account, ChargeId, ChargeRequest, ChargeStatus, Environment, PaymentException, PaymentMethod, ProviderRegistry, Reconciliation};
use Argws\ElectronicPayments\Asaas\{AsaasProvider, Connection};
use Argws\ElectronicPayments\Asaas\Reports\{AsaasReportCatalog, AsaasReportException};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Esperado ' . var_export($expected, true) . ', recebido ' . var_export($actual, true));
    }
}

function fails(string $type, callable $action): Throwable
{
    try {
        $action();
    } catch (Throwable $e) {
        if ($e instanceof $type) {
            return $e;
        }
        throw $e;
    }
    throw new RuntimeException("Era esperada uma exceção do tipo {$type}.");
}

final class Fixture
{
    public MockHandler $mock;
    public array $requests = [];
    public Environment $issuer = Environment::Production;
    public Connection $connection;
    public AsaasProvider $provider;
    public int $credentialReads = 0;

    public function __construct(Environment $environment = Environment::Sandbox)
    {
        $this->mock = new MockHandler();
        $account = new Account(12, null, 'conta-matriz', $environment);
        $this->connection = new Connection(
            $account,
            fn (Account $account) => $this->issuer,
            function (Account $account): string {
                ++$this->credentialReads;
                return 'test-' . $account->environment->value;
            },
            function (RequestInterface $request, array $options) {
                $this->requests[] = $request;
                return ($this->mock)($request, $options);
            },
        );
        $this->provider = new AsaasProvider($this->connection);
    }

    public function respond(array $row, int $status = 200): void
    {
        $this->mock->append(new Response($status, [], json_encode($row, JSON_THROW_ON_ERROR)));
    }

    public function path(): string
    {
        return end($this->requests)->getUri()->getPath();
    }

    public function payload(): array
    {
        return json_decode((string) end($this->requests)->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}

function row(string $status = 'PENDING'): array
{
    return ['id' => 'pay_1', 'status' => $status, 'value' => 12.34, 'externalReference' => 'venda-1',
        'invoiceUrl' => 'https://sandbox.asaas.com/i/example'];
}

function page(array $data, bool $more = false, ?int $total = null): array
{
    return ['data' => $data, 'hasMore' => $more, 'totalCount' => $total ?? count($data)];
}

$tests = [];
$tests['Cobranças, cliente e instruções pelos endpoints reais da SDK'] = function (): void {
    $f = new Fixture();
    $f->respond(['id' => 'cus_1']);
    same('cus_1', $f->provider->createCustomer('Cliente', '12345678909', 'cliente-1'));
    same('/v3/customers', $f->path());
    $f->respond(page([['id' => 'cus_1', 'externalReference' => 'cliente-1']]));
    same(['cus_1'], $f->provider->findCustomers('cliente-1'));
    foreach ($f->provider->methods() as $method) {
        $count = $method === PaymentMethod::CreditCard ? 3 : 1;
        $f->respond(row());
        $charge = $f->provider->create(new ChargeRequest('cus_1', 'venda-1', 1234, $method, new DateTimeImmutable('2027-01-01'), installments: $count));
        same('/v3/payments', $f->path());
        $payload = $f->payload();
        same(match ($method) {
            PaymentMethod::Pix => 'PIX', PaymentMethod::BankSlip => 'BOLETO', PaymentMethod::CreditCard => 'CREDIT_CARD',
        }, $payload['billingType']);
        same('api-sandbox.asaas.com', end($f->requests)->getUri()->getHost());
        same('test-sandbox', end($f->requests)->getHeaderLine('access_token'));
        same(12.34, $payload[$count === 1 ? 'value' : 'totalValue']);
        same($count, $payload['installmentCount'] ?? 1);
        same(ChargeStatus::Pending, $charge->status);
    }
    $f->respond(['encodedImage' => 'test', 'payload' => 'copia-cola']);
    same('copia-cola', $f->provider->pixInstructions($charge->id)['copyPaste']);
    same('/v3/payments/pay_1/pixQrCode', $f->path());
    $f->respond(['identificationField' => '123']);
    same('123', $f->provider->bankSlipInstructions($charge->id)['digitableLine']);
    $f->respond(row());
    same(1234, $f->provider->update($charge->id, 1234, new DateTimeImmutable('2027-01-02'))->amountCents);
    $f->respond(page([row()]));
    same('pay_1', $f->provider->findByReference('venda-1')[0]->id->id);
    $f->respond(page([row()]));
    fails(PaymentException::class, fn () => $f->provider->findByReference('outra'));
};

$tests['Cancelamento, restauração, devolução parcial e devolução de boleto'] = function (): void {
    $f = new Fixture();
    $charge = new ChargeId($f->connection->account, 'asaas', 'pay_1');
    $f->respond(['id' => 'pay_1', 'deleted' => true]);
    $f->provider->cancel($charge);
    same('DELETE', end($f->requests)->getMethod());
    $f->respond(['id' => 'pay_2', 'deleted' => true]);
    same(true, fails(PaymentException::class, fn () => $f->provider->cancel($charge))->outcomeUnknown);
    $f->respond(row());
    same(ChargeStatus::Pending, $f->provider->restore($charge)->status);
    $f->respond(row('REFUND_IN_PROGRESS'));
    same(ChargeStatus::RefundPending, $f->provider->refund($charge, 200, 'Devolução parcial')->status);
    same('/v3/payments/pay_1/refund', $f->path());
    same(2, $f->payload()['value']);
    $f->respond(['requestUrl' => 'https://sandbox.asaas.com/solicitar-estorno/test']);
    same('https://sandbox.asaas.com/solicitar-estorno/test', $f->provider->requestBankSlipRefund($charge));
    same('/v3/payments/pay_1/bankSlip/refund', $f->path());
};

$tests['Isolamento de estabelecimento e trava fiscal em jobs e consultas'] = function (): void {
    $f = new Fixture(Environment::Production);
    $id = new ChargeId($f->connection->account, 'asaas', 'pay_1');
    $f->issuer = Environment::Sandbox;
    fails(DomainException::class, fn () => $f->provider->refund($id));
    fails(DomainException::class, fn () => $f->provider->reports()->page('balance'));
    same(0, $f->credentialReads);
    same([], $f->requests);
    $f->issuer = Environment::Production;
    $f->respond(row('CONFIRMED'));
    $charge = $f->provider->get($id);
    same('api.asaas.com', end($f->requests)->getUri()->getHost());
    same(Reconciliation::PaymentConfirmed, Reconciliation::compare($charge, $id, 'venda-1', 1234));
    same(Reconciliation::Review, Reconciliation::compare($charge, $id, 'venda-1', 1233));
    foreach ([new Account(12, 7, 'conta-matriz', Environment::Production), new Account(13, null, 'conta-matriz', Environment::Production),
        new Account(12, null, 'conta-matriz', Environment::Sandbox), new Account(12, null, 'outra-conta', Environment::Production)] as $other) {
        fails(DomainException::class, fn () => $f->provider->cancel(new ChargeId($other, 'asaas', 'pay_1')));
    }
    same(1, count($f->requests));
    $f->issuer = Environment::Sandbox;
    fails(DomainException::class, fn () => $f->provider->get($id));
    $s = new Fixture();
    $s->issuer = Environment::Sandbox;
    $s->respond(row('RECEIVED'));
    $id = new ChargeId($s->connection->account, 'asaas', 'pay_1');
    same(Reconciliation::Simulation, Reconciliation::compare($s->provider->get($id), $id, 'venda-1', 1234));
};

$tests['Resultado inconclusivo não repete escrita nem expõe resposta remota'] = function (): void {
    $f = new Fixture();
    $f->respond(['errors' => [['description' => 'segredo-teste']]], 503);
    $error = fails(PaymentException::class, fn () => $f->provider->create(
        new ChargeRequest('cus_1', 'venda-1', 1234, PaymentMethod::Pix, new DateTimeImmutable())));
    same(true, $error->outcomeUnknown);
    same(false, str_contains($error->getMessage(), 'segredo-teste'));
    same(1, count($f->requests));
};

$tests['Webhook autenticado e escopado, preserva ID para deduplicação'] = function (): void {
    $f = new Fixture();
    $body = json_encode(['id' => 'evt_1', 'event' => 'PAYMENT_RECEIVED', 'payment' => row('RECEIVED')], JSON_THROW_ON_ERROR);
    fails(DomainException::class, fn () => $f->provider->webhook($body, 'errado', 'segredo'));
    $event = $f->provider->webhook($body, 'segredo', 'segredo');
    same(ChargeStatus::Settled, $event->status);
    same('evt_1', $event->eventId);
    same('conta-matriz', $event->charge->account->accountId);
    $key = \Asaas\Sdk\Webhook\WebhookToken::generate();
    $hash = AsaasProvider::webhookTokenHash($key);
    same($event->eventId, $f->provider->webhookWithTokenHash($body, $key, $hash)->eventId);
    fails(DomainException::class, fn () => $f->provider->webhookWithTokenHash($body, $hash, $hash));
    fails(DomainException::class, fn () => $f->provider->webhookWithTokenHash($body, '', $hash));
    fails(DomainException::class, fn () => $f->provider->webhookWithTokenHash($body, 'outro', $hash));
    same(null, $f->provider->webhook('{"event":"TRANSFER_DONE"}', 'segredo', 'segredo'));
};

$tests['Catálogo, consultas e CSV completo com detecção de repetição'] = function (): void {
    $f = new Fixture();
    $reports = $f->provider->reports();
    same(20, count($reports->catalog()));
    $sdk = $f->connection->sdk();
    foreach (AsaasReportCatalog::definitions() as $definition) {
        same(true, is_callable([$sdk->{$definition['service']}, $definition['method']]));
    }
    $f->respond(['balance' => 12.34]);
    same('12.34', $reports->page('balance')['report']['rows'][0]['balance']);
    $f->respond(page([row('RECEIVED')]));
    same('RECEIVED', $reports->page('payments', ['status' => 'RECEIVED'])['report']['rows'][0]['status']);
    $first = ['id' => 'fin_1', 'value' => 12.34, 'description' => '=teste'];
    $last = ['id' => 'fin_2', 'value' => -0.37, 'description' => 'Tarifa'];
    $f->respond(page([$first], true, 2));
    $f->respond(page([$last], false, 2));
    $export = $reports->export('statement', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'offset' => 20]);
    same(2, $export->rows);
    $csv = stream_get_contents($export->stream());
    same(true, str_contains($csv, 'fin_1') && str_contains($csv, 'fin_2') && str_contains($csv, "'=teste"));
    same(true, str_contains(end($f->requests)->getUri()->getQuery(), 'offset=1'));
    $export->close();
    $f->respond(page([$first], true, 2));
    $f->respond(page([$first], false, 2));
    fails(AsaasReportException::class, fn () => $reports->export('statement'));
    fails(InvalidArgumentException::class, fn () => $reports->page('balance', ['unknown' => 'x']));
};

$tests['Seleção de provedor por conta'] = function (): void {
    $f = new Fixture();
    $registry = new ProviderRegistry();
    $registry->register('asaas', fn (Account $account) => $f->provider);
    same($f->provider, $registry->forAccount('asaas', $f->connection->account));
    fails(DomainException::class, fn () => $registry->forAccount('desconhecido', $f->connection->account));
};

foreach ($tests as $name => $run) {
    $run();
    echo "OK  ", $name, PHP_EOL;
}
echo count($tests), " grupos de testes concluídos.", PHP_EOL;
