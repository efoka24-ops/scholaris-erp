<?php

declare(strict_types=1);

namespace Scholaris\Core\Http\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Idempotency\IdempotencyStore;
use Scholaris\Core\Security\Principal;
use Throwable;

/**
 * Idempotency-Key obligatoire sur les POST des chemins sensibles (config api.idempotent_paths).
 *  - même clé, même requête : la réponse enregistrée est rejouée (en-tête Idempotent-Replayed) ;
 *  - même clé, requête différente : 422 ;
 *  - même clé, opération encore en cours : 409.
 * Une exception libère la clé pour permettre une nouvelle tentative.
 */
final class Idempotency implements MiddlewareInterface
{
    private const TTL = 86400;
    private const KEY_FORMAT = '/^[A-Za-z0-9_\-:.]{8,128}$/';

    /** @param list<string> $paths motifs regex de chemins */
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly array $paths,
        private readonly Psr17Factory $factory = new Psr17Factory(),
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() !== 'POST' || !$this->applies($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $key = trim($request->getHeaderLine('Idempotency-Key'));
        if ($key === '' || preg_match(self::KEY_FORMAT, $key) !== 1) {
            throw HttpException::badRequest('L\'en-tête Idempotency-Key est obligatoire (8 à 128 caractères : lettres, chiffres, _ - : .).');
        }

        $principal = $request->getAttribute('principal');
        $tenant = $principal instanceof Principal ? $principal->tenantId : 'anonyme';
        $route = $request->getMethod() . ' ' . $request->getUri()->getPath();
        $body = (string) $request->getBody();
        if ($request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        }
        // L'auteur entre dans l'empreinte : la même clé rejouée par un autre utilisateur du client n'obtient jamais la
        // réponse enregistrée (422 « requête différente »), ce qui compte depuis que parents et apprenants paient (D2).
        $hash = hash('sha256', $route . "\n" . ($principal instanceof Principal ? $principal->userId : '') . "\n" . $body);

        if (!$this->store->reserve($tenant, $key, $route, $hash, self::TTL)) {
            return $this->replay($tenant, $key, $hash);
        }

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $this->store->release($tenant, $key);
            throw $e;
        }

        $this->store->complete($tenant, $key, $response->getStatusCode(), (string) $response->getBody());
        $response->getBody()->rewind();

        return $response;
    }

    private function applies(string $path): bool
    {
        foreach ($this->paths as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function replay(string $tenant, string $key, string $hash): ResponseInterface
    {
        $stored = $this->store->find($tenant, $key);
        if ($stored === null || $stored['request_hash'] !== $hash) {
            throw HttpException::validation(
                ['Idempotency-Key' => ['Cette clé a déjà servi pour une requête différente.']],
                'Clé d\'idempotence réutilisée avec une autre requête.',
            );
        }
        if ($stored['status_code'] === null) {
            throw HttpException::conflict('Une opération avec cette clé est déjà en cours.');
        }

        return $this->factory->createResponse($stored['status_code'])
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Idempotent-Replayed', 'true')
            ->withBody($this->factory->createStream((string) $stored['response_body']));
    }
}
