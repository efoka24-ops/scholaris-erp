<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\SemesterTranscripts;
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
final class TranscriptService implements SemesterTranscripts
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
        // C-103 : plus de limite à 10 UE. Le modèle de relevé en vigueur (tableau `units_table`, installé ou mis à niveau par la
        // migration 202611051201) liste toutes les UE ; les emplacements u01 à u10 ne servent qu'aux modèles personnalisés anciens.
        $vars = self::semesterVariables($data);
        $issued = $this->issuer->issueFor($principal->tenantId, $establishmentId, Types::TRANSCRIPT, $personId, $data['school_id'], $data['program_id'], $data['level_id'], $vars, $principal->userId, $locale);
        $this->access->audit($principal, 'higher.deliberation.transcript_issued', 'person', $personId, null, ['period_id' => $periodId, 'number' => $issued['number'] ?? null]);

        return $issued;
    }

    /**
     * Variables du modèle DOC-09 à partir des données du relevé (build) : emplacements numérotés u01_… (anciens modèles installés)
     * et tableau `units_table` (mise en page DOC-BUL : une ligne par UE puis ses EC, ligne de total).
     *
     * @param array<string, mixed> $data
     * @return array<string, scalar|null>
     */
    public static function semesterVariables(array $data): array
    {
        $locale = $data['locale'] === 'en' ? 'en' : 'fr';
        $synthesis = $data['synthesis'];
        $vars = [
            'holder_last_name' => (string) $data['student']['last_name'], 'holder_first_names' => (string) $data['student']['first_names'], 'birth_info' => (string) $data['student']['birth_date'],
            'holder_matricule' => (string) $data['student']['matricule'], 'authorization_ref' => (string) $data['authorization_ref'], 'tutelage_name' => (string) $data['tutelage'],
            'semester_label' => (string) $data['period_label'], 'session_label' => (string) $data['session_label'], 'academic_year' => (string) $data['academic_year_id'], 'jury_ref' => (string) $data['jury_id'],
            'semester_average' => self::num($synthesis['average'], $locale), 'gpa' => self::num($synthesis['gpa'], $locale),
            'credits_earned' => (string) $synthesis['credits_earned'], 'credits_total' => (string) $synthesis['credits_total'],
            'decision' => (string) $synthesis['decision'], 'mention' => (string) ($synthesis['mention'] ?? '—'),
            'rank_info' => is_int($synthesis['rank'] ?? null) ? $synthesis['rank'] . (is_int($synthesis['class_size'] ?? null) ? ' / ' . $synthesis['class_size'] : '') : '—',
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
        $en = $locale === 'en';
        $bi = static fn (string $fr, string $english): string => $en ? $english . "\n" . $fr : $fr . "\n" . $english;
        $rows = [];
        foreach ($data['units'] as $unit) {
            $rows[] = ['kind' => 'group', 'cells' => [
                (string) $unit['code'], (string) $unit['name'], (string) $unit['credits'], self::num($unit['average'], $locale),
                self::num($unit['points'], $locale), (string) $unit['letter'], (string) $unit['result_label'], (string) $unit['session_label'],
            ]];
            foreach ($unit['elements'] as $element) {
                $rows[] = ['', ['text' => '· ' . trim((string) $element['code'])], (string) $element['credits'], self::num($element['note'], $locale), '', '', '', ''];
            }
        }
        $rows[] = ['kind' => 'total', 'cells' => [
            ['text' => $en ? 'Semester total and average' : 'Total et moyenne du semestre', 'colspan' => 2], (string) $synthesis['credits_total'],
            self::num($synthesis['average'], $locale), self::num($synthesis['gpa'], $locale), '', ['text' => (string) $synthesis['credits_earned'] . ' / ' . (string) $synthesis['credits_total'] . ($en ? ' credits' : ' crédits'), 'colspan' => 2],
        ]];
        $vars['units_table'] = json_encode([
            'columns' => [
                ['label' => 'Code', 'width' => 10], ['label' => $bi('Unités d\'enseignement et éléments constitutifs', 'Course units and elements'), 'width' => 36, 'align' => 'left'],
                ['label' => $bi('Crédits', 'Credits'), 'align' => 'center'], ['label' => $bi('Note /20', 'Mark /20'), 'align' => 'center'],
                ['label' => $bi('Cote', 'Points'), 'align' => 'center'], ['label' => 'Grade', 'align' => 'center'],
                ['label' => 'Validation', 'align' => 'center'], ['label' => 'Session', 'align' => 'center'],
            ],
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return $vars;
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
