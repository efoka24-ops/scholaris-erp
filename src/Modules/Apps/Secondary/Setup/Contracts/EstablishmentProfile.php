<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Contracts;

/**
 * Profil d'un établissement secondaire tel que les autres sous-modules le voient (lecture seule). Deux sources :
 *  - `stored = true` : copie faite à l'ouverture (`se_establishment_profiles`), seule source après l'ouverture (RG-SEC-2) ;
 *  - `stored = false` : valeurs de départ du profil de type (`secondary.type_profiles.<code>`), avant l'ouverture.
 * Les valeurs de départ sont « à confirmer » (Q18, Q28) : `confirmation` le dit. Aucun code ne doit tester `typeCode` (D9) : on
 * interroge les cycles, les enseignements et les modules.
 */
final class EstablishmentProfile
{
    /**
     * @param list<string> $ownershipOptions propriétés possibles (public, private_secular, private_faith)
     * @param list<string> $cycles first, second
     * @param list<string> $teachingKinds general, technical
     * @param list<string> $sections fr, en
     * @param list<string> $modules modules actifs (Modules::ALL)
     * @param list<string> $readOnlyModules modules coupés après usage : données consultables, aucune écriture
     * @param list<string> $defaultModules modules actifs par défaut pour le type (assistant de profil)
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        /** code du type (affichage et journal seulement) */
        public readonly string $typeCode,
        public readonly int $profileVersion,
        public readonly bool $stored,
        public readonly ?string $ownership,
        public readonly array $ownershipOptions,
        public readonly array $cycles,
        public readonly array $teachingKinds,
        public readonly array $sections,
        public readonly array $modules,
        public readonly array $readOnlyModules,
        public readonly array $defaultModules,
        /** vocabulaire du chef d'établissement : proviseur, principal, ou null (à choisir) */
        public readonly ?string $headTitle,
        /** to_confirm tant que le profil de type n'est pas validé par le client */
        public readonly string $confirmation,
    ) {
    }

    public function isActive(string $module): bool
    {
        return in_array($module, $this->modules, true);
    }

    public function isReadOnly(string $module): bool
    {
        return !$this->isActive($module) && in_array($module, $this->readOnlyModules, true);
    }

    public function offersCycle(string $cycle): bool
    {
        return in_array($cycle, $this->cycles, true);
    }

    public function offersTeaching(string $teachingKind): bool
    {
        return in_array($teachingKind, $this->teachingKinds, true);
    }

    /** Un module ne s'active que si le profil offre le cycle qu'il exige (second cycle, orientation). */
    public function canActivate(string $module): bool
    {
        $cycle = Modules::REQUIRED_CYCLE[$module] ?? null;

        return in_array($module, Modules::ALL, true) && ($cycle === null || $this->offersCycle($cycle));
    }

    /** État d'un module : active, read_only, inactive ou unavailable (cycle absent du profil). */
    public function moduleState(string $module): string
    {
        return match (true) {
            $this->isActive($module) => 'active',
            $this->isReadOnly($module) => 'read_only',
            !$this->canActivate($module) => 'unavailable',
            default => 'inactive',
        };
    }
}
