<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceRecorder;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\BirthInfo;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\ConsentRecorder;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\FoodAllergies;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;
use Scholaris\Modules\Socle\People\Contracts\MediaStore;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\StructureEnrollments;

/**
 * Monde fictif en mémoire des tests de la Maternelle privée : personnes, parents, enfants inscrits en section, autorisés. Remplace
 * les modules People, Identity (lien compte-personne) et Attendance (autorisés) derrière leurs contrats.
 */
final class NurseryWorld
{
    public readonly WorldEnrollments $enrollments;
    public readonly WorldPeople $people;
    public readonly WorldPickups $pickups;
    public readonly WorldAttendance $attendance;
    public readonly WorldRoles $roles;
    public readonly WorldMedia $media;

    public function __construct()
    {
        $this->enrollments = new WorldEnrollments();
        $this->people = new WorldPeople($this->enrollments);
        $this->pickups = new WorldPickups($this->enrollments);
        $this->attendance = new WorldAttendance();
        $this->roles = new WorldRoles();
        $this->media = new WorldMedia();
    }

    /** @return array<string, object> */
    public function bindings(): array
    {
        return [
            PersonDirectory::class => $this->people,
            BirthInfo::class => $this->people,
            FoodAllergies::class => $this->people,
            ConsentChecker::class => $this->people,
            ConsentRecorder::class => $this->people,
            UserPersonLink::class => $this->people,
            LearnerWriter::class => $this->people,
            EnrollmentDirectory::class => $this->enrollments,
            StructureEnrollments::class => $this->enrollments,
            AuthorizedPickupRegistry::class => $this->pickups,
            AttendanceRecorder::class => $this->attendance,
            DailyPresence::class => $this->attendance,
            RoleDirectory::class => $this->roles,
            MediaStore::class => $this->media,
        ];
    }

    /**
     * Enfant fictif inscrit dans la section, avec un parent garant (compte « parent-<enfant> » si $account).
     *
     * @return array{child: string, parent: string}
     */
    public function child(string $tenantId, string $establishmentId, string $sectionId, string $firstName, string $lastName = 'Test', ?string $birthDate = '2023-03-10', bool $account = true): array
    {
        $child = 'c-' . strtolower($firstName) . '-' . substr(md5($tenantId . $establishmentId . $sectionId . $firstName), 0, 6);
        $parent = 'p-' . $child;
        $this->people->add($tenantId, $child, $firstName, $lastName, $birthDate);
        $this->people->add($tenantId, $parent, 'Parent de ' . $firstName, $lastName, null, '+237600000000');
        $this->people->link($tenantId, $parent, $child);
        $this->enrollments->add($tenantId, 'en-' . $child, $child, $establishmentId, $sectionId);
        if ($account) {
            $this->people->account($tenantId, 'parent-' . $child, $parent);
        }

        return ['child' => $child, 'parent' => $parent];
    }
}
