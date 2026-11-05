<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Grades\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientBook;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;
use Throwable;

/**
 * Notifications des notes (T870) par le Messenger du socle (consentement, plages horaires), journal anti-doublon par événement et
 * destinataire (`se_grade_notifications`) :
 *  - NOT-04 « note publiée » : à l'élève et à ses garants, après la publication de l'épreuve au socle (appel de l'écran) ;
 *  - NOT-11 « saisie en retard » : aux enseignants de la matière dans la classe (emploi du temps des 7 prochains jours).
 * Un échec d'envoi ne remet jamais en cause l'opération.
 */
final class GradeNotifications
{
    public const TABLE = 'se_grade_notifications';
    public const NOT04 = 'NOT-04';
    public const NOT11 = 'NOT-11';
    public const TEMPLATE_NOT04 = 'sec.not04.grade_published';
    public const TEMPLATE_NOT11 = 'sec.not11.grades_late';
    public const PUBLISH = ['secondary.grades.lock', 'secondary.grades.validate'];
    private const WEEK = 7;

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly Messenger $messenger,
        private readonly PersonDirectory $people,
        private readonly EnrollmentDirectory $enrollments,
        private readonly ClassProfiles $classes,
        private readonly CoefficientBook $coefficients,
        private readonly TimetableDirectory $timetable,
        private readonly SecondaryClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $data class_id, assessment_id, subject_label, label, link?
     * @return array{notified: int, recipients: int}
     */
    public function published(Principal $principal, string $establishmentId, array $data): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::PUBLISH);
        $classId = (string) $data['class_id'];
        $this->access->assertWritableClass($principal, $perimeter, $classId, self::TABLE, (string) $data['assessment_id']);
        $tenantId = $principal->tenantId;
        $sent = 0;
        $recipients = 0;
        foreach ($this->people->findMany($tenantId, $this->enrollments->personIdsInStructure($tenantId, $classId)) as $learner) {
            $variables = [
                'child_name' => trim($learner->firstName . ' ' . $learner->lastName), 'subject' => (string) $data['subject_label'],
                'label' => (string) $data['label'], 'link' => (string) ($data['link'] ?? '/secondaire/mon-espace'),
            ];
            foreach ([$learner, ...$this->people->guarantorsOf($tenantId, $learner->id)] as $recipient) {
                ++$recipients;
                $sent += $this->send($tenantId, $establishmentId, self::NOT04, (string) $data['assessment_id'], $recipient->id, self::TEMPLATE_NOT04, $variables);
            }
        }
        $this->access->audit($principal, 'secondary.grades.published_notified', self::TABLE, (string) $data['assessment_id'], null, ['notified' => $sent]);

        return ['notified' => $sent, 'recipients' => $recipients];
    }

    /**
     * @param array<string, mixed> $data class_id, period_id, period_label, due_on, subject_ids
     * @return array{notified: int, without_teacher: list<string>}
     */
    public function late(Principal $principal, string $establishmentId, array $data): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::PUBLISH);
        $classId = (string) $data['class_id'];
        $this->access->assertWritableClass($principal, $perimeter, $classId, self::TABLE, $classId);
        $tenantId = $principal->tenantId;
        $class = $this->classes->find($tenantId, $establishmentId, $classId);
        $className = $class === null ? $classId : $class->label;
        $subjects = [];
        if ($class !== null) {
            foreach ($this->coefficients->forLevel($tenantId, $establishmentId, $class->academicYearId, $class->levelCode, $class->streamId) as $line) {
                if (in_array($line->subjectId, (array) $data['subject_ids'], true)) {
                    $subjects[$line->subjectId] = $line;
                }
            }
        }
        $teachers = [];
        $day = $this->clock->at();
        for ($i = 0; $i < self::WEEK; ++$i) {
            foreach ($this->timetable->slotsOn($tenantId, $establishmentId, $day->format('Y-m-d')) as $slot) {
                if ($slot->structureId !== $classId) {
                    continue;
                }
                foreach ($subjects as $id => $line) {
                    $label = mb_strtolower(trim($slot->subjectLabel));
                    if ($label === mb_strtolower($line->label) || $label === mb_strtolower($line->code)) {
                        $teachers[$id][$slot->teacherPersonId] = true;
                    }
                }
            }
            $day = $day->modify('+1 day');
        }
        $sent = 0;
        $without = [];
        foreach ($subjects as $id => $line) {
            if (!isset($teachers[$id])) {
                $without[] = (string) $id;
                continue;
            }
            foreach (array_keys($teachers[$id]) as $teacher) {
                $sent += $this->send($tenantId, $establishmentId, self::NOT11, $classId . '|' . $id . '|' . $data['period_id'], (string) $teacher, self::TEMPLATE_NOT11, [
                    'subject' => $line->label, 'class_name' => $className,
                    'period_label' => (string) ($data['period_label'] ?? $data['period_id']), 'due_on' => (string) $data['due_on'],
                ]);
            }
        }
        $this->access->audit($principal, 'secondary.grades.late_notified', self::TABLE, $classId, null, ['notified' => $sent, 'without_teacher' => $without]);

        return ['notified' => $sent, 'without_teacher' => $without];
    }

    /** @param array<string, scalar> $variables */
    private function send(string $tenantId, string $establishmentId, string $code, string $eventRef, string $recipient, string $template, array $variables): int
    {
        $where = ['notification_code' => $code, 'event_ref' => $eventRef, 'recipient_person_id' => $recipient];
        if ($this->store->count(self::TABLE, $tenantId, $establishmentId, $where) > 0) {
            return 0;
        }
        try {
            $result = $this->messenger->send($tenantId, $establishmentId, $recipient, $template, $variables, ['push']);
        } catch (Throwable $e) {
            $this->logger->warning('Notification ' . $code . ' non envoyée : ' . $e->getMessage(), ['event' => $eventRef]);

            return 0;
        }
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $where + [
            'id' => (string) new Ulid(), 'template_key' => $template, 'delivery_status' => $result->status, 'created_at' => $this->clock->now(),
        ]);

        return $result->status === DeliveryResult::BLOCKED ? 0 : 1;
    }
}
