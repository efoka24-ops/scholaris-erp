<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Application;

use Scholaris\Modules\Socle\Workflows\Contracts\Circuit;
use Scholaris\Modules\Socle\Workflows\Contracts\Lock;
use Scholaris\Modules\Socle\Workflows\Contracts\ResolvedCircuit;
use Scholaris\Modules\Socle\Workflows\Contracts\ResolvedStep;
use Scholaris\Modules\Socle\Workflows\Contracts\Step;
use Scholaris\Modules\Socle\Workflows\Infrastructure\WorkflowStore;

/**
 * Règle de résolution, pure : défaut de l'application, puis surcharges dans l'ordre plateforme, client, établissement. Un niveau
 * qui IMPOSE une étape (`enforced`) fige sa valeur : aucun niveau inférieur ne la change. Toute valeur stockée qui lèverait un
 * verrou (acteur hors liste, étape obligatoire désactivée, confirmation obligatoire levée) est ignorée : le verrou l'emporte
 * toujours, même sur une donnée écrite par une version antérieure.
 */
final class Resolution
{
    /** Du plus haut au plus bas. */
    public const LEVELS = [WorkflowStore::PLATFORM, WorkflowStore::CLIENT, WorkflowStore::ESTABLISHMENT];

    private function __construct()
    {
    }

    /**
     * @param array<string, array<string, mixed>> $docs niveau => document normalisé (`steps`)
     * @param list<string> $levels niveaux pris en compte, du plus haut au plus bas
     */
    public static function resolve(Circuit $circuit, array $docs, array $levels = self::LEVELS): ResolvedCircuit
    {
        $steps = [];
        $customised = false;
        foreach ($circuit->steps as $step) {
            $resolved = self::step($step, $docs, $levels);
            $steps[$step->key] = $resolved;
            if ($resolved->actor->key !== $step->defaultActor || !$resolved->enabled || $resolved->recentConfirmation !== $step->confirmation) {
                $customised = true;
            }
        }

        return new ResolvedCircuit($circuit, $steps, $customised);
    }

    /**
     * Niveau (platform ou client) qui impose l'étape parmi les niveaux donnés, sinon null.
     *
     * @param array<string, array<string, mixed>> $docs
     * @param list<string> $levels
     */
    public static function enforcedBy(string $stepKey, array $docs, array $levels): ?string
    {
        foreach ($levels as $level) {
            if (($docs[$level]['steps'][$stepKey]['enforced'] ?? false) === true) {
                return $level;
            }
        }

        return null;
    }

    /** Exiger la confirmation est toujours permis (plus strict) sauf verrou ; la lever exige qu'elle soit modifiable. */
    public static function confirmationAllowed(Step $step, bool $value): bool
    {
        return $value === $step->confirmation || $step->confirmationEditable() || ($value && !$step->hasLock(Lock::CONFIRMATION));
    }

    /**
     * @param array<string, array<string, mixed>> $docs
     * @param list<string> $levels
     */
    private static function step(Step $step, array $docs, array $levels): ResolvedStep
    {
        $actor = $step->defaultActor;
        $enabled = true;
        $confirmation = $step->confirmation;
        $source = 'default';
        $enforcedBy = null;
        foreach ($levels as $level) {
            $values = $docs[$level]['steps'][$step->key] ?? null;
            if (!is_array($values)) {
                continue;
            }
            if (is_string($values['actor'] ?? null) && $step->actor($values['actor']) !== null && ($step->actorEditable() || $values['actor'] === $step->defaultActor)) {
                $actor = $values['actor'];
            }
            if (is_bool($values['enabled'] ?? null) && ($values['enabled'] || $step->enabledEditable())) {
                $enabled = $values['enabled'];
            }
            if (is_bool($values['recent_confirmation'] ?? null) && self::confirmationAllowed($step, $values['recent_confirmation'])) {
                $confirmation = $values['recent_confirmation'];
            }
            $source = $level;
            if (($values['enforced'] ?? false) === true && $level !== WorkflowStore::ESTABLISHMENT) {
                $enforcedBy = $level;

                break;
            }
        }
        if ($step->confirmationMin) {
            $confirmation = true;
        }
        $resolvedActor = $step->actor($actor) ?? throw new \LogicException('Acteur introuvable : ' . $actor);

        return new ResolvedStep($step->key, $enabled, $resolvedActor, $confirmation, $source, $enforcedBy);
    }
}
