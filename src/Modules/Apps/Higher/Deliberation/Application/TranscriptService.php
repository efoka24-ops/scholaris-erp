<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Deliberation\Documents\DeliberationDocumentTypes as Types;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\LmdCalculator;
use Scholaris\Modules\Apps\Higher\Deliberation\Infrastructure\PdoDeliberationRecords;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\PublishedResult;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;
use Scholaris\Modules\Socle\People\Contracts\BirthInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Relevés (DOC-09 semestriel, DOC-10 annuel et cumulatif) : contenu tiré des seuls résultats PUBLIÉS (donc après signature du PV,
 * RG-DOC-3), aperçu fidèle au modèle du CDC et émission par le module Documents (numéro REL-EC-AAAA-NNNNNN, QR, page de
 * vérification sans les notes). Résultats publiés de l'étudiant connecté (API-16).
 */
final class TranscriptService
{
    public const RESULT_LABELS = [
        'fr' => [LmdCalculator::ACQUIRED => 'Acquise', LmdCalculator::COMPENSATED => 'Acquise par compensation', LmdCalculator::CAPITALIZED => 'Capitalisée', LmdCalculator::NOT_ACQUIRED => 'Non acquise', 'passed' => 'Validée', 'failed' => 'Non validée'],
        'en' => [LmdCalculator::ACQUIRED => 'Passed', LmdCalculator::COMPENSATED => 'Passed by compensation', LmdCalculator::CAPITALIZED => 'Carried over', LmdCalculator::NOT_ACQUIRED => 'Not passed', 'passed' => 'Passed', 'failed' => 'Not passed'],
    ];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly AcademicDirectory $academic,
        private readonly StudentDirectory $students,
        private readonly ResultsDirectory $results,
        private readonly PersonDirectory $people,
        private readonly BirthInfo $births,
        private readonly AuthorizationRegistry $authorizations,
        private readonly UserPersonLink $users,
        private readonly HigherDocumentIssuer $issuer,
        private readonly PermissionChecker $permissions,
    ) {
    }

    /**
     * Relevé semestriel d'un étudiant (personnel habilité, dans son périmètre).
     *
     * @return array<string, mixed>
     */
    public function semester(Principal $principal, string $establishmentId, string $personId, string $periodId, string $locale = 'fr'): array
    {
        $data = $this->build($principal->tenantId, $establishmentId, $personId, $periodId, $locale);
        $perimeter = $this->access->require($principal, $establishmentId, [DeliberationPermissions::READ, DeliberationPermissions::PREPARE]);
        $this->access->assertReadable($perimeter, ['school' => $data['school_id'], 'program' => $data['program_id'], 'level' => $data['level_id'], 'group' => $data['structure_id']]);

        return $data;
    }

    /**
     * Résultats publiés de l'étudiant connecté, par période (API-16) ; jamais une note non publiée.
     *
     * @return list<array<string, mixed>>
     */
    public function mine(Principal $principal, string $establishmentId, string $locale = 'fr'): array
    {
        if (!$this->permissions->can($principal, DeliberationPermissions::SELF, $establishmentId)) {
            throw HttpException::forbidden('Permission requise : self.read.');
        }
        $personId = $this->users->personIdForUser($principal->tenantId, $principal->userId) ?? throw HttpException::notFound('Aucun dossier étudiant pour ce compte.');
        $out = [];
        $seen = [];
        foreach ($this->results->publishedFor($principal->tenantId, $personId) as $result) {
            if ($result->establishmentId !== $establishmentId || isset($seen[$result->periodId])) {
                continue;
            }
            $seen[$result->periodId] = true;
            try {
                $out[] = $this->build($principal->tenantId, $establishmentId, $personId, $result->periodId, $locale);
            } catch (HttpException) {
                continue;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> document émis (numéro, jeton de vérification…) */
    public function issueSemester(Principal $principal, string $establishmentId, string $personId, string $periodId, ?string $locale): array
    {
        $data = $this->build($principal->tenantId, $establishmentId, $personId, $periodId, $locale ?? 'fr');
        $perimeter = $this->access->require($principal, $establishmentId, [DeliberationPermissions::PREPARE]);
        $this->access->assertWritable($principal, $perimeter, ['school' => $data['school_id'], 'program' => $data['program_id'], 'group' => $data['structure_id']], 'transcript', $personId);
        $locale = $data['locale'];
        $vars = [
            'holder_last_name' => $data['student']['last_name'], 'holder_first_names' => $data['student']['first_names'], 'birth_info' => (string) $data['student']['birth_date'],
            'holder_matricule' => (string) $data['student']['matricule'], 'authorization_ref' => (string) $data['authorization_ref'], 'tutelage_name' => (string) $data['tutelage'],
            'semester_label' => $data['period_label'], 'session_label' => $data['session_label'], 'academic_year' => $data['academic_year_id'], 'jury_ref' => $data['jury_id'],
            'semester_average' => self::num($data['synthesis']['average'], $locale), 'gpa' => self::num($data['synthesis']['gpa'], $locale),
            'credits_earned' => (string) $data['synthesis']['credits_earned'], 'credits_total' => (string) $data['synthesis']['credits_total'],
            'decision' => (string) $data['synthesis']['decision'], 'mention' => (string) ($data['synthesis']['mention'] ?? '—'),
        ];
        foreach (array_slice($data['units'], 0, Types::UNIT_SLOTS) as $i => $unit) {
            $p = 'u' . Types::slot($i + 1) . '_';
            $elements = array_map(static fn (array $e): string => trim((string) $e['code']) . ' ' . self::num($e['note'], $locale), $unit['elements']);
            $vars += [
                $p . 'code' => (string) $unit['code'], $p . 'title' => (string) $unit['name'], $p . 'credits' => (string) $unit['credits'], $p . 'elements' => implode(' · ', $elements),
                $p . 'average' => self::num($unit['average'], $locale), $p . 'letter' => (string) $unit['letter'], $p . 'points' => self::num($unit['points'], $locale),
                $p . 'result' => (string) $unit['result_label'], $p . 'session' => (string) $unit['session_label'],
            ];
        }
        if (count($data['units']) > Types::UNIT_SLOTS) {
            throw HttpException::validation(['units' => [sprintf('Le modèle de relevé compte %d UE au plus.', Types::UNIT_SLOTS)]]);
        }
        $issued = $this->issuer->issueFor($principal->tenantId, $establishmentId, Types::TRANSCRIPT, $personId, $data['school_id'], $data['program_id'], $data['level_id'], $vars, $principal->userId, $locale);
        $this->access->audit($principal, 'higher.deliberation.transcript_issued', 'person', $personId, null, ['period_id' => $periodId, 'number' => $issued['number'] ?? null]);

        return $issued;
    }

    /**
     * Données du relevé semestriel : identité, en-tête, UE et EC, synthèse.
     *
     * @return array<string, mixed>
     */
    public function build(string $tenantId, string $establishmentId, string $personId, string $periodId, string $locale = 'fr'): array
    {
        $locale = $locale === 'en' ? 'en' : 'fr';
        [$jury, $result] = $this->publishedResult($tenantId, $establishmentId, $personId, $periodId);
        $person = $this->people->find($tenantId, $personId);
        $program = $this->academic->program($tenantId, $establishmentId, (string) $jury['program_id']);
        $level = $this->academic->level($tenantId, $establishmentId, (string) $jury['level_id']);
        $school = $this->academic->school($tenantId, $establishmentId, (string) $jury['school_id']);
        $tutelage = $this->academic->tutelageFor($tenantId, $establishmentId, (string) $jury['program_id'], (string) $jury['academic_year_id']);
        $authorization = $this->authorizations->forStructure($tenantId, $establishmentId, (string) $jury['program_id']);
        $labels = self::RESULT_LABELS[$locale];

        $units = [];
        $points = 0.0;
        $credits = 0;
        foreach ($result->details as $detail) {
            $unitCredits = (int) ($detail['credits'] ?? 0);
            $unitPoints = isset($detail['points']) && is_numeric($detail['points']) ? (float) $detail['points'] : null;
            $points += ($unitPoints ?? 0.0) * $unitCredits;
            $credits += $unitCredits;
            $status = (string) ($detail['status'] ?? '');
            $units[] = [
                'code' => (string) ($detail['code'] ?? ''), 'name' => (string) ($detail['name'] ?? ''), 'credits' => $unitCredits,
                'average' => isset($detail['average']) && is_numeric($detail['average']) ? (float) $detail['average'] : null,
                'letter' => (string) ($detail['letter'] ?? ''), 'points' => $unitPoints, 'status' => $status, 'result_label' => $labels[$status] ?? $status,
                'session' => (string) ($detail['session'] ?? $result->session),
                'session_label' => self::sessionLabel((string) ($detail['session'] ?? $result->session), $locale),
                'elements' => array_values(array_map(static fn (array $e): array => [
                    'code' => (string) ($e['code'] ?? ''), 'credits' => (int) ($e['credits'] ?? 0),
                    'note' => isset($e['note']) && is_numeric($e['note']) ? (float) $e['note'] : null,
                ], array_filter((array) ($detail['elements'] ?? []), 'is_array'))),
            ];
        }

        return [
            'jury_id' => (string) $jury['id'], 'structure_id' => (string) $jury['structure_id'], 'school_id' => (string) $jury['school_id'],
            'program_id' => (string) $jury['program_id'], 'level_id' => (string) $jury['level_id'], 'academic_year_id' => (string) $jury['academic_year_id'],
            'period_id' => $periodId, 'period_label' => (string) $jury['period_label'], 'session' => $result->session, 'session_label' => self::sessionLabel($result->session, $locale),
            'regime' => (string) $jury['regime'], 'locale' => $locale,
            'school' => ['name' => $school->name ?? null, 'code' => $school->code ?? null],
            'program' => ['name' => $program->name ?? null, 'code' => $program->code ?? null], 'level' => ['name' => $level->name ?? null, 'code' => $level->code ?? null],
            'authorization_ref' => $authorization === [] ? null : $authorization[count($authorization) - 1]->reference,
            'tutelage' => $tutelage?->universityName,
            'student' => [
                'person_id' => $personId, 'last_name' => $person->lastName ?? '', 'first_names' => $person->firstName ?? '',
                'birth_date' => $this->births->birthDate($tenantId, $personId), 'matricule' => $this->students->student($tenantId, $establishmentId, $personId)?->matricule,
            ],
            'units' => $units,
            'synthesis' => [
                'average' => $result->average, 'gpa' => $credits > 0 ? round($points / $credits, 2) : null, 'credits_earned' => (int) $result->creditsEarned,
                'credits_total' => (int) ($result->creditsTotal ?? $credits), 'decision' => $result->decision, 'mention' => $result->mention,
                'rank' => $result->rank, 'class_size' => $result->classSize,
            ],
            'verification_notice' => $locale === 'en' ? 'Only the signed original or the document verified online is authentic' : 'Seul l\'original signé ou vérifié en ligne fait foi',
        ];
    }

    /** @return array{0: array<string, mixed>, 1: PublishedResult} jury signé et résultat publié (rattrapage de préférence) */
    private function publishedResult(string $tenantId, string $establishmentId, string $personId, string $periodId): array
    {
        $groups = array_values(array_filter(array_map(static fn (EnrolmentInfo $e): ?string => $e->groupId, $this->students->enrolmentsOf($tenantId, $establishmentId, $personId))));
        $published = [];
        foreach ($this->results->publishedFor($tenantId, $personId) as $result) {
            if ($result->periodId === $periodId && $result->establishmentId === $establishmentId) {
                $published[$result->session] ??= $result;
            }
        }
        foreach (['resit', 'normal'] as $session) {
            if (!isset($published[$session])) {
                continue;
            }
            foreach ($groups as $group) {
                $jury = $this->store->select(JuryService::TABLE, $tenantId, $establishmentId, ['structure_id' => $group, 'period_id' => $periodId, 'exam_session' => $session], 'id', 1)[0] ?? null;
                if ($jury !== null && in_array($jury['status'], PdoDeliberationRecords::SIGNED, true)) {
                    return [$jury, $published[$session]];
                }
            }
        }

        throw HttpException::notFound('Aucun résultat publié après signature du procès-verbal pour cet étudiant et cette période (RG-DOC-3).');
    }

    public static function num(int|float|null $value, string $locale): string
    {
        if ($value === null) {
            return '—';
        }

        return $locale === 'en' ? number_format((float) $value, 2, '.', ',') : number_format((float) $value, 2, ',', ' ');
    }

    private static function sessionLabel(string $session, string $locale): string
    {
        return match ([$session, $locale]) {
            ['resit', 'fr'] => 'Rattrapage',
            ['resit', 'en'] => 'Resit',
            ['normal', 'en'] => 'Regular',
            default => 'Normale',
        };
    }
}
