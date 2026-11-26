# ARGWS Electronic Payments

Pacote Composer `argws/electronic-payments` para aplicações PHP 8.2 ou superior. O adaptador Asaas utiliza `argws/asaas-sdk-php` 0.4.x. Oferece Pix, boleto, cartão de crédito com checkout hospedado, consulta de recebimentos, conciliação por parcela e exportação de extrato.

## Uso direto desta distribuição

Este ZIP já inclui o autoloader, a SDK Asaas 0.4.0 e as dependências de execução. Extraia a pasta inteira. Em um script PHP localizado ao lado da pasta extraída, carregue:

```php
require_once __DIR__ . '/electronic-payments/vendor/autoload.php';
```

O mesmo autoloader registra o pacote de pagamentos e a SDK em `vendor/argws/asaas-sdk-php/`. Para uma aplicação que já gerencia as dependências pelo Composer, a instalação descrita abaixo permite usar o autoloader principal da aplicação.

## Instalação pelo Composer

Para instalar a partir do ZIP, extraia a pasta `electronic-payments` em `packages/` dentro da aplicação e execute na raiz dessa aplicação:

```bash
composer config repositories.electronic-payments path ./packages/electronic-payments
composer config repositories.asaas-sdk git https://github.com/wkarts/asaas-sdk-argws.git
composer require 'argws/electronic-payments:@dev'
```

Após a primeira release estável, instale pela tag do repositório:

```bash
composer config repositories.electronic-payments git https://github.com/wkarts/ARGWS-Electronic-Payments.git
composer config repositories.asaas-sdk git https://github.com/wkarts/asaas-sdk-argws.git
composer require "argws/electronic-payments:^0.1"
```

A origem Git garante acesso à tag da SDK requerida. O Composer considera os repositórios definidos no projeto consumidor. O lock do consumidor registra os commits efetivamente instalados.

## Versionamento e publicação

A versão oficial vem da tag Git em formato `vMAJOR.MINOR.PATCH`. Cada push na `main` passa pela matriz PHP e pela construção do ZIP. Quando o workflow **Verificar pacote PHP** termina com sucesso, **Publicar release do pacote** usa o SHA validado para publicar no GitHub Releases.

- Primeira versão automática: `v0.1.0`. O incremento padrão é patch. Para solicitar minor ou major, inclua o trailer Git `Release-Bump: minor` ou `Release-Bump: major` em um commit que entrará na `main` (no squash, inclua o trailer na mensagem final). Entre dois releases prevalece o maior incremento solicitado.
- Uma tag estável enviada manualmente em um commit da `main` também aciona a publicação. Reruns do mesmo commit reutilizam a tag. Uma validação atrasada de um commit já superado por uma versão estável dispensa nova tag.
- O ZIP oficial inclui `vendor/autoload.php`, a SDK Asaas e as dependências instaladas pelo lock, além de `release-info.json` com versão, tag e SHA do código. O workflow extrai o arquivo e roda os testes antes de publicar. Para conferir a integridade, baixe também o arquivo `.sha256` e execute `sha256sum -c argws-electronic-payments-vX.Y.Z.zip.sha256`.
- A publicação utiliza `GITHUB_TOKEN` com permissão de escrita em conteúdo. Quando as regras do repositório exigirem outro token autorizado para tags, configure `RELEASE_TOKEN`. Para atualizar o índice de um pacote já cadastrado no Packagist, configure `PACKAGIST_USERNAME` e `PACKAGIST_TOKEN`.

## Conexão e cobrança

Este exemplo pressupõe que a aplicação já autenticou o operador e carregou o estabelecimento da venda. Os dois callbacks consultam o cadastro do consumidor. O token e o ambiente fiscal são resolvidos para a empresa, filial e conta indicadas, inclusive em jobs. Use `null` para a Matriz.

```php
use Argws\ElectronicPayments\{Account, ChargeRequest, Environment, PaymentMethod};
use Argws\ElectronicPayments\Asaas\{AsaasProvider, Connection};

$account = new Account(
    companyId: 12,
    branchId: null,
    accountId: 'carteira-asaas-matriz', // Identidade persistente da conexão verificada.
    environment: Environment::Sandbox,
);

$provider = new AsaasProvider(new Connection(
    $account,
    issuerEnvironment: fn (Account $account): Environment =>
        $cadastroFinanceiro->ambienteFiscalAtual($account),
    apiKey: fn (Account $account): string =>
        $cadastroFinanceiro->tokenDescriptografado($account),
));

$charge = $provider->create(new ChargeRequest(
    customerId: $clienteAsaasId,
    reference: $referenciaUnicaDaParcela,
    amountCents: 12590,
    method: PaymentMethod::Pix,
    dueDate: new DateTimeImmutable('today'),
));

$pix = $provider->pixInstructions($charge->id);
echo $pix['copyPaste'];
```

O cadastro do ERP implementa os callbacks conforme sua persistência. Para mapear o ambiente fiscal do FERSOFT, converta explicitamente 1 em `Environment::Production` e 2 em `Environment::Sandbox`. Rejeite qualquer valor indefinido. O pacote bloqueia a conexão de produção para emitente em homologação antes da leitura da credencial. Emitente em produção pode operar nos dois ambientes. Resolva o token por empresa, filial, conta e ambiente em cada chamada.

Guarde os campos do `ChargeId`: conta lógica, empresa, filial, ambiente, provedor e ID externo. Reconstrua esse objeto com a identidade original ao consultar uma cobrança ou processar um job. Quando cadastrar outra carteira, atribua a ela outro identificador. Os clientes remotos também pertencem à conexão que os criou.

| Operação | API do pacote |
|---|---|
| Criar ou localizar pagador | `createCustomer()` e `findCustomers()` por referência externa |
| Emitir Pix, boleto ou cartão | `create()` com o meio desejado. Para cartão, abra `$charge->checkoutUrl` no navegador. Para boleto, use `bankSlipUrl` e `bankSlipInstructions()`. |
| Cartão parcelado | Informe o valor total em centavos e `installments`. O retorno identifica a cobrança individual e o parcelamento. Consulte as demais parcelas pelo filtro `installment` do relatório `payments`. |
| Atualizar, consultar ou recuperar vínculo | `update()`, `get()`, `findByReference()` e paginação por offset/limite |
| Cancelar ou restaurar | `cancel()` confirma ID e exclusão; `restore()` recupera a cobrança |
| Estornar Pix/cartão | `refund($id, $centavos, $motivo)`; omitir valor solicita estorno integral conforme elegibilidade na conta |
| Solicitar devolução de boleto | `requestBankSlipRefund()` devolve o link para o pagador informar os dados. A confirmação financeira ocorre posteriormente. |
| Verificar vínculo e valor | `Reconciliation::compare($charge, $idEsperado, $referencia, $centavosDaParcela)` |

Valores de cobrança usam centavos de BRL. A aplicação fornece uma referência estável para a parcela e persiste sua intenção antes do envio. Se uma escrita lançar `PaymentException` com `outcomeUnknown`, consulte a cobrança ou a referência previamente salva antes de decidir por nova tentativa. A busca pode retornar várias correspondências. Em vendas com mais de uma forma, use somente a parte atribuída ao provedor.

## Recebimentos, extrato e download

```php
$reports = $provider->reports();
$catalogo = $reports->catalog();
$saldo = $reports->page('balance')['report']['rows'][0]['balance'];
$recebidos = $reports->page('payments', [
    'status' => 'RECEIVED',
    'date_field' => 'paymentDate',
    'date_from' => '2026-11-01',
    'date_to' => '2026-11-30',
])['report'];

$arquivo = $reports->export('statement', [
    'date_from' => '2026-11-01',
    'date_to' => '2026-11-30',
]);
try {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $arquivo->filename . '"');
    fpassthru($arquivo->stream());
} finally {
    $arquivo->close();
}
```

O catálogo expõe os filtros e as colunas de cada consulta. As 20 consultas abrangem extrato, cobranças e recebimentos, saldo, estatísticas, resumo de splits, splits recebidos e enviados, transferências, antecipações, contas pagas/agendadas, parcelamentos, assinaturas, transações Pix, autorizações e instruções de Pix Automático, chargebacks, estornos por cobrança, notas de serviço, negativações e recargas. Cada recurso depende das permissões da conta Asaas.

`page()` serve consultas paginadas. `export()` percorre o resultado inteiro e devolve um arquivo temporário completo em CSV UTF-8 com BOM, separador ponto e vírgula e decimal brasileiro. Paginação inconsistente, registros repetidos ou falha durante a leitura interrompem o download. Use os IDs das linhas de extrato para conciliar crédito bruto, tarifa, líquido e transferência na conta correta.

## Webhook e baixa financeira

1. A aplicação identifica a conexão pelo endpoint cadastrado e carrega o segredo de webhook daquela conexão e ambiente.
2. Passe o corpo original, o cabeçalho `asaas-access-token` e o segredo salvo para `$provider->webhook($body, $header, $secret)`. O método valida o segredo e o ambiente e devolve ID do evento, tipo, referência e cobrança. Eventos de outros recursos retornam `null`.
3. Persista o evento antes de responder sucesso e deduplique por provedor, conta, ambiente e ID do evento. Em uma transação do ERP, bloqueie o título e a movimentação correspondente. Consulte o estado vigente para eventos tardios ou fora de ordem.
4. Use `Reconciliation::compare()` para conferir a parcela esperada. Em produção, o resultado distingue confirmação comercial e crédito disponível. Preserve uma única baixa quando a cobrança passar de confirmada a recebida. Em sandbox o parecer é `Simulation`. Devolução, divergência e recebimento em dinheiro exigem tratamento financeiro próprio. O ERP decide sobre estoque, emissão fiscal, baixa e conciliação.

## Outros provedores

A aplicação pode receber `Contracts\PaymentProvider` por injeção ou resolver o provedor com `ProviderRegistry::forAccount()`. Registre uma fábrica por nome, construindo cada instância com a conta informada. `methods()` enumera os meios implementados pelo adaptador. Os contratos opcionais `Refunds`, `PixInstructions`, `BankSlipInstructions`, `PaymentWebhooks` e `ProvidesReports` permitem expor apenas as capacidades oferecidas. O consumidor pode manter outro meio de pagamento já instalado em paralelo.

## Verificação

```bash
composer validate --strict
composer install
composer test
```

A suíte exercita o adaptador com a SDK real e transporte HTTP controlado. Os testes cobrem emissão, devolução, escopo da conta, trava fiscal, webhook, conciliação e CSV com várias páginas.
