<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\Referential;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Domain\MentionRules;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;

/**
 * Résout le contexte d'une saisie ou d'une lecture (unité, classe, référentiel, élèves) et la règle de mention en vigueur pour l'école.
 * Tout passe par les contrats : PublicSchools (école, classes), Referential (Primaire publique), paramètres (Rules).
 */
final class ContextResolver
{
    public function __construct(
        private readonly Referential $referential,
        private readonly ClassRoster $classes,
        private readonly SchoolRegistry $schools,
        private readonly Rules $rules,
        private readonly PrimaryClock $clock,
    ) {
    }

    public function subsystem(string $tenantId, string $establishmentId): string
    {
        $school = $this->schools->find($tenantId, $establishmentId);
        if ($school === null) {
            throw HttpException::validation(['school' => ['La fiche de l\'école (sous-système francophone ou anglophone) doit être enregistrée avant l\'évaluation.']]);
        }

        return $school->subsystem === 'anglophone' ? 'anglophone' : 'francophone';
    }

    public function resolve(string $tenantId, string $establishmentId, string $unitId, string $structureId): UnitContext
    {
        $unit = $this->referential->unit($tenantId, $unitId) ?? throw HttpException::notFound('Unité d\'apprentissage introuvable.');
        $class = $this->classes->find($tenantId, $establishmentId, $structureId) ?? throw HttpException::notFound('Classe introuvable dans cette école.');
        if (!in_array($unit->levelCode, $class->levelCodes, true)) {
            throw HttpException::validation(['structure_id' => ['Cette unité concerne le niveau ' . $unit->levelCode . ', absent de la classe.']]);
        }
        $subsystem = $this->subsystem($tenantId, $establishmentId);
        $frames = $this->referential->framesFor($tenantId, $subsystem, $unit->levelCode, $unit->startsOn);

        return new UnitContext($unit, $class, $subsystem, $frames, $this->classes->pupilIdsIn($tenantId, $establishmentId, $structureId, $this->clock->today()));
    }

    public function mentionRules(string $tenantId, string $establishmentId): MentionRules
    {
        try {
            return MentionRules::from(
                $this->rules->int($tenantId, $establishmentId, 'public_primary.evaluation.occasions'),
                $this->rules->int($tenantId, $establishmentId, 'public_primary.evaluation.mastery_min_success'),
                $this->rules->array($tenantId, $establishmentId, 'public_primary.evaluation.mention_rule'),
                $this->rules->bool($tenantId, $establishmentId, 'public_primary.evaluation.perfection_only_if_a'),
            );
        } catch (\InvalidArgumentException $invalid) {
            throw new HttpException(500, 'Paramètres invalides', $invalid->getMessage());
        }
    }

    /** @return list<array{code: string, label_fr: string, label_en: string}> échelle qualitative d'affichage */
    public function scale(string $tenantId, string $establishmentId): array
    {
        $scale = [];
        foreach ($this->rules->array($tenantId, $establishmentId, 'public_primary.evaluation.scale') as $level) {
            if (is_array($level) && is_string($level['code'] ?? null)) {
                $scale[] = ['code' => $level['code'], 'label_fr' => (string) ($level['label_fr'] ?? $level['code']), 'label_en' => (string) ($level['label_en'] ?? $level['code'])];
            }
        }

        return $scale;
    }
}
