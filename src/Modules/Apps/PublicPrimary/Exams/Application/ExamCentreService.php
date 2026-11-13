<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Domain\VacationStatement;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Infrastructure\PdoExams;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Centres, sous-centres, jurys et vacations (EX-EXA-03 « délégation : consolidation, centres » ; décret n° 2010/1738/PM) :
 *  - la délégation départementale (ou la direction des examens) crée les centres et sous-centres de son territoire et y affecte les
 *    candidats des listes figées, avec un numéro de candidat (code du centre + rang) ;
 *  - elle enregistre les intervenants de chaque centre (fonction du décret, demi-journées, copies corrigées) ;
 *  - l'état des vacations applique les taux du paramètre `public_primary.exams.vacation_rates` (montants officiels à vérifier, jamais
 *    en dur) et l'indemnité des directeurs d'école par candidat présenté.
 */
final class ExamCentreService
{
    public function __construct(
        private readonly PdoExams $repository,
        private readonly ExamAccess $access,
        private readonly ExamSupport $support,
        private readonly Rules $rules,
        private readonly PrimaryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return list<array<string, mixed>> centres et sous-centres du périmètre (territoire affecté et descendants ; tous au niveau national) */
    public function centres(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $reach = $this->access->reader($principal, $establishmentId, ExamAccess::CONSOLIDATE, ExamAccess::VALIDATE, ExamAccess::IMPORT);
        $this->support->session($principal->tenantId, $sessionId);
        $centres = $this->repository->centres($principal->tenantId, $sessionId, $this->access->territoryIdsOf($principal, $reach));

        return array_map(static fn (array $row): array => self::resource($row), $centres);
    }

    /**
     * @param array<string, mixed> $input territory_id, code, label, parent_centre_id?, host_establishment_id?, capacity?
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, string $sessionId, array $input): array
    {
        $territoryId = $this->access->territoryInput($principal, $establishmentId, $input, ExamAccess::CONSOLIDATE);
        $scope = $this->access->territory($principal, $establishmentId, $territoryId, ExamAccess::CONSOLIDATE);
        $tenantId = $principal->tenantId;
        $this->support->session($tenantId, $sessionId);
        $errors = [];
        $code = Input::text($input['code'] ?? null, 40, 'code', $errors, true);
        $label = Input::text($input['label'] ?? null, 160, 'label', $errors, true);
        $parentId = Input::text($input['parent_centre_id'] ?? null, 26, 'parent_centre_id', $errors);
        $host = Input::text($input['host_establishment_id'] ?? null, 40, 'host_establishment_id', $errors);
        $capacity = $input['capacity'] ?? null;
        if ($capacity !== null && (!is_int($capacity) || $capacity < 1)) {
            $errors['capacity'][] = 'Entier positif attendu.';
        }
        if ($host !== null && !in_array($host, $scope['establishments'], true)) {
            $errors['host_establishment_id'][] = 'École d\'accueil hors du territoire.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($parentId !== null) {
            $parent = $this->repository->centre($tenantId, $sessionId, $parentId);
            if ($parent === null || $parent['parent_centre_id'] !== null) {
                throw HttpException::validation(['parent_centre_id' => ['Centre parent inconnu (un sous-centre dépend d\'un centre).']]);
            }
        }
        foreach ($this->repository->centres($tenantId, $sessionId, null) as $existing) {
            if ($existing['code'] === $code) {
                throw HttpException::conflict('Code de centre déjà utilisé dans cette session.');
            }
        }
        $id = $this->repository->insert('pp_exam_centres', [
            'tenant_id' => $tenantId, 'session_id' => $sessionId, 'territory_id' => $territoryId, 'parent_centre_id' => $parentId, 'code' => $code, 'label' => $label,
            'host_establishment_id' => $host, 'capacity' => is_int($capacity) ? $capacity : null, 'created_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.centre_created', 'pp_exam_centre', $id, null, ['code' => $code, 'territory_id' => $territoryId]);

        return self::resource($this->repository->centre($tenantId, $sessionId, $id) ?? throw HttpException::notFound());
    }

    /**
     * Affecte au centre (ou au sous-centre) les candidats VALIDÉS des listes figées des écoles données, et les numérote
     * (code du centre, tiret, rang sur quatre chiffres) s'ils n'ont pas de numéro.
     *
     * @param array<string, mixed> $input establishment_ids
     * @return array<string, mixed>
     */
    public function assign(Principal $principal, string $establishmentId, string $sessionId, string $centreId, array $input): array
    {
        $this->access->reader($principal, $establishmentId, ExamAccess::CONSOLIDATE);
        $tenantId = $principal->tenantId;
        $centre = $this->repository->centre($tenantId, $sessionId, $centreId) ?? throw HttpException::notFound('Centre introuvable.');
        $scope = $this->access->territory($principal, $establishmentId, (string) $centre['territory_id'], ExamAccess::CONSOLIDATE);
        $schools = $input['establishment_ids'] ?? null;
        if (!is_array($schools) || $schools === [] || array_filter($schools, static fn ($id): bool => !is_string($id) || !in_array($id, $scope['establishments'], true)) !== []) {
            throw HttpException::validation(['establishment_ids' => ['Écoles du territoire du centre attendues.']]);
        }
        $main = $centre['parent_centre_id'] === null ? $centre : ($this->repository->centre($tenantId, $sessionId, (string) $centre['parent_centre_id']) ?? $centre);
        $taken = 0;
        foreach ($this->repository->candidatesIn($tenantId, $sessionId, $scope['establishments']) as $row) {
            if ($row['candidate_number'] !== null && str_starts_with((string) $row['candidate_number'], (string) $main['code'] . '-')) {
                ++$taken;
            }
        }
        $assigned = 0;
        $skipped = 0;
        $now = $this->clock->now();
        foreach ($this->repository->candidatesIn($tenantId, $sessionId, array_values(array_map('strval', $schools))) as $row) {
            $list = $this->repository->list($tenantId, (string) $row['list_id']);
            if ($row['candidate_state'] !== 'validated' || $list === null || $list['frozen_at'] === null) {
                ++$skipped;
                continue;
            }
            $values = ['centre_id' => (string) $main['id'], 'sub_centre_id' => $centre['parent_centre_id'] === null ? null : $centreId, 'updated_at' => $now];
            if ($row['candidate_number'] === null) {
                ++$taken;
                $values['candidate_number'] = (string) $main['code'] . '-' . str_pad((string) $taken, 4, '0', STR_PAD_LEFT);
            }
            $this->repository->update('pp_exam_candidates', $tenantId, (string) $row['id'], $values);
            ++$assigned;
        }
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.candidates_assigned', 'pp_exam_centre', $centreId, null, ['schools' => $schools, 'assigned' => $assigned]);

        return ['assigned' => $assigned, 'skipped' => $skipped];
    }

    /** @return list<array<string, mixed>> */
    public function officials(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $reach = $this->access->reader($principal, $establishmentId, ExamAccess::CONSOLIDATE);
        $centres = $this->repository->centres($principal->tenantId, $sessionId, $this->access->territoryIdsOf($principal, $reach));

        return array_map(static fn (array $row): array => self::officialResource($row), $this->repository->officials($principal->tenantId, $sessionId, array_map(static fn (array $c): string => (string) $c['id'], $centres)));
    }

    /**
     * Intervenant d'un centre (jury, secrétariat, correction…) : fonction du décret (paramètre `public_primary.exams.functions`).
     *
     * @param array<string, mixed> $input centre_id, full_name, staff_ref?, function_code, half_days, copies_marked
     * @return array<string, mixed>
     */
    public function addOfficial(Principal $principal, string $establishmentId, string $sessionId, array $input): array
    {
        $this->access->reader($principal, $establishmentId, ExamAccess::CONSOLIDATE);
        $tenantId = $principal->tenantId;
        $errors = [];
        $centreId = Input::text($input['centre_id'] ?? null, 26, 'centre_id', $errors, true);
        $name = Input::text($input['full_name'] ?? null, 160, 'full_name', $errors, true);
        $staffRef = Input::text($input['staff_ref'] ?? null, 60, 'staff_ref', $errors);
        $function = $input['function_code'] ?? null;
        $functions = $this->rules->array($tenantId, $establishmentId, 'public_primary.exams.functions');
        if (!is_string($function) || !isset($functions[$function])) {
            $errors['function_code'][] = 'Fonction attendue : ' . implode(', ', array_keys($functions)) . '.';
        }
        foreach (['half_days', 'copies_marked'] as $field) {
            if (!is_int($input[$field] ?? 0) || (int) ($input[$field] ?? 0) < 0) {
                $errors[$field][] = 'Entier positif ou nul attendu.';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $centre = $this->repository->centre($tenantId, $sessionId, (string) $centreId) ?? throw HttpException::notFound('Centre introuvable.');
        $this->access->territory($principal, $establishmentId, (string) $centre['territory_id'], ExamAccess::CONSOLIDATE);
        $id = $this->repository->insert('pp_exam_officials', [
            'tenant_id' => $tenantId, 'session_id' => $sessionId, 'centre_id' => (string) $centreId, 'full_name' => $name, 'staff_ref' => $staffRef,
            'function_code' => (string) $function, 'half_days' => (int) ($input['half_days'] ?? 0), 'copies_marked' => (int) ($input['copies_marked'] ?? 0),
            'created_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.official_added', 'pp_exam_official', $id, null, ['function_code' => $function]);
        foreach ($this->repository->officials($tenantId, $sessionId, [(string) $centreId]) as $row) {
            if ($row['id'] === $id) {
                return self::officialResource($row);
            }
        }

        throw HttpException::notFound();
    }

    /**
     * État des vacations d'un territoire : intervenants des centres du territoire et indemnité des directeurs des écoles dont des
     * candidats validés y composent.
     *
     * @return array<string, mixed>
     */
    public function vacations(Principal $principal, string $establishmentId, string $sessionId, string $territoryId): array
    {
        $scope = $this->access->territory($principal, $establishmentId, $territoryId, ExamAccess::CONSOLIDATE);
        $tenantId = $principal->tenantId;
        $this->support->session($tenantId, $sessionId);
        $centres = $this->repository->centres($tenantId, $sessionId, $this->access->descendantIds($tenantId, $territoryId));
        $centreIds = array_map(static fn (array $c): string => (string) $c['id'], $centres);
        $officials = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'], 'centre_id' => (string) $row['centre_id'], 'full_name' => (string) $row['full_name'], 'function_code' => (string) $row['function_code'],
            'half_days' => (int) $row['half_days'], 'copies_marked' => (int) $row['copies_marked'],
        ], $this->repository->officials($tenantId, $sessionId, $centreIds));
        $bySchool = [];
        foreach ($this->repository->candidatesIn($tenantId, $sessionId, $scope['establishments']) as $row) {
            if ($row['candidate_state'] === 'validated' && $row['centre_id'] !== null && in_array((string) $row['centre_id'], $centreIds, true)) {
                $bySchool[(string) $row['establishment_id']] = ($bySchool[(string) $row['establishment_id']] ?? 0) + 1;
            }
        }
        $rates = $this->rules->array($tenantId, $establishmentId, 'public_primary.exams.vacation_rates');

        return ['territory_id' => $territoryId, 'rates' => $rates, 'rates_status' => Rules::DEFAULTS['public_primary.exams.vacation_rates']['status'], 'source' => Rules::DEFAULTS['public_primary.exams.vacation_rates']['source']]
            + VacationStatement::compute($officials, $rates, $bySchool);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'session_id' => (string) $row['session_id'], 'territory_id' => (string) $row['territory_id'], 'parent_centre_id' => $row['parent_centre_id'],
            'code' => (string) $row['code'], 'label' => (string) $row['label'], 'host_establishment_id' => $row['host_establishment_id'],
            'capacity' => $row['capacity'] === null ? null : (int) $row['capacity'], 'kind' => $row['parent_centre_id'] === null ? 'centre' : 'sub_centre',
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function officialResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'centre_id' => (string) $row['centre_id'], 'full_name' => (string) $row['full_name'], 'staff_ref' => $row['staff_ref'],
            'function_code' => (string) $row['function_code'], 'half_days' => (int) $row['half_days'], 'copies_marked' => (int) $row['copies_marked'],
        ];
    }
}
