<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments\Asaas;

use Argws\ElectronicPayments\{Account, Environment};
use Asaas\Sdk\AsaasSdk;
use Asaas\Sdk\Config\AsaasConfig;
use Asaas\Sdk\Http\Environment as AsaasEnvironment;
use Asaas\Sdk\Http\RetryMiddleware;
use GuzzleHttp\{Client, HandlerStack, Middleware};
use Psr\Http\Message\RequestInterface;

final readonly class Connection
{
    /**
     * @param \Closure(Account): Environment $issuerEnvironment Ambiente fiscal vigente.
     * @param \Closure(Account): string $apiKey Credencial da conta e do ambiente solicitados.
     * @param ?\Closure $handler Transporte Guzzle opcional para testes de contrato.
     */
    public function __construct(
        public Account $account,
        private \Closure $issuerEnvironment,
        private \Closure $apiKey,
        private ?\Closure $handler = null,
    ) {}

    public function authorize(): void
    {
        $this->account->authorize(($this->issuerEnvironment)($this->account));
    }

    /** @internal Cria um cliente de uso imediato já vinculado à conta autorizada. */
    public function sdk(): AsaasSdk
    {
        $this->authorize();
        $key = ($this->apiKey)($this->account);
        if (!is_string($key) || trim($key) === '' || trim($key) !== $key || preg_match('/[\r\n]/', $key)) {
            throw new \DomainException('Credencial Asaas ausente ou inválida para este estabelecimento e ambiente.');
        }
        $environment = match ($this->account->environment) {
            Environment::Production => AsaasEnvironment::Production,
            Environment::Sandbox => AsaasEnvironment::Sandbox,
        };
        $stack = HandlerStack::create($this->handler);
        $stack->push(RetryMiddleware::create());
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->authorize();
            return $request;
        }));
        $http = new Client([
            'base_uri' => $environment->value,
            'allow_redirects' => false,
            'timeout' => 30,
            'connect_timeout' => 10,
            'handler' => $stack,
        ]);
        return new AsaasSdk(new AsaasConfig($key, $environment, 'ARGWS-Electronic-Payments', httpClient: $http));
    }
}
