<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\GroupInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\LevelInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\ProgramInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\StructureLookup;
use Scholaris\Modules\Apps\Higher\Support\ScopedBySchool;

/**
 * Résout les CODES de filière (« GE » ou « ECOLE/GE » si le code se répète entre écoles), de niveau et de groupe d'une ligne, et vérifie
 * que la filière est dans le périmètre de la personne qui importe (RG-ORG-2). Les résultats sont mémorisés pour la passe.
 */
final class StructureResolver
{
    public function __construct(
        private readonly StructureLookup $lookup,
        private readonly AcademicDirectory $academic,
        private readonly ScopedBySchool $scoped,
    ) {
    }

    /**
     * @return array{program: ?ProgramInfo, level: ?LevelInfo, group: ?GroupInfo, errors: list<string>}
     */
    public function resolve(RunContext $context, string $programCode, string $levelCode, string $groupCode = ''): array
    {
        $cacheKey = 'structure|' . $programCode . '|' . $levelCode . '|' . $groupCode;
        if (isset($context->cache[$cacheKey])) {
            /** @var array{program: ?ProgramInfo, level: ?LevelInfo, group: ?GroupInfo, errors: list<string>} $cached */
            $cached = $context->cache[$cacheKey];

            return $cached;
        }
        $errors = [];
        $program = $level = $group = null;
        $schoolCode = null;
        $code = trim($programCode);
        if (str_contains($code, '/')) {
            [$schoolCode, $code] = array_map('trim', explode('/', $code, 2));
        }
        if ($code === '') {
            $errors[] = 'Filière manquante.';
        } else {
            $found = $this->lookup->programsByCode($context->tenantId, $context->establishmentId, $code, $schoolCode);
            if ($found === []) {
                $errors[] = 'Filière « ' . $programCode . ' » inconnue : vérifiez le code dans le paramétrage académique.';
            } elseif (count($found) > 1) {
                $errors[] = 'Le code de filière « ' . $programCode . ' » existe dans plusieurs écoles : écrivez ÉCOLE/CODE.';
            } else {
                $program = $found[0];
            }
        }
        if ($program !== null) {
            foreach ($this->academic->levelsOf($context->tenantId, $context->establishmentId, $program->id) as $candidate) {
                if (strcasecmp($candidate->code, trim($levelCode)) === 0) {
                    $level = $candidate;
                }
            }
            if ($level === null) {
                $errors[] = 'Niveau « ' . $levelCode . ' » inconnu dans la filière ' . $program->code . '.';
            }
        }
        if ($level !== null && trim($groupCode) !== '') {
            foreach ($this->academic->groupsOf($context->tenantId, $context->establishmentId, $level->id) as $candidate) {
                if (strcasecmp($candidate->code, trim($groupCode)) === 0) {
                    $group = $candidate;
                }
            }
            if ($group === null) {
                $errors[] = 'Groupe « ' . $groupCode . ' » inconnu dans le niveau ' . $level->code . '.';
            }
        }
        if ($program !== null && $errors === []) {
            $covered = $this->scoped->covers($context->perimeter, [
                'school' => $program->schoolId, 'department' => $program->departmentId, 'program' => $program->id,
                'level' => $level->id, 'group' => $group?->id,
            ]);
            if (!$covered) {
                $errors[] = 'La filière ' . $program->code . ' est hors de votre périmètre : ligne refusée.';
            }
        }

        return $context->cache[$cacheKey] = ['program' => $program, 'level' => $level, 'group' => $group, 'errors' => $errors];
    }
}
