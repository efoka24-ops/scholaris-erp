<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use Nyholm\Psr7\Factory\Psr17Factory;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Organisation\Application\OrgClock;
use Scholaris\Modules\Socle\Organisation\Http\Policies\OrganisationPolicy;
use Scholaris\Modules\Socle\Organisation\Http\RequestContext;
use Scholaris\Modules\Socle\Organisation\Http\Requests\ValidatedRequest;
use Scholaris\Modules\Socle\Organisation\Http\Resources\Resource;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;
use Scholaris\Modules\Socle\Organisation\Infrastructure\ResourceRepository;

/**
 * Contrôleur de ressource rattachée à l'établissement actif (en-tête X-Establishment-Id) : liste, détail,
 * création, modification. Chaque sous-classe déclare sa table, sa requête de validation, sa ressource de sortie
 * et ses contrôles de références. Les écritures sont journalisées dans l'audit.
 */
abstract class ScopedCrudController
{
    use ExportsList;

    protected const EXPORT_LIMIT = 5000;

    private const EXPORT_LABELS = [
        'id' => 'Identifiant', 'parent_id' => 'Parent', 'type' => 'Type', 'name' => 'Nom', 'label' => 'Libellé',
        'starts_on' => 'Début', 'ends_on' => 'Fin', 'status' => 'Statut', 'closed_at' => 'Clôturée le',
        'academic_year_id' => 'Année académique', 'kind' => 'Nature', 'ordinal' => 'Ordre', 'org_unit_id' => 'Unité',
        'structure_id' => 'Structure', 'capacity' => 'Capacité', 'equipment' => 'Équipements', 'reference' => 'Référence',
        'authority' => 'Autorité', 'valid_from' => 'Valide du', 'valid_to' => "Valide jusqu'au",
        'created_at' => 'Créé le', 'updated_at' => 'Modifié le', 'modules_enabled' => 'Modules activés',
    ];

    public function __construct(
        protected readonly JsonResponder $responder,
        protected readonly RequestContext $context,
        protected readonly Repositories $repositories,
        protected readonly OrganisationPolicy $policy,
        protected readonly OrgClock $clock,
    ) {
    }

    abstract protected function table(): string;

    /** Nom court de l'objet dans le journal d'audit (ex. « room »). */
    abstract protected function objectType(): string;

    abstract protected function validator(): ValidatedRequest;

    abstract protected function resource(): Resource;

    /** @return list<string> */
    protected function filters(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function sorts(): array
    {
        return [];
    }

    /** @return list<string> champs qu'on ne peut plus changer après la création */
    protected function immutable(): array
    {
        return [];
    }

    /**
     * Contrôles de références et valeurs par défaut. Reçoit les changements validés, la ligne telle qu'elle serait après
     * modification, la ligne actuelle (nulle à la création) ; renvoie les colonnes à écrire.
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $merged
     * @param array<string, mixed>|null $existing
     * @return array<string, scalar|null>
     */
    protected function check(array $changes, array $merged, ?array $existing): array
    {
        return $changes;
    }

    /** @param array<string, mixed> $existing */
    protected function guardUpdate(array $existing): void
    {
    }

    protected function repository(): ResourceRepository
    {
        return $this->repositories->request($this->table());
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, false);
        $query = ListQuery::fromRequest($request, $this->filters(), $this->sorts());
        [$rows, $next] = $this->repository()->page($query);

        $links = ['next' => null];
        if ($next !== null) {
            $params = $request->getQueryParams();
            $params['cursor'] = $next;
            $links['next'] = $request->getUri()->getPath() . '?' . http_build_query($params);
        }

        return $this->responder->json([
            'data' => $this->resource()->collection($rows),
            'links' => $links,
            'meta' => ['next_cursor' => $next],
        ]);
    }

    /** Export CSV / Excel de la liste (mêmes filtres, tris et droits que la liste, 5 000 lignes au plus). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, false);
        $resource = $this->resource();
        $fields = array_values(array_filter(array_keys($resource->toArray([])), static fn (string $field): bool => $field !== 'establishment_id'));
        $rows = [];
        $cursor = null;
        do {
            $params = $request->getQueryParams();
            $params['per_page'] = (string) ListQuery::MAX_PER_PAGE;
            unset($params['cursor']);
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }
            $query = ListQuery::fromRequest($request->withQueryParams($params), $this->filters(), $this->sorts());
            [$chunk, $cursor] = $this->repository()->page($query);
            array_push($rows, ...$chunk);
        } while ($cursor !== null && count($rows) < self::EXPORT_LIMIT);

        $labels = self::EXPORT_LABELS;

        return $this->csvResponse(
            new Psr17Factory(),
            new Psr17Factory(),
            $this->exportName() . '.csv',
            array_map(static fn (string $field): string => $labels[$field] ?? $field, $fields),
            array_map(static function (array $row) use ($resource, $fields): array {
                $r = $resource->toArray($row);

                return array_map(static fn (string $field): mixed => is_array($r[$field]) ? implode(', ', array_map('strval', array_filter($r[$field], 'is_scalar'))) : $r[$field], $fields);
            }, array_slice($rows, 0, self::EXPORT_LIMIT)),
            $request,
        );
    }

    /** Nom de fichier d'export (sans extension). */
    protected function exportName(): string
    {
        return str_replace('_', '-', $this->table());
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, false);

        return $this->responder->json(['data' => $this->resource()->toArray($this->repository()->findOrFail($this->id($request)))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, true);
        $data = $this->validator()->fromHttp($request);
        $columns = $this->check($data, $data, null);

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->guarded(fn () => $this->repository()->insert($columns + ['id' => $id, 'created_at' => $now, 'updated_at' => $now]));

        $row = $this->repository()->findOrFail($id);
        $this->context->audit($request, $this->objectType() . '.created', $this->objectType(), $id, null, $this->resource()->toArray($row));

        return $this->responder->json(['data' => $this->resource()->toArray($row)], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, true);
        $id = $this->id($request);
        $existing = $this->repository()->findOrFail($id);
        $this->guardUpdate($existing);

        $changes = $this->validator()->fromHttp($request, true);
        $errors = [];
        foreach ($this->immutable() as $field) {
            if (array_key_exists($field, $changes)) {
                if ($changes[$field] !== $existing[$field]) {
                    $errors[$field] = ['Ce champ ne peut plus être modifié après la création.'];
                }
                unset($changes[$field]);
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $columns = $this->check($changes, $changes + $existing, $existing);
        if ($columns !== []) {
            $this->guarded(fn () => $this->repository()->update($id, $columns + ['updated_at' => $this->clock->now()]));
        }

        $row = $this->repository()->findOrFail($id);
        $this->context->audit(
            $request,
            $this->objectType() . '.updated',
            $this->objectType(),
            $id,
            $this->resource()->toArray($existing),
            $this->resource()->toArray($row),
        );

        return $this->responder->json(['data' => $this->resource()->toArray($row)]);
    }

    protected function id(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute('id');
    }

    /** Vérifie l'établissement actif dans le client, puis la permission. */
    protected function authorize(ServerRequestInterface $request, bool $write): void
    {
        $principal = $this->context->principal($request);
        $this->context->activeEstablishment();
        $establishmentId = $this->context->establishmentId();
        $write
            ? $this->policy->authorizeManage($principal, $establishmentId)
            : $this->policy->authorizeRead($principal, $establishmentId);
    }

    /** @param callable(): mixed $write */
    protected function guarded(callable $write): void
    {
        try {
            $write();
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw HttpException::conflict('Un enregistrement identique existe déjà dans cet établissement.');
            }
            throw $e;
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @throws HttpException
     */
    protected function fail(array $errors): never
    {
        throw HttpException::validation($errors);
    }
}
