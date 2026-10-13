<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Contracts;

/**
 * Candidatures pour les autres sous-modules Higher (Enrolment : conversion candidature vers inscription, T417 ; Dashboards).
 * Borné au client et à l'établissement ; aucun contrôle de périmètre (à faire par l'appelant).
 */
interface AdmissionDirectory
{
    public function application(string $tenantId, string $establishmentId, string $applicationId): ?AdmittedApplication;

    /**
     * Marque la candidature « inscrite » (seulement depuis « admis ») et la lie à l'inscription. Idempotent pour la même inscription.
     *
     * @throws \Scholaris\Core\Http\HttpException 409 si la candidature n'est pas admise ou est déjà liée à une autre inscription
     */
    public function markEnrolled(string $tenantId, string $establishmentId, string $applicationId, string $enrolmentId, ?string $actorId): void;
}
