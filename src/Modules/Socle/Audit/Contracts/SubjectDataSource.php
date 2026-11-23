<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Contracts;

/**
 * Contrat sortant LOCAL : données d'une personne concernée (accès, portabilité, effacement).
 * Enregistrement : clé `privacy.subject_data_source.<module>` dans `definitions.php`, ou `SubjectDataSources::register()`.
 */
interface SubjectDataSource
{
    public function name(): string;

    /** @return array<string, list<array<string, mixed>>> */
    public function collect(string $tenantId, string $personId): array;

    /** Anonymise les données de la personne dans le périmètre de la source ; renvoie le nombre de lignes touchées. */
    public function anonymize(string $tenantId, string $personId): int;
}
