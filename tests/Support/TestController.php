<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Core\Tenancy\TenantTable;

/** Contrôleur de test : ressources cloisonnées, paiement idempotent, erreurs. */
final class TestController
{
    public static int $payments = 0;

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ConnectionFactory $connections,
        private readonly TenantContext $context,
    ) {
    }

    public function things(ServerRequestInterface $request): ResponseInterface
    {
        $this->connections->pdo()->exec('CREATE TABLE IF NOT EXISTS things (id VARCHAR(26) PRIMARY KEY, tenant_id VARCHAR(40), establishment_id VARCHAR(40), name VARCHAR(80))');
        $table = (new TenantTable($this->connections, $this->context, 'things'))->forEstablishment();

        return $this->responder->json(['data' => $table->select()]);
    }

    public function pay(ServerRequestInterface $request): ResponseInterface
    {
        self::$payments++;

        return $this->responder->json(['payment' => self::$payments, 'body' => (string) $request->getBody()], 201);
    }

    /** Accès aux données sans contexte client : doit être refusé (403), jamais servi. */
    public function orphan(ServerRequestInterface $request): ResponseInterface
    {
        $this->connections->pdo()->exec('CREATE TABLE IF NOT EXISTS things (id VARCHAR(26) PRIMARY KEY, tenant_id VARCHAR(40), establishment_id VARCHAR(40), name VARCHAR(80))');

        return $this->responder->json(['data' => (new TenantTable($this->connections, $this->context, 'things'))->select()]);
    }

    public function boom(ServerRequestInterface $request): ResponseInterface
    {
        throw new RuntimeException('secret interne : mot de passe de la base');
    }

    public function invalid(ServerRequestInterface $request): ResponseInterface
    {
        throw HttpException::validation(['grades.3.value' => ['La valeur 21 dépasse le maximum de 20.']], 'La note doit être comprise entre 0 et 20.');
    }

    public function me(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Principal $principal */
        $principal = $request->getAttribute('principal');

        return $this->responder->json(['user' => $principal->userId, 'tenant' => $this->context->tenantId()]);
    }
}
