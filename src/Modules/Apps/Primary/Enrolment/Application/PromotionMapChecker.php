<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Enrolment\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Contrôle de cohérence de la table de passage `people.promotion.next_structure` (classe -> classe suivante), EX-PRI-46 :
 * chaque classe d'un niveau non final a une classe suivante d'un niveau de rang supérieur ; une classe du niveau final n'en a pas.
 * Lecture seule : l'école écrit la table par PUT /settings (socle).
 */
final class PromotionMapChecker
{
    public const SETTING = 'people.promotion.next_structure';
    public const READ = ['primary.settings.manage', 'primary.referential.manage', 'primary.promotion.decide', 'primary.referential.read'];

    public function __construct(
        private readonly OrgUnitTree $tree,
        private readonly LevelDirectory $levels,
        private readonly SettingsReader $settings,
        private readonly Access $access,
    ) {
    }

    /** @return array{ok: bool, errors: list<array{code: string, class_structure_id: string, target_structure_id: ?string}>, classes: int} */
    public function check(Principal $principal, string $establishmentId): array
    {
        $this->access->require($principal, $establishmentId, self::READ);
        $t = $principal->tenantId;
        $raw = $this->settings->value($t, $establishmentId, self::SETTING, []);
        $map = is_array($raw) ? $raw : [];
        $rank = [];
        foreach ($this->levels->all($t, $establishmentId) as $profile) {
            $rank[$profile->levelStructureId] = $profile;
        }
        $errors = [];
        $classes = 0;
        foreach ($rank as $levelId => $profile) {
            foreach ($this->tree->structureDescendantsOf($t, $establishmentId, $levelId) as $classId) {
                if ($classId === $levelId) {
                    continue;
                }
                ++$classes;
                $target = isset($map[$classId]) && is_string($map[$classId]) && $map[$classId] !== '' ? $map[$classId] : null;
                if ($profile->isFinalLevel) {
                    if ($target !== null) {
                        $errors[] = ['code' => 'final_level_has_next', 'class_structure_id' => $classId, 'target_structure_id' => $target];
                    }
                    continue;
                }
                if ($target === null) {
                    $errors[] = ['code' => 'missing_next_class', 'class_structure_id' => $classId, 'target_structure_id' => null];
                    continue;
                }
                $targetLevel = $this->tree->levelOf($t, $establishmentId, $target);
                $targetInfo = $this->tree->structure($t, $establishmentId, $target);
                if ($targetInfo === null || $targetInfo->kind !== 'class' || $targetLevel === null || !isset($rank[$targetLevel]) || $rank[$targetLevel]->sortOrder <= $profile->sortOrder) {
                    $errors[] = ['code' => 'invalid_next_class', 'class_structure_id' => $classId, 'target_structure_id' => $target];
                }
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'classes' => $classes];
    }
}
