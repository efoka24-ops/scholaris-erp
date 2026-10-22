<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Workflows\Application\WorkflowService;
use Scholaris\Modules\Socle\Workflows\Infrastructure\WorkflowStore;

/**
 * Circuits de validation : /workflows (administrateur de l'établissement actif, permission `workflows.manage`) et, pour le super
 * administrateur de la plateforme, /platform/clients/{tenant}/workflows (réglage d'un client, sur sa fiche) et /platform/workflows
 * (plancher posé pour tous les clients). Toute modification exige une confirmation récente et un motif ; lecture comprise, les
 * routes de l'établissement ne rendent rien à un utilisateur d'un autre client (permission, puis 404 sur tout identifiant inconnu).
 */
final class WorkflowController
{
    public const PERMISSION = 'workflows.manage';
    public const CONFIRMATION_MAX_AGE = 600;
    public const PLATFORM_CONFIRMATION_MAX_AGE = 900;
    public const CONFIRMATION_TYPE = 'https://api.scholaris.cm/errors/second-factor-required';

    public function __construct(
        private readonly WorkflowService $service,
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly RecentSecondFactor $secondFactor,
        private readonly ClientProvisioning $clients,
    ) {
    }

    /** GET /workflows?application= et GET /workflows/{application} */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$level, $tenantId, $establishmentId] = $this->target($request, false);
        $application = $request->getAttribute('application') ?? ($request->getQueryParams()['application'] ?? null);
        if ($application !== null && (!is_string($application) || preg_match('/^[a-z][a-z0-9_]{1,30}$/', $application) !== 1)) {
            throw HttpException::validation(['application' => ['Clé d\'application invalide.']]);
        }

        $query = ListQuery::fromRequest($request);
        $after = ListQuery::decodeCursor($query->cursor);
        $page = [];
        $more = false;
        foreach ($this->service->list($level, $tenantId, $establishmentId, is_string($application) ? $application : null) as $circuit) {
            if ($after !== null && strcmp($circuit['id'], $after) <= 0) {
                continue;
            }
            if (count($page) === $query->perPage) {
                $more = true;

                break;
            }
            $page[] = $circuit;
        }
        $next = $more && $page !== [] ? ListQuery::encodeCursor($page[count($page) - 1]['id']) : null;

        return $this->noStore($this->responder->json(['data' => $page, 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]]));
    }

    /** GET .../{application}/{circuit} */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$level, $tenantId, $establishmentId] = $this->target($request, false);

        return $this->noStore($this->responder->json(['data' => $this->service->show($level, $tenantId, $establishmentId, ...$this->circuit($request))]));
    }

    /** PUT .../{application}/{circuit} : {steps: {...}, reason} */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        [$level, $tenantId, $establishmentId, $principal] = $this->target($request, true);
        $body = self::body($request, false);
        $reason = $body['reason'] ?? null;
        unset($body['reason']);
        [$application, $circuit] = $this->circuit($request);
        $view = $this->service->override($level, $tenantId, $establishmentId, $application, $circuit, $body, $reason, $principal->userId, self::ip($request));

        return $this->noStore($this->responder->json(['data' => $view]));
    }

    /** DELETE .../{application}/{circuit} : retour au défaut à ce niveau ; motif en corps JSON ou ?reason= */
    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        [$level, $tenantId, $establishmentId, $principal] = $this->target($request, true);
        $reason = self::body($request, true)['reason'] ?? ($request->getQueryParams()['reason'] ?? null);
        [$application, $circuit] = $this->circuit($request);

        return $this->noStore($this->responder->json(['data' => $this->service->reset($level, $tenantId, $establishmentId, $application, $circuit, $reason, $principal->userId, self::ip($request))]));
    }

    /** GET .../{application}/{circuit}/history */
    public function history(ServerRequestInterface $request): ResponseInterface
    {
        [$level, $tenantId, $establishmentId] = $this->target($request, false);

        return $this->noStore($this->responder->json(['data' => $this->service->history($level, $tenantId, $establishmentId, ...$this->circuit($request))]));
    }

    /**
     * Niveau visé, client, établissement et auteur, après contrôle des droits.
     *
     * @return array{0: string, 1: string, 2: ?string, 3: Principal}
     */
    private function target(ServerRequestInterface $request, bool $write): array
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $path = $request->getUri()->getPath();
        if (str_contains($path, '/platform/')) {
            return $this->platformTarget($request, $principal, $write);
        }
        $establishmentId = $request->getAttribute('establishment_id');
        if (!is_string($establishmentId) || $establishmentId === '') {
            throw HttpException::badRequest('L\'en-tête X-Establishment-Id est obligatoire.');
        }
        if ($principal->tenantId === WorkflowStore::PLATFORM_TENANT || !$this->permissions->can($principal, self::PERMISSION, $establishmentId)) {
            throw HttpException::forbidden('Permission requise : ' . self::PERMISSION . '.');
        }
        if ($write) {
            $this->assertRecent($principal, self::CONFIRMATION_MAX_AGE, self::CONFIRMATION_TYPE, 'Confirmez votre identité (mot de passe ou code) pour modifier un circuit de validation.');
        }

        return [WorkflowStore::ESTABLISHMENT, $principal->tenantId, $establishmentId, $principal];
    }

    /** @return array{0: string, 1: string, 2: ?string, 3: Principal} */
    private function platformTarget(ServerRequestInterface $request, Principal $principal, bool $write): array
    {
        $client = $request->getAttribute('tenant');
        $permission = is_string($client) ? ($write ? 'platform.clients.manage' : 'platform.clients.read') : ($write ? 'platform.settings.manage' : 'platform.clients.read');
        if ($principal->tenantId !== WorkflowStore::PLATFORM_TENANT || str_starts_with($principal->userId, 'svc:')
            || !$this->permissions->can($principal, 'platform.access') || !$this->permissions->can($principal, $permission)) {
            throw HttpException::forbidden('Réservé à l\'administrateur de plateforme.');
        }
        if ($write) {
            $this->assertRecent($principal, self::PLATFORM_CONFIRMATION_MAX_AGE, 'https://api.scholaris.cm/errors/two-factor-recent-required', 'Validez à nouveau votre double authentification pour cette action.');
        }
        if (!is_string($client)) {
            return [WorkflowStore::PLATFORM, WorkflowStore::PLATFORM_TENANT, null, $principal];
        }
        if (strlen($client) > 80 || !$this->clients->tenantExists($client) || $client === WorkflowStore::PLATFORM_TENANT) {
            throw HttpException::notFound('Client introuvable.');
        }

        return [WorkflowStore::CLIENT, $client, null, $principal];
    }

    private function assertRecent(Principal $principal, int $maxAge, string $type, string $detail): void
    {
        if (!$this->secondFactor->hasRecent($principal->tenantId, $principal->userId, $maxAge)) {
            throw new HttpException(403, 'Confirmation récente requise', $detail, $type);
        }
    }

    /** @return array{0: string, 1: string} */
    private function circuit(ServerRequestInterface $request): array
    {
        $application = $request->getAttribute('application');
        $circuit = $request->getAttribute('circuit');
        if (!is_string($application) || !is_string($circuit) || preg_match('/^[a-z][a-z0-9_]{1,40}$/', $application . '') !== 1 || preg_match('/^[a-z][a-z0-9_]{1,60}$/', $circuit) !== 1) {
            throw HttpException::notFound('Circuit de validation introuvable.');
        }

        return [$application, $circuit];
    }

    /** @return array<string, mixed> */
    private static function body(ServerRequestInterface $request, bool $allowEmpty): array
    {
        $raw = trim((string) $request->getBody());
        if ($raw === '') {
            if ($allowEmpty) {
                return [];
            }
            throw HttpException::validation(['steps' => ['Corps attendu : {"steps": {...}, "reason": "..."}.']]);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }

        return $data;
    }

    private static function ip(ServerRequestInterface $request): ?string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    private function noStore(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store');
    }
}
