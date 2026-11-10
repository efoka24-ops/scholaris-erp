<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Rattachement d'un garant financier à un élève par une application (C-118) : la personne du client qui porte ce téléphone
 * (un parent déjà connu, avec ou sans compte), sinon une nouvelle personne créée avec l'identité fournie. Le portail famille
 * liste les enfants dont la personne connectée est garant financier (`PersonDirectory::learnersOf`).
 * Le téléphone est comparé sans indicatif ni séparateurs (« +237 6 99 … » et « 699… » désignent la même personne).
 */
interface GuarantorLinker
{
    /**
     * @param array{last_name?: string, first_name?: string} $identity utilisée seulement si aucune personne ne porte ce téléphone
     * @return array{person_id: string, created: bool, already_linked: bool}
     * @throws \Scholaris\Core\Http\HttpException 422 (téléphone ou identité invalide, élève inconnu), 409 (plusieurs personnes portent ce téléphone)
     */
    public function attachFinancialGuarantor(string $tenantId, string $learnerId, string $phone, array $identity, ?string $actorId): array;

    /**
     * Personne du client qui porte ce téléphone (pour réutiliser un parent déjà connu au lieu d'en créer un double).
     *
     * @throws \Scholaris\Core\Http\HttpException 422 (téléphone invalide), 409 (plusieurs personnes portent ce téléphone)
     */
    public function personIdByPhone(string $tenantId, string $phone, ?string $exceptPersonId = null): ?string;

    /** @return list<PersonSummary> garants financiers d'un élève (sans les tuteurs) */
    public function financialGuarantorsOf(string $tenantId, string $learnerId): array;
}
