<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\ProgramInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Domain\MatriculeFormat;
use Scholaris\Modules\Apps\Higher\Enrolment\Infrastructure\EnrolmentStore;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentNumberProvider;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentNumberRequest;

/**
 * Matricule IPES (RG-INS-1, T228, D5) : format paramétrable par école (`higher.enrolment.matricule_pattern`, défaut
 * `{AA}{ECOLE}-{FILIERE}-{N4}` => 26EC-CF-0042), attribué à la première inscription validée, définitif (inchangé par un changement
 * de filière), jamais réattribué (compteurs jamais décrémentés), unique par personne (double cursus : un seul matricule, RG-ORG-6).
 *
 * Également fournisseur de numéro du socle (`people.enrollment_number.providers.higher`) : une inscription du socle validée dans un
 * établissement `higher` reçoit le matricule IPES de la personne, ce qui évite deux numéros concurrents.
 */
final class MatriculeService implements EnrollmentNumberProvider
{
    public const CONTAINER_KEY = 'people.enrollment_number.providers.higher';
    public const STUDENTS = 'hi_students';
    public const SEQUENCES = 'hi_matricule_sequences';

    public function __construct(
        private readonly EnrolmentStore $store,
        private readonly AcademicDirectory $academic,
        private readonly OrgUnitTree $tree,
        private readonly OrgUnitSettingsReader $settings,
        private readonly EnrolmentClock $clock,
    ) {
    }

    public function supports(EnrollmentNumberRequest $request): bool
    {
        return $request->establishmentType === 'higher' && $this->programOf($request) !== null;
    }

    public function next(EnrollmentNumberRequest $request): string
    {
        $program = $this->programOf($request) ?? throw new \LogicException('Structure hors filière IPES.');

        return $this->assign($request->tenantId, $request->establishmentId, $request->personId, $program, $request->year);
    }

    /** Matricule de la personne, attribué au besoin (une seule fois, définitivement). */
    public function assign(string $tenantId, string $establishmentId, string $personId, ProgramInfo $program, int $year): string
    {
        return $this->store->transaction(function () use ($tenantId, $establishmentId, $personId, $program, $year): string {
            $student = $this->ensureStudent($tenantId, $establishmentId, $personId, $program);
            if ($student['matricule'] !== null) {
                return (string) $student['matricule'];
            }
            $school = $this->academic->school($tenantId, $establishmentId, $program->schoolId)
                ?? throw HttpException::validation(['school_id' => ['École de la filière introuvable : matricule impossible.']]);
            $format = $this->format($tenantId, $establishmentId, $program->schoolId);
            $key = $format->sequenceKey($year, $school->code, $program->code);
            do {
                $matricule = $format->format($year, $school->code, $program->code, $this->nextNumber($tenantId, $establishmentId, $key));
            } while ($this->store->count(self::STUDENTS, $tenantId, $establishmentId, ['matricule' => $matricule]) > 0);
            $this->store->update(self::STUDENTS, $tenantId, $establishmentId, [
                'matricule' => $matricule, 'matricule_assigned_at' => $this->clock->now(), 'updated_at' => $this->clock->now(),
            ], ['id' => $student['id']]);

            return $matricule;
        });
    }

    /**
     * Dossier étudiant de la personne dans l'établissement, créé au besoin (EX-INS-01).
     *
     * @return array<string, mixed>
     */
    public function ensureStudent(string $tenantId, string $establishmentId, string $personId, ProgramInfo $program): array
    {
        $existing = $this->store->select(self::STUDENTS, $tenantId, $establishmentId, ['person_id' => $personId], 'id', 1)[0] ?? null;
        if ($existing !== null) {
            return $existing;
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::STUDENTS, $tenantId, $establishmentId, [
            'id' => $id, 'person_id' => $personId, 'matricule' => null, 'matricule_assigned_at' => null, 'school_id' => $program->schoolId,
            'program_id' => $program->id, 'photo_ref' => null, 'guarantor_person_id' => null, 'share_with_guarantor' => 0,
            'consent_recorded_by' => null, 'consent_recorded_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->store->findOrFail(self::STUDENTS, $tenantId, $establishmentId, $id);
    }

    private function format(string $tenantId, string $establishmentId, string $schoolId): MatriculeFormat
    {
        $pattern = $this->settings->valueForOrgUnit($tenantId, $establishmentId, $schoolId, EnrolmentPermissions::MATRICULE_PATTERN, MatriculeFormat::DEFAULT);
        try {
            return new MatriculeFormat(is_string($pattern) ? $pattern : MatriculeFormat::DEFAULT);
        } catch (\InvalidArgumentException $e) {
            throw HttpException::validation([EnrolmentPermissions::MATRICULE_PATTERN => [$e->getMessage()]], 'Paramètre de matricule invalide.');
        }
    }

    /** Numéro d'ordre suivant pour la clé (année, école, filière) : compteur incrémenté, jamais décrémenté ni réutilisé. */
    private function nextNumber(string $tenantId, string $establishmentId, string $key): int
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $row = $this->store->select(self::SEQUENCES, $tenantId, $establishmentId, ['sequence_key' => $key], 'id', 1)[0] ?? null;
            if ($row === null) {
                $this->store->insert(self::SEQUENCES, $tenantId, $establishmentId, [
                    'id' => (string) new Ulid(), 'sequence_key' => $key, 'last_number' => 1, 'updated_at' => $this->clock->now(),
                ]);

                return 1;
            }
            $next = (int) $row['last_number'] + 1;
            if ($this->store->update(self::SEQUENCES, $tenantId, $establishmentId, ['last_number' => $next, 'updated_at' => $this->clock->now()], ['id' => $row['id'], 'last_number' => $row['last_number']]) === 1) {
                return $next;
            }
        }

        throw HttpException::conflict('Attribution du matricule concurrente : réessayez.');
    }

    private function programOf(EnrollmentNumberRequest $request): ?ProgramInfo
    {
        $track = $this->tree->trackOf($request->tenantId, $request->establishmentId, $request->structureId);

        return $track === null ? null : $this->academic->program($request->tenantId, $request->establishmentId, $track);
    }
}
