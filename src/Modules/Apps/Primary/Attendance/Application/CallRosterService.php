<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Attendance\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\ClassRoster;

/**
 * Liste d'appel d'une classe pour l'appel par demi-journée (défaut FIX-PRI 1) : élèves inscrits (actifs ou sous condition) et leurs
 * noms. Le périmètre est celui du rôle dans l'école primaire (affectation à la classe, au niveau ou à tout l'établissement), et non
 * les affectations de service ou l'emploi du temps du socle : un maître titulaire sans créneau voit sa classe. Hors périmètre : 404.
 */
final class CallRosterService
{
    public const READ = ['attendance.take', 'attendance.read'];

    public function __construct(private readonly Access $access, private readonly ClassRoster $classes)
    {
    }

    /** @return array{class: array{id: string, name: string}, learners: list<array{person_id: string, name: string}>} */
    public function roster(Principal $principal, string $establishmentId, string $classId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->access->assertReadableClass($perimeter, $classId);
        $context = $this->classes->resolve($principal->tenantId, $establishmentId, $classId);
        $names = $this->classes->names($principal->tenantId, $context['learner_ids']);
        $learners = array_map(static fn (string $id): array => ['person_id' => $id, 'name' => $names[$id] ?? $id], $context['learner_ids']);
        usort($learners, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return ['class' => ['id' => $classId, 'name' => $context['class']->name], 'learners' => $learners];
    }
}
