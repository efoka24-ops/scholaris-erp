<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\SemesterTranscripts;
use Scholaris\Modules\Apps\Higher\Deliberation\Documents\DeliberationDocumentTypes as Types;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\AnnualTranscript;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\LmdCalculator;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\Rules;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;

/**
 * Relevé de notes et de crédits DOC-10 (annuel et cumulatif) : un étudiant, ou toute une promotion (groupe et année). Émis par le
 * socle Documents à partir des seuls résultats PUBLIÉS des semestres de l'année (PV signés, RG-DOC-3) et de la décision de fin
 * d'année enregistrée (EX-DEL-07). L'émission en lot saute, avec sa raison, les étudiants sans décision, sans relevé publié ou déjà
 * servis par un relevé valide postérieur à la décision : relancer le lot n'émet rien en double.
 */
final class AnnualTranscriptService
{
    public const PASSING_STATUSES = [LmdCalculator::ACQUIRED, LmdCalculator::COMPENSATED, LmdCalculator::CAPITALIZED, 'passed'];
    public const BATCH_LIMIT = 400;

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly AcademicDirectory $academic,
        private readonly StudentDirectory $students,
        private readonly ResultsDirectory $results,
        private readonly SemesterTranscripts $transcripts,
        private readonly HigherDocumentIssuer $issuer,
        private readonly RulesProvider $rules,
    ) {
    }

    /**
     * Aperçu du relevé annuel d'un étudiant (personnel habilité, dans son périmètre).
     *
     * @return array<string, mixed>
     */
    public function preview(Principal $principal, string $establishmentId, string $personId, string $academicYearId, string $locale = 'fr'): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, [DeliberationPermissions::READ, DeliberationPermissions::PREPARE]);
        $data = $this->build($principal->tenantId, $establishmentId, $personId, $academicYearId, $locale);
        $this->access->assertReadable($perimeter, ['school' => $data['school_id'], 'program' => $data['program_id'], 'level' => $data['level_id'], 'group' => $data['structure_id']]);

        return $data;
    }

    /** @return array<string, mixed> document émis (numéro, jeton de vérification…) */
    public function issue(Principal $principal, string $establishmentId, string $personId, string $academicYearId, ?string $locale): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, [DeliberationPermissions::PREPARE]);
        $data = $this->build($principal->tenantId, $establishmentId, $personId, $academicYearId, $locale ?? 'fr');
        $this->access->assertWritable($principal, $perimeter, ['school' => $data['school_id'], 'program' => $data['program_id'], 'group' => $data['structure_id']], 'annual_transcript', $personId);

        return $this->emit($principal, $establishmentId, $personId, $data);
    }

    /**
     * Émission pour une promotion : tous les étudiants inscrits au groupe pour l'année.
     *
     * @return array{academic_year_id: string, structure_id: string, total: int, issued: list<array<string, mixed>>, skipped: list<array{person_id: string, matricule: ?string, reason: string}>}
     */
    public function issueBatch(Principal $principal, string $establishmentId, string $structureId, string $academicYearId, ?string $locale): array
    {
        $tenantId = $principal->tenantId;
        $group = $this->academic->group($tenantId, $establishmentId, $structureId) ?? throw HttpException::notFound('Groupe introuvable.');
        $program = $this->academic->program($tenantId, $establishmentId, $group->programId) ?? throw HttpException::notFound('Filière introuvable.');
        $scope = ['school' => $program->schoolId, 'program' => $program->id, 'group' => $group->id];
        $perimeter = $this->access->require($principal, $establishmentId, [DeliberationPermissions::PREPARE]);
        $this->access->assertWritable($principal, $perimeter, $scope, 'annual_transcript_batch', $structureId);

        $enrolled = $this->students->enrolledIn($tenantId, $establishmentId, $structureId, $academicYearId);
        if (count($enrolled) > self::BATCH_LIMIT) {
            throw HttpException::validation(['structure_id' => [sprintf('Promotion de plus de %d étudiants : émettez par groupe plus petit.', self::BATCH_LIMIT)]]);
        }
        $issued = [];
        $skipped = [];
        foreach ($enrolled as $enrolment) {
            $personId = $enrolment->personId;
            try {
                $data = $this->build($tenantId, $establishmentId, $personId, $academicYearId, $locale ?? 'fr');
                if ($this->alreadyIssued($tenantId, $establishmentId, $personId, $academicYearId, $data)) {
                    $skipped[] = ['person_id' => $personId, 'matricule' => $enrolment->matricule, 'reason' => 'already_issued'];

                    continue;
                }
                $issued[] = $this->emit($principal, $establishmentId, $personId, $data) + ['person_id' => $personId];
            } catch (HttpException $e) {
                $skipped[] = ['person_id' => $personId, 'matricule' => $enrolment->matricule, 'reason' => $e->getMessage()];
            }
        }
        $this->access->audit($principal, 'higher.deliberation.annual_transcripts_issued', 'structure', $structureId, null, ['academic_year_id' => $academicYearId, 'issued' => count($issued), 'skipped' => count($skipped)]);

        return ['academic_year_id' => $academicYearId, 'structure_id' => $structureId, 'total' => count($enrolled), 'issued' => $issued, 'skipped' => $skipped];
    }

    /**
     * Données du relevé annuel : semestres publiés de l'année + décision de fin d'année enregistrée.
     *
     * @return array<string, mixed>
     */
    public function build(string $tenantId, string $establishmentId, string $personId, string $academicYearId, string $locale = 'fr'): array
    {
        $locale = $locale === 'en' ? 'en' : 'fr';
        $decision = $this->store->select('hi_year_decisions', $tenantId, $establishmentId, ['person_id' => $personId, 'academic_year_id' => $academicYearId], 'decided_at DESC', 1)[0]
            ?? throw HttpException::conflict('Aucune décision de fin d\'année enregistrée pour cet étudiant et cette année : délibérez d\'abord l\'année (RG-DOC-3).');
        $periods = [];
        foreach ($this->results->publishedFor($tenantId, $personId) as $result) {
            if ($result->establishmentId === $establishmentId) {
                $periods[$result->periodId] = true;
            }
        }
        $periodIds = array_map('strval', array_keys($periods));
        natsort($periodIds);
        $semesters = [];
        foreach ($periodIds as $periodId) {
            try {
                $semester = $this->transcripts->build($tenantId, $establishmentId, $personId, $periodId, $locale);
            } catch (HttpException) {
                continue;
            }
            if ($semester['academic_year_id'] === $academicYearId) {
                $semesters[] = $this->withMentions($tenantId, $establishmentId, $semester);
            }
        }
        if ($semesters === []) {
            throw HttpException::notFound('Aucun relevé semestriel publié après signature du procès-verbal pour cette année (RG-DOC-3).');
        }

        return AnnualTranscript::compose($semesters, $decision, $locale);
    }

    /**
     * @param array<string, mixed> $semester
     * @return array<string, mixed>
     */
    private function withMentions(string $tenantId, string $establishmentId, array $semester): array
    {
        $jury = $this->store->find(JuryService::TABLE, $tenantId, $establishmentId, (string) $semester['jury_id']);
        $rules = $jury === null ? null : new Rules($this->rules->forJury($jury));
        foreach ($semester['units'] as $i => $unit) {
            $average = $unit['average'];
            $semester['units'][$i]['mention'] = $rules !== null && is_float($average) && in_array($unit['status'], self::PASSING_STATUSES, true) ? $rules->mention($average) : null;
        }

        return $semester;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function emit(Principal $principal, string $establishmentId, string $personId, array $data): array
    {
        $issued = $this->issuer->issueFor(
            $principal->tenantId,
            $establishmentId,
            Types::ANNUAL_TRANSCRIPT,
            $personId,
            (string) $data['school_id'],
            (string) $data['program_id'],
            (string) $data['level_id'],
            AnnualTranscript::variables($data),
            $principal->userId,
            (string) $data['locale'],
        );
        $this->access->audit($principal, 'higher.deliberation.annual_transcript_issued', 'person', $personId, null, ['academic_year_id' => $data['academic_year_id'], 'number' => $issued['number'] ?? null]);

        return $issued;
    }

    /** @param array<string, mixed> $data */
    private function alreadyIssued(string $tenantId, string $establishmentId, string $personId, string $academicYearId, array $data): bool
    {
        $decision = $this->store->select('hi_year_decisions', $tenantId, $establishmentId, ['person_id' => $personId, 'academic_year_id' => $academicYearId], 'decided_at DESC', 1)[0] ?? null;
        $since = is_array($decision) ? (string) $decision['decided_at'] : '';
        foreach ($this->store->select('hi_issued_documents', $tenantId, $establishmentId, ['person_id' => $personId, 'doc_code' => Types::ANNUAL_TRANSCRIPT, 'doc_status' => 'valid', 'program_id' => $data['program_id']], 'issued_at DESC', 1) as $row) {
            if ((string) $row['issued_at'] >= $since) {
                return true;
            }
        }

        return false;
    }
}
