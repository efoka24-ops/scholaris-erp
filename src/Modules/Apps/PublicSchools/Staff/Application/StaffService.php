<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Staff\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Staff\Infrastructure\PdoStaffRecords;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMoveInfo;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMover;

/**
 * Personnel de l'école publique (T639) : fiche de carrière (EX-PER-01), formations (EX-PER-03), mutations à l'intérieur du territoire
 * avec historique (EX-PER-02, PP19) et besoins en enseignants (EX-PER-04).
 *
 * RG-PER-2 : l'inspection ne voit que le statut, le grade, les affectations et les présences ; matricule, numéro de contrat,
 * diplôme, date de prise de service et formations restent dans l'école. RG-PER-1 : les maîtres des parents sont payés par l'APEE
 * (dépenses du sous-module Apee) ; ce sous-module n'a aucune donnée de paie.
 */
final class StaffService
{
    public const STATUSES = ['civil_servant', 'contract', 'parents_teacher', 'volunteer'];
    private const DEFAULT_MOVE_LEVEL = 'departement';

    public function __construct(
        private readonly PdoStaffRecords $records,
        private readonly StaffDirectory $staff,
        private readonly PersonDirectory $people,
        private readonly StaffMover $mover,
        private readonly ClassRoster $classes,
        private readonly CurrentPeriod $periods,
        private readonly TerritoryDirectory $territories,
        private readonly SettingsReader $settings,
        private readonly AuditLog $audit,
        private readonly StaffClock $clock,
    ) {
    }

    /**
     * Personnel enseignant actif de l'école ; l'identifiant de chaque ligne est celui de la personne (curseur).
     *
     * @return list<array<string, mixed>>
     */
    public function listing(string $tenantId, string $establishmentId, ?string $afterId, int $limit, bool $full): array
    {
        $personIds = $this->staff->teacherIdsIn($tenantId, $establishmentId);
        sort($personIds);
        $personIds = array_slice(array_values(array_filter($personIds, static fn (string $id): bool => $afterId === null || strcmp($id, $afterId) > 0)), 0, $limit);
        $infos = [];
        foreach ($personIds as $personId) {
            $info = $this->staff->find($tenantId, $personId);
            if ($info !== null && $info->staffId !== null) {
                $infos[$personId] = $info;
            }
        }
        $profiles = $this->records->profiles($tenantId, array_values(array_map(static fn (StaffInfo $info): string => (string) $info->staffId, $infos)));
        $names = [];
        foreach ($this->people->findMany($tenantId, array_keys($infos)) as $person) {
            $names[$person->id] = $person->fullName();
        }
        $rows = [];
        foreach ($infos as $personId => $info) {
            $rows[] = ['id' => (string) $personId, 'name' => $names[$personId] ?? ''] + $this->resource($info, $profiles[(string) $info->staffId] ?? null, $full);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function profile(string $tenantId, string $establishmentId, string $personId, bool $full): array
    {
        $info = $this->teacher($tenantId, $establishmentId, $personId);

        return ['id' => $personId] + $this->resource($info, $this->records->profile($tenantId, (string) $info->staffId), $full);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveProfile(Principal $principal, string $establishmentId, string $personId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $info = $this->teacher($tenantId, $establishmentId, $personId);
        $errors = [];
        $status = $input['public_status'] ?? null;
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            $errors['public_status'][] = 'Valeur attendue : ' . implode(', ', self::STATUSES) . '.';
            $status = '';
        }
        $values = ['public_status' => $status];
        foreach (['civil_service_number' => 40, 'contract_number' => 40, 'professional_diploma' => 80] as $field => $max) {
            $values[$field] = self::optionalText($input[$field] ?? null, $max, $field, $errors);
        }
        $start = self::optionalText($input['service_start_on'] ?? null, 10, 'service_start_on', $errors);
        if ($start !== null && !self::isDate($start)) {
            $errors['service_start_on'][] = 'Date au format AAAA-MM-JJ attendue.';
        }
        $values['service_start_on'] = $start;
        if ($status === 'civil_servant' && $values['civil_service_number'] === null) {
            $errors['civil_service_number'][] = 'Le matricule de la fonction publique est requis pour un fonctionnaire.';
        }
        if ($status === 'contract' && $values['contract_number'] === null) {
            $errors['contract_number'][] = 'Le numéro de contrat est requis pour un contractuel.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $this->records->saveProfile($tenantId, (string) $info->staffId, $values, $principal->userId, $this->clock->now());
        $this->audit->append($tenantId, $principal->userId, 'public_schools.staff.profile_saved', 'staff_profile', (string) $info->staffId, null, ['public_status' => $status]);

        return $this->profile($tenantId, $establishmentId, $personId, true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addTraining(Principal $principal, string $establishmentId, string $personId, array $input): array
    {
        $info = $this->teacher($principal->tenantId, $establishmentId, $personId);
        $errors = [];
        $title = self::optionalText($input['title'] ?? null, 160, 'title', $errors);
        if ($title === null) {
            $errors['title'][] = 'Intitulé requis.';
        }
        $starts = self::optionalText($input['starts_on'] ?? null, 10, 'starts_on', $errors);
        if ($starts === null || !self::isDate($starts)) {
            $errors['starts_on'][] = 'Date de début au format AAAA-MM-JJ requise.';
        }
        $ends = self::optionalText($input['ends_on'] ?? null, 10, 'ends_on', $errors);
        if ($ends !== null && (!self::isDate($ends) || ($starts !== null && $ends < $starts))) {
            $errors['ends_on'][] = 'Date de fin valide, non antérieure au début.';
        }
        $organiser = self::optionalText($input['organiser'] ?? null, 160, 'organiser', $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $this->records->addTraining($principal->tenantId, (string) $info->staffId, (string) $title, (string) $starts, $ends, $organiser, $principal->userId, $this->clock->now());
    }

    /** @return list<array<string, mixed>> */
    public function trainings(string $tenantId, string $establishmentId, string $personId, ?string $afterId, int $limit): array
    {
        $info = $this->teacher($tenantId, $establishmentId, $personId);

        return $this->records->trainings($tenantId, (string) $info->staffId, $afterId, $limit);
    }

    /**
     * Mutation vers une autre école du même territoire (niveau `public_schools.staff.move_level`, département par défaut), via le
     * contrat du socle : affectations closes, rattachement mis à jour, historique conservé.
     *
     * @param array<string, mixed> $input to_establishment_id, effective_on, reason
     * @return array<string, mixed>
     */
    public function move(Principal $principal, string $establishmentId, string $personId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $info = $this->teacher($tenantId, $establishmentId, $personId);
        $errors = [];
        $to = self::optionalText($input['to_establishment_id'] ?? null, 40, 'to_establishment_id', $errors);
        if ($to === null) {
            $errors['to_establishment_id'][] = 'École d’accueil requise.';
        }
        $on = self::optionalText($input['effective_on'] ?? null, 10, 'effective_on', $errors);
        if ($on === null || !self::isDate($on)) {
            $errors['effective_on'][] = 'Date d’effet au format AAAA-MM-JJ requise.';
        }
        $reason = self::optionalText($input['reason'] ?? null, 255, 'reason', $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $this->assertSameTerritory($tenantId, $establishmentId, (string) $to);

        $move = $this->mover->move($tenantId, (string) $info->staffId, (string) $to, (string) $on, $reason, $principal->userId);
        $this->audit->append($tenantId, $principal->userId, 'public_schools.staff.moved', 'staff', (string) $info->staffId, ['establishment_id' => $establishmentId], ['establishment_id' => (string) $to, 'effective_on' => (string) $on]);

        return self::moveResource($move);
    }

    /** @return list<array<string, mixed>> */
    public function moves(string $tenantId, string $establishmentId, string $personId): array
    {
        $info = $this->teacher($tenantId, $establishmentId, $personId);

        return array_map(static fn (StaffMoveInfo $move): array => self::moveResource($move), $this->mover->history($tenantId, (string) $info->staffId));
    }

    /**
     * Besoins en enseignants de l'école (EX-PER-04) : élèves par enseignant, classes sans titulaire et, si l'objectif
     * `public_schools.staff.pupils_per_teacher_target` est fixé, nombre d'enseignants manquants. Aucune donnée nominative.
     *
     * @return array<string, mixed>
     */
    public function needs(string $tenantId, string $establishmentId, string $day): array
    {
        $teachers = count($this->staff->teacherIdsIn($tenantId, $establishmentId));
        $pupils = 0;
        $withoutHomeroom = [];
        $period = $this->periods->at($tenantId, $establishmentId, $day);
        $classes = $period === null ? [] : $this->classes->classesOf($tenantId, $establishmentId, $period->academicYearId);
        foreach ($classes as $class) {
            $pupils += $this->classes->headcount($tenantId, $establishmentId, $class->structureId, $day);
            if ($class->homeroomStaffId === null) {
                $withoutHomeroom[] = ['structure_id' => $class->structureId, 'label' => $class->label];
            }
        }
        $target = $this->settings->value($tenantId, $establishmentId, 'public_schools.staff.pupils_per_teacher_target', null);
        $target = is_numeric($target) && (int) $target > 0 ? (int) $target : null;

        return [
            'date' => $day,
            'teachers' => $teachers,
            'pupils' => $pupils,
            'classes' => count($classes),
            'pupils_per_teacher' => $teachers > 0 ? round($pupils / $teachers, 1) : null,
            'pupils_per_teacher_target' => $target,
            'teachers_missing' => $target === null ? null : max(0, (int) ceil($pupils / $target) - $teachers),
            'classes_without_homeroom' => $withoutHomeroom,
        ];
    }

    public function teacher(string $tenantId, string $establishmentId, string $personId): StaffInfo
    {
        $info = in_array($personId, $this->staff->teacherIdsIn($tenantId, $establishmentId), true) ? $this->staff->find($tenantId, $personId) : null;
        if ($info === null || $info->staffId === null) {
            throw HttpException::notFound('Membre du personnel introuvable dans cet établissement.');
        }

        return $info;
    }

    /**
     * Visibilité (RG-PER-2) : `$full` faux = statut, grade et affectation seulement.
     *
     * @param array<string, mixed>|null $profile
     * @return array<string, mixed>
     */
    private function resource(StaffInfo $info, ?array $profile, bool $full): array
    {
        $resource = [
            'staff_id' => (string) $info->staffId,
            'grade' => $info->grade,
            'employment' => $info->status,
            'public_status' => $profile['public_status'] ?? null,
        ];
        if ($full) {
            $resource += [
                'civil_service_number' => $profile['civil_service_number'] ?? null,
                'contract_number' => $profile['contract_number'] ?? null,
                'professional_diploma' => $profile['professional_diploma'] ?? null,
                'service_start_on' => $profile['service_start_on'] ?? null,
            ];
        }

        return $resource;
    }

    /** @return array<string, mixed> */
    private static function moveResource(StaffMoveInfo $move): array
    {
        return [
            'id' => $move->id,
            'staff_id' => $move->staffId,
            'from_establishment_id' => $move->fromEstablishmentId,
            'to_establishment_id' => $move->toEstablishmentId,
            'effective_on' => $move->effectiveOn,
            'reason' => $move->reason,
            'ended_assignments' => $move->endedAssignments,
        ];
    }

    private function assertSameTerritory(string $tenantId, string $fromId, string $toId): void
    {
        $now = $this->clock->now();
        $level = $this->settings->value($tenantId, $fromId, 'public_schools.staff.move_level', self::DEFAULT_MOVE_LEVEL);
        $level = is_string($level) && $level !== '' ? $level : self::DEFAULT_MOVE_LEVEL;
        $from = $this->territories->territoryOf($tenantId, $fromId, $now);
        $to = $this->territories->territoryOf($tenantId, $toId, $now);
        if ($from === null || $to === null) {
            throw HttpException::validation(['to_establishment_id' => ['Les deux écoles doivent être rattachées à un territoire.']]);
        }
        $shared = [];
        foreach ($this->territories->lineage($tenantId, $from->id, $now) as $node) {
            if ($node->type === $level) {
                $shared[$node->id] = true;
            }
        }
        foreach ($this->territories->lineage($tenantId, $to->id, $now) as $node) {
            if ($node->type === $level && isset($shared[$node->id])) {
                return;
            }
        }

        throw HttpException::validation(['to_establishment_id' => ['La mutation reste à l’intérieur du même territoire (' . $level . ').']]);
    }

    private static function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /** @param array<string, list<string>> $errors */
    private static function optionalText(mixed $value, int $max, string $field, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $max) {
            $errors[$field][] = 'Texte de ' . $max . ' caractères au plus attendu.';

            return null;
        }

        return trim($value);
    }
}
