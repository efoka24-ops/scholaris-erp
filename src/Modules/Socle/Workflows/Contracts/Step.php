<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Contracts;

/**
 * Étape d'un circuit. Ce que l'administrateur peut ajuster :
 *  - QUI valide : `actors` (au moins un ; un seul = non modifiable) ;
 *  - l'étape elle-même : `optional` (peut être désactivée) ;
 *  - la confirmation récente : `confirmation` est la valeur par défaut ; `confirmationMin` = elle ne peut jamais être levée.
 * Tout ce qui n'est pas ajustable est expliqué par un Lock.
 */
final class Step
{
    /**
     * @param list<Actor> $actors
     * @param list<Lock> $locks
     */
    public function __construct(
        public readonly string $key,
        public readonly string $labelFr,
        public readonly string $labelEn,
        public readonly array $actors,
        public readonly string $defaultActor,
        public readonly bool $optional = false,
        public readonly bool $confirmation = false,
        public readonly bool $confirmationMin = false,
        public readonly array $locks = [],
    ) {
        if ($actors === [] || $this->actor($defaultActor) === null) {
            throw new \InvalidArgumentException('Étape ' . $key . ' : acteur par défaut absent de la liste.');
        }
        if ($confirmationMin && !$confirmation) {
            throw new \InvalidArgumentException('Étape ' . $key . ' : une confirmation obligatoire doit aussi être exigée par défaut.');
        }
    }

    public function actor(string $key): ?Actor
    {
        foreach ($this->actors as $actor) {
            if ($actor->key === $key) {
                return $actor;
            }
        }

        return null;
    }

    public function hasLock(string $aspect): bool
    {
        foreach ($this->locks as $lock) {
            if ($lock->aspect === $aspect) {
                return true;
            }
        }

        return false;
    }

    public function actorEditable(): bool
    {
        return count($this->actors) > 1 && !$this->hasLock(Lock::ACTOR);
    }

    public function enabledEditable(): bool
    {
        return $this->optional && !$this->hasLock(Lock::ENABLED);
    }

    public function confirmationEditable(): bool
    {
        return !$this->confirmationMin && !$this->hasLock(Lock::CONFIRMATION);
    }
}
