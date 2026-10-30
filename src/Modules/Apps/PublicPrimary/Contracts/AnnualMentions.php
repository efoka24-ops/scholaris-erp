<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Contracts;

/**
 * Mentions annuelles d'un élève par discipline (EX-EVA-04, règle `public_primary.evaluation.annual_mention`), fournies par
 * PublicPrimary\Evaluation à la politique de passage (PAS) et aux statistiques. Aucune donnée APEE (RG-ELV-1).
 */
interface AnnualMentions
{
    /**
     * @return list<array{frame_id: string, discipline_code: string, label_fr: string, label_en: string, is_key: bool, mention: string}>
     *         `mention` : code de l'échelle (A, ECA, NA par défaut) ou `not_assessed`
     */
    public function annual(string $tenantId, string $establishmentId, string $personId, string $yearLabel, string $levelCode, string $subsystem): array;
}
