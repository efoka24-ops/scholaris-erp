<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Modules\Socle\Assessment\Contracts\AdmissionDirectory;

/**
 * Admissions de l'année : celles des résultats du socle (PdoAdmissionDirectory, comportement historique) RÉUNIES à celles des
 * sources enregistrées par les applications qui arrêtent leurs décisions de fin d'année ailleurs (entrées de liste
 * `assessment.admission_sources.<application>`, implémentant le même contrat AdmissionDirectory ; ex. conseil des maîtres du
 * primaire). Ainsi People\YearPromotion reste l'unique moteur de passage en classe supérieure. Sans source : comportement inchangé.
 */
final class CompositeAdmissionDirectory implements AdmissionDirectory
{
    /** @param list<AdmissionDirectory> $sources */
    public function __construct(private readonly AdmissionDirectory $base, private readonly array $sources)
    {
    }

    public function admittedPersonIds(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        $ids = $this->base->admittedPersonIds($tenantId, $establishmentId, $academicYearId);
        foreach ($this->sources as $source) {
            array_push($ids, ...$source->admittedPersonIds($tenantId, $establishmentId, $academicYearId));
        }

        return array_values(array_unique($ids));
    }
}
