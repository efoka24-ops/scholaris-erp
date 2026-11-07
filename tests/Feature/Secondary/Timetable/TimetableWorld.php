<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Timetable;

use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Billing\RecordingMessenger;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * Base des tests de l'emploi du temps et du cahier de textes : personnes, inscriptions et messages en mémoire, comptes liés à des
 * personnes, établissement ouvert avec un niveau, des matières à volume horaire et des classes paramétrées. Données fictives.
 */
abstract class TimetableWorld extends SecondaryTestCase implements UserPersonLink
{
    protected const YEAR = 'annee-2026';
    protected const FROM = '2026-09-07';
    protected const SETUP = ['secondary.settings.manage', 'secondary.profile.manage', 'secondary.profile.read'];
    protected const CENSOR = ['secondary.timetable.manage', 'secondary.timetable.read', 'secondary.syllabus.read', 'secondary.syllabus.visa'];

    protected InMemoryPeople $people;
    protected InMemoryEnrollments $enrollments;
    protected RecordingMessenger $messenger;

    /** @var array<string, string> « client|utilisateur » => personne */
    private array $links = [];

    protected function setUp(): void
    {
        $this->people = new InMemoryPeople();
        $this->enrollments = new InMemoryEnrollments();
        $this->messenger = new RecordingMessenger();
        $this->bootSecondary([PersonDirectory::class => $this->people, EnrollmentDirectory::class => $this->enrollments, Messenger::class => $this->messenger, UserPersonLink::class => $this]);
    }

    public function personIdForUser(string $tenantId, string $userId): ?string
    {
        return $this->links[$tenantId . '|' . $userId] ?? null;
    }

    protected function link(string $tenantId, string $userId, string $personId): void
    {
        $this->links[$tenantId . '|' . $userId] = $personId;
    }

    protected function q(string $sql): \PDOStatement
    {
        $statement = $this->pdo()->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return $statement;
    }

    protected function teacher(string $id, string $tenantId = self::TA): string
    {
        $this->people->add($tenantId, $id, 'Prof', ucfirst($id));

        return $id;
    }

    /**
     * Établissement ouvert, niveau 6E (exemples), matières avec volumes horaires, classes « 6e A »... de l'année.
     *
     * @param array<string, int> $hours code de matière => heures par semaine (valeurs fictives)
     * @param array<string, mixed>|null $openBody corps d'ouverture par la route (sinon profil de type copié tel quel)
     * @return array{school: string, classes: list<string>, subjects: array<string, string>}
     */
    protected function world(int $classCount, array $hours, string $code = 'leg', string $tenantId = self::TA, ?array $openBody = null): array
    {
        if ($openBody === null) {
            $school = $this->factory->opened($tenantId, $code);
            $this->grant($tenantId, self::ADMIN, $school, [...self::SETUP, ...self::CENSOR]);
        } else {
            $school = $this->factory->establishment($tenantId, $code);
            $this->grant($tenantId, self::ADMIN, $school, [...self::SETUP, ...self::CENSOR]);
            $this->ok('POST', '/secondary/setup/profile/open', $openBody, self::ADMIN, $tenantId, $school);
        }
        $levels = array_column($this->ok('POST', '/secondary/setup/levels/examples', [], self::ADMIN, $tenantId, $school), null, 'code');
        $subjects = [];
        foreach ($hours as $subject => $weekly) {
            $created = $this->ok('POST', '/secondary/setup/subjects', ['code' => $subject, 'label' => 'Matière ' . $subject], self::ADMIN, $tenantId, $school);
            $subjects[$subject] = (string) $created['id'];
            $this->ok('POST', '/secondary/setup/coefficients', [
                'academic_year_id' => self::YEAR, 'level_code' => '6E', 'subject_ref' => $created['id'], 'coefficient' => 2, 'weekly_hours' => $weekly,
                'subject_role' => 'official', 'origin' => 'example', 'valid_from' => self::FROM, 'reason' => 'Valeur d\'exemple de test',
            ], self::ADMIN, $tenantId, $school);
        }
        $classes = [];
        for ($i = 0; $i < $classCount; ++$i) {
            $class = $this->ok('POST', '/secondary/setup/classes', ['label' => '6e ' . chr(65 + $i), 'level_id' => $levels['6E']['id'], 'academic_year_id' => self::YEAR], self::ADMIN, $tenantId, $school);
            $classes[] = (string) $class['structure_id'];
        }

        return ['school' => $school, 'classes' => $classes, 'subjects' => $subjects];
    }

    /** @param list<array{0: int, 1: string, 2: string}> $bands jour, début, fin */
    protected function bands(string $school, array $bands, string $tenantId = self::TA): void
    {
        $list = array_map(static fn (array $b): array => ['weekday' => $b[0], 'start_time' => $b[1], 'end_time' => $b[2]], $bands);
        $this->ok('PUT', '/secondary/timetable/bands', ['academic_year_id' => self::YEAR, 'bands' => $list], self::ADMIN, $tenantId, $school);
    }

    /**
     * Lundi à vendredi, plages d'une heure de 07:30 à 13:30 (valeurs fictives de test).
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    protected static function week(int $perDay = 6): array
    {
        $bands = [];
        for ($day = 1; $day <= 5; ++$day) {
            for ($h = 0; $h < $perDay; ++$h) {
                $bands[] = [$day, sprintf('%02d:30', 7 + $h), sprintf('%02d:30', 8 + $h)];
            }
        }

        return $bands;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function assign(string $school, string $class, string $subject, string $teacher, array $extra = [], string $tenantId = self::TA): array
    {
        return $this->ok('POST', '/secondary/timetable/assignments', $extra + [
            'academic_year_id' => self::YEAR, 'class_id' => $class, 'subject_ref' => $subject, 'teacher_person_id' => $teacher,
            'teacher_kind' => 'permanent', 'room_kind' => 'classroom',
        ], self::ADMIN, $tenantId, $school);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function pose(string $school, string $assignment, int $weekday, string $start, string $end, array $extra = []): array
    {
        return $this->call('POST', '/secondary/timetable/entries', $extra + [
            'assignment_id' => $assignment, 'weekday' => $weekday, 'start_time' => $start, 'end_time' => $end, 'valid_from' => self::FROM,
        ], self::ADMIN, self::TA, $school);
    }
}
