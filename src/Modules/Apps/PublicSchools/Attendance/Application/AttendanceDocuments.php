<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;

/**
 * PDF du lot P1 émis par le module Documents du socle (numéro, modèles FR/EN, sans QR selon le catalogue du CDC) :
 *  - DOC-06 cahier d'appel mensuel d'une classe (données de AttendanceRegisterService, une colonne par demi-journée appelée) ;
 *  - DOC-22 synthèse mensuelle de présence des enseignants (données de TeacherPresenceService::monthlySummary).
 * Le document est rattaché au directeur qui l'émet (document collectif, aucun document d'élève).
 */
final class AttendanceDocuments
{
    public const REGISTER_TYPE = 'pub_doc06_register';
    public const PRESENCE_TYPE = 'pub_doc22_presence';

    public function __construct(
        private readonly AttendanceRegisterService $registers,
        private readonly TeacherPresenceService $presence,
        private readonly ClassRoster $classes,
        private readonly StaffDirectory $staff,
        private readonly PersonDirectory $people,
        private readonly DocumentIssuer $documents,
        private readonly UserPersonLink $users,
    ) {
    }

    /** @return array{id: string, number: string} */
    public function register(Principal $principal, string $establishmentId, string $structureId, string $month, string $locale): array
    {
        $tenantId = $principal->tenantId;
        $data = $this->registers->month($tenantId, $establishmentId, $structureId, $month);
        $en = $locale === 'en';
        $columns = [];
        foreach ($data['days'] as $day) {
            foreach (['morning' => $en ? 'Mo' : 'M', 'afternoon' => $en ? 'Af' : 'AM'] as $part => $short) {
                if ($day[$part]) {
                    $columns[] = [$day['date'], $part, substr($day['date'], 8, 2) . $short];
                }
            }
        }
        $lines = [sprintf('%-28s %s %s', $en ? 'Pupil' : 'Élève', implode(' ', array_map(static fn (array $c): string => str_pad($c[2], 4), $columns)), $en ? 'Abs.' : 'Abs.')];
        $total = 0;
        foreach ($data['pupils'] as $pupil) {
            $marks = array_map(static fn (array $c): string => str_pad((string) ($pupil['marks'][$c[0]][$c[1]] ?? '-'), 4), $columns);
            $lines[] = sprintf('%-28s %s %4d', mb_substr((string) $pupil['name'], 0, 28), implode(' ', $marks), $pupil['absent_half_days']);
            $total += (int) $pupil['absent_half_days'];
        }
        $class = $this->classes->find($tenantId, $establishmentId, $structureId);
        $teacher = '';
        if ($class !== null && $class->homeroomStaffId !== null) {
            foreach ($this->staff->teacherIdsIn($tenantId, $establishmentId) as $personId) {
                if ($this->staff->find($tenantId, $personId)?->staffId === $class->homeroomStaffId) {
                    $teacher = $this->people->find($tenantId, $personId)?->fullName() ?? '';
                }
            }
        }
        $document = $this->documents->issue($tenantId, $establishmentId, self::REGISTER_TYPE, $this->subject($principal), [
            'class_name' => (string) $data['class']['label'], 'month_label' => $month, 'teacher_name' => $teacher,
            'legend' => $en ? 'P present, A absent, - not called; Mo morning, Af afternoon.' : 'P présent, A absent, - sans appel ; M matin, AM après-midi.',
            'register_block' => implode("\n", $lines),
            'totals_line' => ($en ? 'Pupils: ' : 'Élèves : ') . count($data['pupils']) . ($en ? ' · half-days called: ' : ' · demi-journées appelées : ') . count($columns) . ($en ? ' · absences: ' : ' · absences : ') . $total,
        ], $principal->userId, $en ? 'en' : 'fr');

        return ['id' => $document->id, 'number' => $document->number];
    }

    /** @return array{id: string, number: string} */
    public function presence(Principal $principal, string $establishmentId, string $month, string $locale): array
    {
        $tenantId = $principal->tenantId;
        $summary = $this->presence->monthlySummary($tenantId, $establishmentId, $month);
        $en = $locale === 'en';
        $names = [];
        foreach ($this->staff->teacherIdsIn($tenantId, $establishmentId) as $personId) {
            $info = $this->staff->find($tenantId, $personId);
            if ($info !== null && $info->staffId !== null) {
                $names[$info->staffId] = $this->people->find($tenantId, $personId)?->fullName() ?? $personId;
            }
        }
        $lines = [sprintf('%-28s %8s %8s %10s %8s %9s %8s', $en ? 'Teacher' : 'Enseignant', $en ? 'Present' : 'Présent', 'Absent', 'Permission', 'Mission', $en ? 'Checked' : 'Contrôlé', $en ? 'Gaps' : 'Écarts')];
        foreach ($summary['teachers'] as $line) {
            $lines[] = sprintf('%-28s %8d %8d %10d %8d %9d %8d', mb_substr($names[(string) $line['staff_id']] ?? (string) $line['staff_id'], 0, 28), $line['present'], $line['absent'], $line['permission'], $line['mission'], $line['checked'], $line['discrepancies']);
        }
        $document = $this->documents->issue($tenantId, $establishmentId, self::PRESENCE_TYPE, $this->subject($principal), [
            'month_label' => $month, 'presence_block' => implode("\n", $lines), 'uncovered_days' => (string) $summary['uncovered_days'],
        ], $principal->userId, $en ? 'en' : 'fr');

        return ['id' => $document->id, 'number' => $document->number];
    }

    private function subject(Principal $principal): string
    {
        return $this->users->personIdForUser($principal->tenantId, $principal->userId)
            ?? throw HttpException::validation(['user' => ['Compte sans personne liée : émission impossible.']]);
    }
}
