<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Organisation\Application\SettingsService;
use Scholaris\Modules\Socle\Organisation\Domain\SettingEntry;
use Scholaris\Modules\Socle\Organisation\Domain\SettingScope;
use Scholaris\Modules\Socle\Organisation\Http\Policies\SettingsPolicy;
use Scholaris\Modules\Socle\Organisation\Http\RequestContext;
use Scholaris\Modules\Socle\Organisation\Http\Requests\PutSettingRequest;

/**
 * Paramètres à quatre niveaux (plateforme, client, établissement, unité interne). Le client et l'établissement viennent toujours du contexte authentifié
 * (jeton + X-Establishment-Id), jamais du corps de la requête.
 */
final class SettingsController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly SettingsService $settings,
        private readonly SettingsPolicy $policy,
    ) {
    }

    /** GET /settings?filter[scope]=establishment|tenant|platform&filter[key]=... : valeurs définies à un niveau. */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $this->context->activeEstablishment();
        $this->policy->authorizeRead($principal, $this->context->establishmentId());
        $query = ListQuery::fromRequest($request, ['scope', 'key', 'org_unit_id']);

        $scope = SettingScope::tryFrom($query->filters['scope'] ?? SettingScope::Establishment->value);
        if ($scope === null) {
            throw HttpException::validation(['filter[scope]' => ['Niveau inconnu : platform, tenant, establishment ou org_unit.']]);
        }
        $establishmentId = $scope === SettingScope::Establishment ? $this->context->establishmentId() : null;
        $unitId = $query->filters['org_unit_id'] ?? null;
        if ($scope === SettingScope::OrgUnit && $unitId === null) {
            throw HttpException::validation(['filter[org_unit_id]' => ['Indiquez l\'unité (filter[org_unit_id]) pour le niveau org_unit.']]);
        }

        if ($scope === SettingScope::OrgUnit) {
            if (isset($query->filters['key'])) {
                $entry = $this->settings->getForOrgUnit($this->context->tenantId(), $this->context->establishmentId(), (string) $unitId, $query->filters['key']);
                $entries = $entry === null ? [] : [$entry];
            } else {
                $entries = $this->settings->listForOrgUnit($this->context->tenantId(), $this->context->establishmentId(), (string) $unitId);
            }
        } elseif (isset($query->filters['key'])) {
            $entry = $this->settings->get($scope, $this->context->tenantId(), $establishmentId, $query->filters['key']);
            $entries = $entry === null ? [] : [$entry];
        } else {
            $entries = $this->settings->list($scope, $this->context->tenantId(), $establishmentId);
        }

        // Pagination par curseur sur la clé (les entrées sont triées par clé).
        $after = ListQuery::decodeCursor($query->cursor);
        if ($after !== null) {
            $entries = array_values(array_filter($entries, static fn (SettingEntry $entry): bool => strcmp($entry->key, $after) > 0));
        }
        $page = array_slice($entries, 0, $query->perPage);
        $next = count($entries) > $query->perPage && $page !== [] ? ListQuery::encodeCursor($page[array_key_last($page)]->key) : null;

        return $this->responder->json([
            'data' => array_map(static fn (SettingEntry $entry): array => $entry->toArray(), $page),
            'links' => ['next' => $next === null ? null : $request->getUri()->getPath() . '?' . http_build_query(['cursor' => $next] + $request->getQueryParams())],
            'meta' => ['next_cursor' => $next],
        ]);
    }

    /** GET /settings/effective?filter[key]=... : valeur effective pour l'établissement actif, avec son origine et les niveaux masqués. */
    public function effective(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $this->context->activeEstablishment();
        $this->policy->authorizeRead($principal, $this->context->establishmentId());
        $query = ListQuery::fromRequest($request, ['key', 'org_unit_id']);
        $unitId = $query->filters['org_unit_id'] ?? null;

        if (isset($query->filters['key'])) {
            $effective = $this->settings->effective($this->context->tenantId(), $this->context->establishmentId(), $query->filters['key'], $unitId);
            if ($effective === null) {
                throw HttpException::notFound('Aucun niveau ne définit ce paramètre.');
            }

            return $this->responder->json(['data' => $effective->toArray()]);
        }

        $all = $this->settings->effectiveAll($this->context->tenantId(), $this->context->establishmentId(), $unitId);

        return $this->responder->json(['data' => array_map(static fn ($setting): array => $setting->toArray(), $all)]);
    }

    /** PUT /settings {scope, key, value, org_unit_id?} (org_unit_id obligatoire pour scope=org_unit) : définit la valeur au niveau demandé (trace dans le journal d'audit). */
    public function put(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->context->principal($request);
        $this->context->activeEstablishment();
        // A1 : le droit de modifier (quel que soit le niveau) est contrôlé avant la validation du corps.
        $this->policy->authorizeAnyWrite($principal, $this->context->establishmentId());
        $data = (new PutSettingRequest())->fromHttp($request);
        $scope = SettingScope::from((string) $data['scope']);
        $this->policy->authorizeWrite($principal, $scope, $this->context->establishmentId());

        $unitId = isset($data['org_unit_id']) ? (string) $data['org_unit_id'] : null;
        $entry = $scope === SettingScope::OrgUnit
            ? $this->settings->setForOrgUnit(
                $this->context->tenantId(),
                $this->context->establishmentId(),
                (string) $unitId,
                (string) $data['key'],
                $data['value'],
                $principal->userId,
                $this->context->ip($request),
                $this->context->device($request),
            )
            : $this->settings->set(
                $scope,
                $this->context->tenantId(),
                $scope === SettingScope::Establishment ? $this->context->establishmentId() : null,
                (string) $data['key'],
                $data['value'],
                $principal->userId,
                $this->context->ip($request),
                $this->context->device($request),
            );
        $effective = $this->settings->effective($this->context->tenantId(), $this->context->establishmentId(), $entry->key, $unitId);

        return $this->responder->json(['data' => $entry->toArray(), 'effective' => $effective?->toArray()]);
    }
}
