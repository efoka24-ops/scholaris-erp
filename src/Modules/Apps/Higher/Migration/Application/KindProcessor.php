<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application;

/** Traitement d'un jeu de données de la reprise : contrôle sans écriture, application, annulation. */
interface KindProcessor
{
    public function kind(): string;

    /**
     * Contrôle une ligne SANS RIEN ÉCRIRE.
     *
     * @param array<string, string> $row
     */
    public function check(RunContext $context, int $line, array $row): RowVerdict;

    /**
     * Écrit les lignes applicables (toutes valides) ; renvoie, par numéro de ligne, les références des objets créés ou repris.
     *
     * @param list<RowVerdict> $verdicts
     * @return array<int, array<string, mixed>>
     */
    public function apply(RunContext $context, array $verdicts): array;

    /**
     * Annule les objets d'une ligne appliquée (refus 409 si une donnée en dépend).
     *
     * @param array<string, mixed> $refs
     */
    public function revert(RunContext $context, array $refs, string $reason): void;

    /** Motif pour lequel ce jeu ne peut pas être annulé, null s'il le peut. */
    public function revertRefusal(): ?string;
}
