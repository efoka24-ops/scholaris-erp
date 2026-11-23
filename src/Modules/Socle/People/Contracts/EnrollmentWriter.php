<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Inscription du socle écrite par une application (ex. Higher à la validation de son inscription administrative) : brouillon puis
 * validation, avec les mêmes contrôles que l'API (structure ouverte, garant d'un mineur, gardes `organisation.enrolment.guards.*`,
 * une seule inscription vivante par année). Idempotent : une inscription vivante de la même personne, dans la même structure et
 * la même année, est reprise (validée si elle est encore en brouillon) au lieu d'en créer une autre.
 */
interface EnrollmentWriter
{
    /**
     * @return string identifiant de l'inscription du socle
     * @throws \Scholaris\Core\Http\HttpException 409 (autre inscription vivante) ou 422 (dossier incomplet, refus d'une garde)
     */
    public function enrol(string $tenantId, string $establishmentId, string $personId, string $structureId, string $academicYearId, ?string $actorId): string;
}
