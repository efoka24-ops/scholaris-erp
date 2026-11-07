<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Workflows\Contracts\Circuit;
use Scholaris\Modules\Socle\Workflows\Contracts\Lock;
use Scholaris\Modules\Socle\Workflows\Contracts\Step;
use Scholaris\Modules\Socle\Workflows\Infrastructure\WorkflowStore;

/**
 * Validation STRICTE d'une surcharge : refuse (422, explication en français) tout ce qui lèverait un verrou du circuit, tout champ
 * inconnu, et tout ce qu'un niveau supérieur a imposé. Rend le document normalisé `{steps: {étape: {actor?, enabled?,
 * recent_confirmation?, enforced?}}}`.
 */
final class OverrideValidator
{
    private const FIELDS = ['actor', 'enabled', 'recent_confirmation', 'enforced'];
    private const LEVEL_LABEL = ['platform' => 'la plateforme', 'client' => 'l\'administration de votre organisation'];

    /**
     * @param array<string, array<string, mixed>> $above documents (normalisés) des niveaux supérieurs, par niveau
     * @return array{steps: array<string, array<string, bool|string>>}
     */
    public function normalise(Circuit $circuit, mixed $raw, string $level, array $above): array
    {
        $errors = [];
        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            throw HttpException::validation(['steps' => ['Corps attendu : {"steps": {"<étape>": {"actor": ..., "enabled": ..., "recent_confirmation": ...}}}.']]);
        }
        foreach (array_keys($raw) as $field) {
            if ($field !== 'steps') {
                $errors[(string) $field] = ['Champ inconnu.'];
            }
        }
        $steps = $raw['steps'] ?? null;
        if (!is_array($steps) || $steps === [] || array_is_list($steps)) {
            $errors['steps'] = ['Indiquez au moins une étape à ajuster.'];
            throw HttpException::validation($errors);
        }
        $out = [];
        $higher = array_values(array_filter(Resolution::LEVELS, static fn (string $l): bool => array_search($l, Resolution::LEVELS, true) < array_search($level, Resolution::LEVELS, true)));
        foreach ($steps as $key => $values) {
            $key = (string) $key;
            $step = $circuit->step($key);
            if ($step === null) {
                $errors['steps.' . $key] = ['Étape inconnue pour ce circuit.'];
                continue;
            }
            $imposed = Resolution::enforcedBy($key, $above, $higher);
            if ($imposed !== null) {
                $errors['steps.' . $key] = ['Cette étape est imposée par ' . (self::LEVEL_LABEL[$imposed] ?? $imposed) . ' : un réglage inférieur ne peut pas la modifier.'];
                continue;
            }
            if (!is_array($values) || $values === [] || array_is_list($values)) {
                $errors['steps.' . $key] = ['Objet attendu avec au moins un champ parmi actor, enabled, recent_confirmation.'];
                continue;
            }
            $clean = $this->step($step, $values, $level, $errors);
            if ($clean !== []) {
                $out[$key] = $clean;
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($out === []) {
            throw HttpException::validation(['steps' => ['Aucun ajustement à enregistrer.']]);
        }

        return ['steps' => $out];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     * @return array<string, bool|string>
     */
    private function step(Step $step, array $values, string $level, array &$errors): array
    {
        $path = 'steps.' . $step->key;
        $clean = [];
        foreach (array_keys($values) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $errors[$path . '.' . $field] = ['Champ inconnu (attendu : actor, enabled, recent_confirmation).'];
            }
        }
        if (array_key_exists('actor', $values)) {
            $actor = $values['actor'];
            if (!is_string($actor) || $step->actor($actor) === null) {
                $errors[$path . '.actor'] = ['Fonction non autorisée pour cette étape. Choix possibles : ' . implode(', ', array_map(static fn ($a): string => $a->key, $step->actors)) . '.'];
            } elseif ($actor !== $step->defaultActor && !$step->actorEditable()) {
                $errors[$path . '.actor'] = [$this->why($step, Lock::ACTOR, 'Cette étape ne peut être validée que par la fonction prévue par l\'application.')];
            } else {
                $clean['actor'] = $actor;
            }
        }
        if (array_key_exists('enabled', $values)) {
            if (!is_bool($values['enabled'])) {
                $errors[$path . '.enabled'] = ['Booléen attendu.'];
            } elseif ($values['enabled'] === false && !$step->enabledEditable()) {
                $errors[$path . '.enabled'] = [$this->why($step, Lock::ENABLED, 'Cette étape est obligatoire : elle ne peut pas être désactivée.')];
            } else {
                $clean['enabled'] = $values['enabled'];
            }
        }
        if (array_key_exists('recent_confirmation', $values)) {
            $value = $values['recent_confirmation'];
            if (!is_bool($value)) {
                $errors[$path . '.recent_confirmation'] = ['Booléen attendu.'];
            } elseif (!Resolution::confirmationAllowed($step, $value)) {
                $errors[$path . '.recent_confirmation'] = [$this->why($step, Lock::CONFIRMATION, 'La confirmation récente est obligatoire pour cette étape : elle ne peut pas être levée.')];
            } else {
                $clean['recent_confirmation'] = $value;
            }
        }
        if (array_key_exists('enforced', $values)) {
            if (!is_bool($values['enforced'])) {
                $errors[$path . '.enforced'] = ['Booléen attendu.'];
            } elseif ($values['enforced'] && $level === WorkflowStore::ESTABLISHMENT) {
                $errors[$path . '.enforced'] = ['Seule la plateforme (pour un client) peut imposer une étape à un niveau inférieur.'];
            } elseif ($values['enforced']) {
                if (array_intersect_key($clean, ['actor' => 1, 'enabled' => 1, 'recent_confirmation' => 1]) === []) {
                    $errors[$path . '.enforced'] = ['Précisez la valeur imposée (actor, enabled ou recent_confirmation).'];
                } else {
                    $clean['enforced'] = true;
                }
            }
        }

        return $clean;
    }

    private function why(Step $step, string $aspect, string $fallback): string
    {
        foreach ($step->locks as $lock) {
            if ($lock->aspect === $aspect) {
                return $lock->reasonFr;
            }
        }

        return $fallback;
    }
}
