<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Contracts;

/**
 * Circuit de validation déclaré par une application : étapes ordonnées et règles de sécurité (invariants) qui valent quel que
 * soit le réglage. `establishmentTypes` vide = tous les établissements.
 */
final class Circuit
{
    /**
     * @param list<Step> $steps
     * @param list<Lock> $invariants verrous de circuit (aspect circuit), jamais modifiables
     * @param list<string> $establishmentTypes
     */
    public function __construct(
        public readonly string $application,
        public readonly string $key,
        public readonly string $labelFr,
        public readonly string $labelEn,
        public readonly array $steps,
        public readonly array $invariants = [],
        public readonly array $establishmentTypes = [],
    ) {
        if ($steps === []) {
            throw new \InvalidArgumentException('Circuit ' . $key . ' sans étape.');
        }
        if (preg_match('/^[a-z][a-z0-9_]{1,30}$/', $application) !== 1 || preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) !== 1) {
            throw new \InvalidArgumentException('Clé de circuit invalide : ' . $application . '/' . $key);
        }
        $seen = [];
        foreach ($steps as $step) {
            if (isset($seen[$step->key])) {
                throw new \InvalidArgumentException('Étape en double : ' . $step->key);
            }
            $seen[$step->key] = true;
        }
    }

    public function step(string $key): ?Step
    {
        foreach ($this->steps as $step) {
            if ($step->key === $key) {
                return $step;
            }
        }

        return null;
    }

    public function appliesTo(string $establishmentType): bool
    {
        return $this->establishmentTypes === [] || in_array($establishmentType, $this->establishmentTypes, true);
    }
}
