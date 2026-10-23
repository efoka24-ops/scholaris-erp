<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use DateTimeImmutable;
use JsonException;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Modules\Socle\Organisation\Application\EstablishmentTerritoryService;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryInfo;
use Scholaris\Modules\Socle\Organisation\Domain\Instant;
use Scholaris\Modules\Socle\Organisation\Http\Policies\TerritoryPolicy;
use Scholaris\Modules\Socle\Organisation\Http\RequestContext;

/** API territoriale du noyau Organisation : lecture et versions synthétiques, jamais de seed officiel. */
final class TerritoryController
{
    private const TYPES = ['region', 'departement', 'arrondissement'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly TenantContext $tenant,
        private readonly ConnectionFactory $connections,
        private readonly TerritoryDirectory $territories,
        private readonly TerritoryPolicy $policy,
        private readonly EstablishmentTerritoryService $assignments,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $at = $this->at($request);
        $query = ListQuery::fromRequest($request);
        $after = ListQuery::decodeCursor($query->cursor) ?? '';
        // Pagination par curseur sur l'identifiant stable du territoire (convention des listes du socle).
        $statement = $this->connections->pdo()->prepare(
            'SELECT DISTINCT territory_id FROM organisation_territory_versions '
            . 'WHERE tenant_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to > ?) AND territory_id > ? '
            . 'ORDER BY territory_id LIMIT ' . ($query->perPage + 1),
        );
        $statement->execute([$this->tenant->tenantId(), $at, $at, $after]);
        $ids = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $next = null;
        if (count($ids) > $query->perPage) {
            $ids = array_slice($ids, 0, $query->perPage);
            $next = ListQuery::encodeCursor((string) end($ids));
        }
        $items = [];
        foreach ($ids as $territoryId) {
            $info = $this->territories->find($this->tenant->tenantId(), $territoryId, $at);
            if ($info !== null) {
                $items[] = self::resource($info);
            }
        }

        return $this->responder->json(['data' => $items, 'meta' => ['next_cursor' => $next, 'at' => $at]]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $info = $this->territories->find($this->tenant->tenantId(), (string) $request->getAttribute('id'), $this->at($request));
        if ($info === null) {
            throw HttpException::notFound('Territoire introuvable.');
        }

        return $this->responder->json(['data' => self::resource($info)]);
    }

    public function lineage(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $items = $this->territories->lineage($this->tenant->tenantId(), (string) $request->getAttribute('id'), $this->at($request));
        if ($items === []) {
            throw HttpException::notFound('Territoire introuvable.');
        }

        return $this->responder->json(['data' => array_map(self::resource(...), $items)]);
    }

    public function descendants(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeRead($request);
        $items = $this->territories->descendantsOf($this->tenant->tenantId(), (string) $request->getAttribute('id'), $this->at($request));
        if ($items === []) {
            throw HttpException::notFound('Territoire introuvable.');
        }

        return $this->responder->json(['data' => array_map(self::resource(...), $items)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeManage($request);
        [$type, $code, $labelFr, $labelEn, $parentId, $validFrom] = $this->validatedTerritory($this->body($request));
        $this->assertParent($parentId, $validFrom, null);

        $id = (string) new Ulid();
        $now = (new DateTimeImmutable('now'))->format(DATE_ATOM);
        $this->connections->pdo()->prepare(
            'INSERT INTO organisation_territory_versions '
            . '(id, tenant_id, territory_id, version, territory_type, code, label_fr, label_en, parent_id, valid_from, valid_to, created_at) '
            . 'VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, NULL, ?)',
        )->execute([(string) new Ulid(), $this->tenant->tenantId(), $id, $type, trim($code), trim($labelFr), trim($labelEn), $parentId, $validFrom, $now]);
        $info = $this->territories->find($this->tenant->tenantId(), $id, (string) $validFrom);
        if ($info === null) {
            throw new \LogicException('Le territoire créé est introuvable après insertion.');
        }
        $this->context->audit($request, 'organisation.territory.created', 'territory', $id, null, self::resource($info));

        return $this->responder->json(['data' => self::resource($info)], 201);
    }

    public function version(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizeManage($request);
        $territoryId = (string) $request->getAttribute('id');
        $tenantId = $this->tenant->tenantId();
        $statement = $this->connections->pdo()->prepare(
            'SELECT * FROM organisation_territory_versions WHERE tenant_id = ? AND territory_id = ? ORDER BY version DESC LIMIT 1',
        );
        $statement->execute([$tenantId, $territoryId]);
        $current = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            throw HttpException::notFound('Territoire introuvable.');
        }
        [$type, $code, $labelFr, $labelEn, $parentId, $validFrom] = $this->validatedTerritory($this->body($request));
        $newStart = new DateTimeImmutable($validFrom);
        $oldStart = new DateTimeImmutable((string) $current['valid_from']);
        if ($newStart <= $oldStart) {
            throw HttpException::validation(['valid_from' => ['La nouvelle version doit commencer après la version précédente.']]);
        }
        $this->assertParent($parentId, $validFrom, $territoryId);

        $pdo = $this->connections->pdo();
        $version = (int) $current['version'] + 1;
        $now = (new DateTimeImmutable('now'))->format(DATE_ATOM);
        $pdo->beginTransaction();
        try {
            if ($current['valid_to'] === null) {
                $pdo->prepare('UPDATE organisation_territory_versions SET valid_to = ? WHERE tenant_id = ? AND id = ? AND valid_to IS NULL')
                    ->execute([$validFrom, $tenantId, $current['id']]);
            } elseif (new DateTimeImmutable((string) $current['valid_to']) > $newStart) {
                throw HttpException::conflict('La nouvelle version chevauche une période déjà clôturée.');
            }
            $pdo->prepare(
                'INSERT INTO organisation_territory_versions '
                . '(id, tenant_id, territory_id, version, territory_type, code, label_fr, label_en, parent_id, valid_from, valid_to, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)',
            )->execute([(string) new Ulid(), $tenantId, $territoryId, $version, $type, $code, $labelFr, $labelEn, $parentId, $validFrom, $now]);
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        $info = $this->territories->find($tenantId, $territoryId, $validFrom);
        if ($info === null) {
            throw new \LogicException('La nouvelle version territoriale est introuvable après insertion.');
        }
        $this->context->audit($request, 'organisation.territory.versioned', 'territory', $territoryId, null, self::resource($info));

        return $this->responder->json(['data' => self::resource($info)], 201);
    }

    /** PUT /territories/{id}/establishments : rattachement daté, ancien lien clos (PP1, RG-TER-4). */
    public function assignEstablishments(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->policy->authorizeAssign($principal);
        $data = $this->body($request);
        $errors = [];
        foreach (array_diff(array_keys($data), ['establishment_ids', 'valid_from']) as $unknown) {
            $errors[(string) $unknown][] = 'Champ non autorisé.';
        }
        $ids = $data['establishment_ids'] ?? null;
        $validFrom = $data['valid_from'] ?? null;
        if (!is_array($ids) || $ids === [] || !array_is_list($ids) || count($ids) > 500) {
            $errors['establishment_ids'][] = 'Liste non vide d\'identifiants d\'établissement requise (500 maximum).';
        } else {
            foreach ($ids as $id) {
                if (!is_string($id) || $id === '' || mb_strlen($id) > 40) {
                    $errors['establishment_ids'][] = 'Identifiant d\'établissement invalide.';
                    break;
                }
            }
        }
        if (!is_string($validFrom) || !$this->validDate($validFrom)) {
            $errors['valid_from'][] = 'Date d\'effet ISO 8601 invalide.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $territoryId = (string) $request->getAttribute('id');
        $result = $this->assignments->assign($this->tenant->tenantId(), $territoryId, array_values(array_map('strval', (array) $ids)), Instant::utc((string) $validFrom));
        $this->context->audit($request, 'organisation.territory.establishments_assigned', 'territory', $territoryId, null, $result);

        return $this->responder->json(['data' => $result]);
    }

    /** @param array<string, mixed> $data
     * @return array{string, string, string, string, ?string, string}
     */
    private function validatedTerritory(array $data): array
    {
        $errors = [];
        $type = $data['type'] ?? null;
        $code = $data['code'] ?? null;
        $labelFr = $data['label_fr'] ?? null;
        $labelEn = $data['label_en'] ?? null;
        $parentId = $data['parent_id'] ?? null;
        $validFrom = $data['valid_from'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            $errors['type'][] = 'Type territorial invalide.';
        }
        if (!is_string($code) || trim($code) === '' || mb_strlen($code) > 80) {
            $errors['code'][] = 'Code requis (80 caractères maximum).';
        }
        if (!is_string($labelFr) || trim($labelFr) === '' || mb_strlen($labelFr) > 160) {
            $errors['label_fr'][] = 'Libellé français requis (160 caractères maximum).';
        }
        if (!is_string($labelEn) || trim($labelEn) === '' || mb_strlen($labelEn) > 160) {
            $errors['label_en'][] = 'Libellé anglais requis (160 caractères maximum).';
        }
        if ($parentId !== null && (!is_string($parentId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $parentId) !== 1)) {
            $errors['parent_id'][] = 'Identifiant parent ULID invalide.';
        }
        if (!is_string($validFrom) || !$this->validDate($validFrom)) {
            $errors['valid_from'][] = 'Date de début ISO 8601 invalide.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [$type, trim($code), trim($labelFr), trim($labelEn), $parentId, $validFrom];
    }

    private function assertParent(?string $parentId, string $validFrom, ?string $territoryId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($parentId === $territoryId) {
            throw HttpException::validation(['parent_id' => ['Un territoire ne peut pas être son propre parent.']]);
        }
        $lineage = $this->territories->lineage($this->tenant->tenantId(), $parentId, $validFrom);
        if ($lineage === []) {
            throw HttpException::validation(['parent_id' => ['Territoire parent introuvable dans ce tenant à cette date.']]);
        }
        foreach ($lineage as $ancestor) {
            if ($ancestor->id === $territoryId) {
                throw HttpException::validation(['parent_id' => ['La modification créerait un cycle territorial.']]);
            }
        }
    }

    private function authorizeRead(ServerRequestInterface $request): void
    {
        $this->policy->authorizeRead($this->principal($request), $this->context->establishmentId());
    }

    private function authorizeManage(ServerRequestInterface $request): void
    {
        $this->policy->authorizeManage($this->principal($request), $this->context->establishmentId());
    }

    private function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }

        return $principal;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        try {
            $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw HttpException::badRequest('Le corps doit être un objet JSON valide.');
        }
        if (!is_array($body)) {
            throw HttpException::badRequest('Le corps doit être un objet JSON.');
        }

        return $body;
    }

    private function at(ServerRequestInterface $request): string
    {
        $query = $request->getQueryParams();
        $value = $query['at'] ?? (new DateTimeImmutable('now'))->format(DATE_ATOM);

        return is_string($value) && $this->validDate($value) ? $value : throw HttpException::badRequest('Paramètre at ISO 8601 invalide.');
    }

    private function validDate(string $value): bool
    {
        try {
            new DateTimeImmutable($value);

            return str_contains($value, 'T');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private static function resource(TerritoryInfo $info): array
    {
        return [
            'id' => $info->id,
            'tenant_id' => $info->tenantId,
            'type' => $info->type,
            'code' => $info->code,
            'label_fr' => $info->labelFr,
            'label_en' => $info->labelEn,
            'parent_id' => $info->parentId,
            'valid_from' => $info->validFrom,
            'valid_to' => $info->validTo,
            'version' => $info->version,
        ];
    }
}
