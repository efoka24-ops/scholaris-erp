<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Apee\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Apee\Application\ApeeService;
use Scholaris\Modules\Apps\PublicSchools\Apee\Application\SupplyService;
use Scholaris\Modules\Apps\PublicSchools\Apee\Infrastructure\PdoApee;
use Scholaris\Modules\Apps\PublicSchools\Support\Perimeter;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;

/**
 * APEE (API-14, DOC-24, DOC-25, DOC-27). Droits : lecture des comptes `public_schools.apee.read` (bureau, directeur, conseil d'école,
 * inspection) ; écriture `public_schools.apee.write` (bureau seul : RG-APE-4) ; liste nominative des non-contributeurs et des exonérés
 * `public_schools.apee.non_payers.read` (bureau seul : RG-APE-2). Paquet minimum et dons : le directeur (`public_schools.school.write`).
 */
final class ApeeController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ScopedByTerritory $scope,
        private readonly ApeeService $service,
        private readonly SupplyService $supplies,
        private readonly PdoApee $repository,
    ) {
    }

    public function years(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->reader($request);

        return $this->responder->json(RequestInput::page(
            $request,
            fn (?string $after, int $limit): array => $this->repository->years($principal->tenantId, $establishmentId, $after, $limit),
            static fn (array $row): array => ApeeService::yearResource($row),
        ));
    }

    public function vote(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->writer($request, 'pub_apee_year');

        return $this->responder->json(['data' => $this->service->vote($principal, $establishmentId, RequestInput::body($request))], 201);
    }

    public function entries(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->reader($request);
        $yearId = RequestInput::attribute($request, 'id');
        $this->repository->year($principal->tenantId, $establishmentId, $yearId) ?? throw HttpException::notFound();
        $direction = RequestInput::query($request, 'direction');
        $full = $this->canSeeNames($principal, $establishmentId);

        return $this->responder->json(RequestInput::page(
            $request,
            fn (?string $after, int $limit): array => $this->repository->entries($principal->tenantId, $establishmentId, $yearId, $direction, $after, $limit),
            static function (array $row) use ($full): array {
                $resource = ApeeService::entryResource($row);
                if (!$full) {
                    // RG-APE-2 : hors du bureau, aucune famille n'est nommée (le directeur voit les comptes, pas qui a payé).
                    $resource['family_ref'] = null;
                }

                return $resource;
            },
        ));
    }

    /** API-14 : encaissement ou dépense de l'APEE. */
    public function record(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->writer($request, 'pub_apee_entry');
        $result = $this->service->record($principal, $establishmentId, RequestInput::body($request));

        return $this->responder->json(['data' => $result['entry'] + ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function attachProof(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->writer($request, 'pub_apee_entry');
        $body = RequestInput::body($request);
        $proof = $body['proof_ref'] ?? null;
        if (!is_string($proof) || trim($proof) === '' || mb_strlen($proof) > 255) {
            throw HttpException::validation(['proof_ref' => ['Référence de la pièce justificative requise (255 caractères au plus).']]);
        }

        return $this->responder->json(['data' => $this->service->attachProof($principal, $establishmentId, RequestInput::attribute($request, 'id'), trim($proof))]);
    }

    public function statement(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->reader($request);

        return $this->responder->json(['data' => $this->service->statement($principal->tenantId, $establishmentId, RequestInput::attribute($request, 'id'))]);
    }

    public function issueStatement(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->writer($request, 'pub_apee_year');
        $locale = RequestInput::body($request)['locale'] ?? 'fr';

        return $this->responder->json(['data' => $this->service->issueStatement($principal, $establishmentId, RequestInput::attribute($request, 'id'), is_string($locale) ? $locale : 'fr')], 201);
    }

    /** RG-APE-2 : liste nominative des familles non contributrices, bureau de l'APEE seulement (403 directeur et enseignant). */
    public function nonContributors(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $this->nonPayers($principal, $establishmentId);
        $rows = array_map(static fn (array $row): array => ['id' => $row['person_id']] + $row, $this->service->nonContributors($principal->tenantId, $establishmentId, RequestInput::attribute($request, 'id')));

        return $this->responder->json(RequestInput::page(
            $request,
            static fn (?string $after, int $limit): array => array_slice(array_values(array_filter($rows, static fn (array $r): bool => $after === null || strcmp((string) $r['id'], $after) > 0)), 0, $limit),
            static fn (array $row): array => $row,
        ));
    }

    public function exemptions(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $this->nonPayers($principal, $establishmentId);
        $yearId = RequestInput::attribute($request, 'id');
        $this->repository->year($principal->tenantId, $establishmentId, $yearId) ?? throw HttpException::notFound();

        return $this->responder->json(['data' => array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'family_ref' => (string) $row['family_ref'], 'reason_code' => (string) $row['reason_code'], 'decided_on' => (string) $row['decided_on']], $this->repository->exemptions($principal->tenantId, $establishmentId, $yearId))]);
    }

    public function exempt(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->writer($request, 'pub_apee_exemption');

        return $this->responder->json(['data' => $this->service->exempt($principal, $establishmentId, RequestInput::attribute($request, 'id'), RequestInput::body($request))], 201);
    }

    /** Recherche d'élève minimale (nom) pour établir un reçu : bureau seulement. */
    public function families(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->writer($request, 'pub_apee_family');
        $term = RequestInput::query($request, 'search');
        if ($term === null || mb_strlen($term) < 2) {
            throw HttpException::validation(['search' => ['Terme de recherche de 2 caractères au moins.']]);
        }

        return $this->responder->json(['data' => $this->service->findFamilies($principal->tenantId, $establishmentId, $term)]);
    }

    public function supplies(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.school.read', $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }

        return $this->responder->json(RequestInput::page(
            $request,
            fn (?string $after, int $limit): array => $this->repository->supplies($principal->tenantId, $establishmentId, $after, $limit),
            static fn (array $row): array => SupplyService::resource($row),
        ));
    }

    public function receiveSupply(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->director($request);

        return $this->responder->json(['data' => $this->supplies->receive($principal, $establishmentId, RequestInput::body($request))], 201);
    }

    public function supply(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        if ($this->scope->perimeter($principal, 'public_schools.school.read', $establishmentId)->isEmpty()) {
            throw HttpException::notFound();
        }

        return $this->responder->json(['data' => $this->supplies->show($principal->tenantId, $establishmentId, RequestInput::attribute($request, 'id'))]);
    }

    public function supplySlip(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->director($request);
        $locale = RequestInput::body($request)['locale'] ?? 'fr';

        return $this->responder->json(['data' => $this->supplies->issueSlip($principal, $establishmentId, RequestInput::attribute($request, 'id'), is_string($locale) ? $locale : 'fr')], 201);
    }

    /** @return array{0: Principal, 1: string} */
    private function reader(ServerRequestInterface $request): array
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.apee.read', $establishmentId);
        if ($perimeter->isEmpty()) {
            throw HttpException::notFound();
        }

        return [$principal, $establishmentId];
    }

    /** @return array{0: Principal, 1: string} */
    private function writer(ServerRequestInterface $request, string $objectType): array
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.apee.write', $establishmentId);
        if (!$perimeter->wholeSchool) {
            $this->scope->assertWritable($principal, Perimeter::none($principal->tenantId, $establishmentId, 'public_schools.apee.write'), null, $objectType, $establishmentId);
        }

        return [$principal, $establishmentId];
    }

    /** @return array{0: Principal, 1: string} */
    private function director(ServerRequestInterface $request): array
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        if (!$this->scope->perimeter($principal, 'public_schools.school.write', $establishmentId)->wholeSchool) {
            $this->scope->assertWritable($principal, Perimeter::none($principal->tenantId, $establishmentId, 'public_schools.school.write'), null, 'pub_supply_receipt', $establishmentId);
        }

        return [$principal, $establishmentId];
    }

    private function nonPayers(Principal $principal, string $establishmentId): void
    {
        if (!$this->canSeeNames($principal, $establishmentId)) {
            throw HttpException::forbidden('La liste nominative des familles non contributrices est réservée au trésorier et au président de l\'APEE (RG-APE-2).');
        }
    }

    private function canSeeNames(Principal $principal, string $establishmentId): bool
    {
        return $this->scope->perimeter($principal, 'public_schools.apee.non_payers.read', $establishmentId)->wholeSchool;
    }
}
