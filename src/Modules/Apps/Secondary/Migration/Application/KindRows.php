<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Migration\Application;

use Scholaris\Core\Security\Principal;

/**
 * Traitement d'un jeu de reprise. `plan` contrôle chaque ligne SANS RIEN ÉCRIRE et rend son verdict ; `apply` écrit une ligne
 * valide par le service qui possède les données (Setup, Enrolment, Billing du socle) et rend l'objet créé.
 *
 * Un jeu `atomic` s'applique en une transaction (tout ou rien) et sa simulation rejoue l'application dans une transaction
 * annulée : la simulation voit alors les mêmes refus que l'application (règles du socle comprises).
 *
 * @phpstan-type Line array{line: int, status: string, key: string, label: string, messages: list<string>, plan: array<string, mixed>|null}
 */
interface KindRows
{
    /** @return list<string> permissions exigées (l'une d'elles) pour simuler et appliquer ce jeu */
    public function permissions(): array;

    public function atomic(): bool;

    /**
     * @param array<int, array<string, string>> $rows lignes du fichier par numéro de ligne
     * @return list<Line>
     */
    public function plan(Principal $principal, string $establishmentId, string $academicYearId, ?string $entryDate, array $rows): array;

    /**
     * @param array<string, mixed> $plan
     * @return string référence de l'objet créé
     */
    public function apply(Principal $principal, string $establishmentId, string $academicYearId, array $plan): string;
}
