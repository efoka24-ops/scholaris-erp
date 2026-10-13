<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Catalogue de programme (D9) : une application projette ses UE et EC dans le référentiel d'évaluation (matières
 * et éléments) sans passer par l'API. Chaque écriture est idempotente par clé externe : rejouer la même projection
 * ne change rien ; une valeur modifiée met la ligne à jour ; une ligne désactivée est réactivée par un nouvel upsert.
 * Fourni par le module Assessment (S09).
 *
 * @throws \Scholaris\Core\Http\HttpException 409 si le code est déjà pris par une autre matière, 404 si la matière parente manque
 */
interface CurriculumCatalog
{
    /** @return string identifiant de la matière (subjects.id) */
    public function upsertSubject(string $tenantId, string $establishmentId, CurriculumItem $subject): string;

    /** @return string identifiant de l'élément (subject_elements.id) */
    public function upsertElement(string $tenantId, string $establishmentId, string $subjectExternalKey, CurriculumItem $element): string;

    /** Désactive une matière ou un élément ; aucune donnée n'est supprimée. @return bool false si la clé est inconnue */
    public function deactivate(string $tenantId, string $establishmentId, string $externalKey): bool;

    public function find(string $tenantId, string $establishmentId, string $externalKey): ?CurriculumEntry;

    /** @return list<CurriculumEntry> éléments de la matière, actifs ou non */
    public function elementsOf(string $tenantId, string $establishmentId, string $subjectExternalKey): array;
}
