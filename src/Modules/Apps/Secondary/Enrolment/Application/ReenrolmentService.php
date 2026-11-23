<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Réinscription (T827, EX-SEC-14, INS-02) : les élèves AUTORISÉS par le conseil de fin d'année (décision « admis », lue par
 * AdmissionDirectory) se réinscrivent dans n'importe quelle classe de l'année suivante ; un élève non admis ne se réinscrit
 * qu'en redoublant, dans une classe du même niveau. L'exclu définitif exige la décision motivée du chef (RG-INS-3, garde).
 * Chaque élève est traité seul : un refus n'empêche pas les autres (rapport par élève).
 */
final class ReenrolmentService
{
    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly EnrolmentService $enrolment,
        private readonly AdmissionDirectory $admissions,
        private readonly PersonDirectory $people,
    ) {
    }

    /** @return list<array<string, mixed>> élèves de l'année source, avec la décision et l'éventuelle réinscription déjà faite */
    public function candidates(Principal $principal, string $establishmentId, string $fromYear, ?string $toYear): array
    {
        $tenantId = $principal->tenantId;
        $this->access->require($principal, $establishmentId, EnrolmentService::READ);
        $admitted = array_flip($this->admissions->admittedPersonIds($tenantId, $establishmentId, $fromYear));
        $rows = $this->store->select('se_enrolment_records', $tenantId, $establishmentId, ['academic_year_id' => $fromYear], 'level_code, created_at', 5000);
        $done = [];
        if ($toYear !== null) {
            foreach ($this->store->select('se_enrolment_records', $tenantId, $establishmentId, ['academic_year_id' => $toYear], 'id', 5000) as $row) {
                $done[(string) $row['person_id']] = (string) $row['class_profile_id'];
            }
        }
        $names = [];
        foreach ($this->people->findMany($tenantId, array_values(array_unique(array_map(static fn (array $r): string => (string) $r['person_id'], $rows)))) as $person) {
            $names[$person->id] = $person->fullName();
        }
        $result = [];
        foreach ($rows as $row) {
            $personId = (string) $row['person_id'];
            $result[$personId] = [
                'person_id' => $personId, 'full_name' => $names[$personId] ?? null, 'level_code' => (string) $row['level_code'],
                'class_id' => (string) $row['class_profile_id'], 'admitted' => isset($admitted[$personId]), 'reenrolled_class_id' => $done[$personId] ?? null,
            ];
        }

        return array_values($result);
    }

    /**
     * @param array<string, mixed> $body from_academic_year_id, class_id, entry_date, students: [{person_id, repeat?, exclusion_decision_reason?}]
     * @return array{enrolled: list<string>, refused: list<array{person_id: string, reason: string}>}
     */
    public function reenrol(Principal $principal, string $establishmentId, array $body): array
    {
        $tenantId = $principal->tenantId;
        $this->access->require($principal, $establishmentId, EnrolmentService::MANAGE);
        $errors = [];
        $from = is_string($body['from_academic_year_id'] ?? null) ? $body['from_academic_year_id'] : '';
        $classId = is_string($body['class_id'] ?? null) ? $body['class_id'] : '';
        $date = is_string($body['entry_date'] ?? null) ? $body['entry_date'] : '';
        $students = $body['students'] ?? null;
        if ($from === '') {
            $errors['from_academic_year_id'] = ['Année source obligatoire.'];
        }
        if ($classId === '') {
            $errors['class_id'] = ['Classe obligatoire.'];
        }
        if (!EnrolmentService::isDate($date)) {
            $errors['entry_date'] = ['Date au format AAAA-MM-JJ attendue.'];
        }
        if (!is_array($students) || !array_is_list($students) || $students === [] || count($students) > 200) {
            $errors['students'] = ['Liste de 1 à 200 élèves attendue.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $class = $this->enrolment->classProfile($tenantId, $establishmentId, $classId);
        if ($class->academicYearId === $from) {
            throw HttpException::validation(['class_id' => ['Choisissez une classe de l\'année suivante.']]);
        }
        $admitted = array_flip($this->admissions->admittedPersonIds($tenantId, $establishmentId, $from));
        $enrolled = [];
        $refused = [];
        foreach ((array) $students as $item) {
            $personId = is_array($item) && is_string($item['person_id'] ?? null) ? $item['person_id'] : '';
            $previous = $personId === '' ? null : ($this->store->select('se_enrolment_records', $tenantId, $establishmentId, ['person_id' => $personId, 'academic_year_id' => $from], 'created_at DESC', 1)[0] ?? null);
            if ($previous === null) {
                $refused[] = ['person_id' => $personId, 'reason' => 'Élève non inscrit dans l\'établissement l\'année source.'];
                continue;
            }
            $repeat = ($item['repeat'] ?? false) === true;
            if (!isset($admitted[$personId]) && !($repeat && $previous['level_code'] === $class->levelCode)) {
                $refused[] = ['person_id' => $personId, 'reason' => 'Non autorisé par le conseil de fin d\'année : seule une réinscription en redoublement, dans le même niveau, est possible.'];
                continue;
            }
            try {
                $this->enrolment->admit($principal, $establishmentId, [
                    'person_id' => $personId, 'class_id' => $classId, 'entry_date' => $date, 'entry_kind' => 'reenrolment',
                    'repeater' => !isset($admitted[$personId]), 'national_id' => null, 'documents_checked' => [],
                    'exclusion_decision_reason' => is_string($item['exclusion_decision_reason'] ?? null) && trim($item['exclusion_decision_reason']) !== '' ? trim($item['exclusion_decision_reason']) : null,
                ]);
                $enrolled[] = $personId;
            } catch (HttpException $e) {
                $refused[] = ['person_id' => $personId, 'reason' => EnrolmentService::explain($e)];
            }
        }

        return ['enrolled' => $enrolled, 'refused' => $refused];
    }
}
