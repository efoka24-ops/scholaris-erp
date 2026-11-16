<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Point d'extension : les modules qui rattachent des données à une période (colonne `period_id` : épreuves, notes, conseils,
 * délibérations, bulletins, cahiers de progrès…) disent combien de lignes l'utilisent. Organisation s'en sert pour refuser la
 * suppression d'une période utilisée et le déplacement de ses dates hors des éléments datés qu'elle contient.
 * Enregistrement dans le conteneur sous une entrée de liste `organisation.period_usage.<module>` (voir PREFIX). Chaque module ne
 * lit que ses propres tables, bornées au client et à l'établissement.
 */
interface PeriodUsage
{
    public const PREFIX = 'organisation.period_usage.';

    /** @return list<PeriodUse> usages de la période (les usages à zéro peuvent être omis) */
    public function uses(string $tenantId, string $establishmentId, string $periodId): array;
}
