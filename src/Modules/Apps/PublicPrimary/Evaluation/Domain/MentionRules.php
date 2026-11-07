<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Domain;

/**
 * Règle de mention lue des paramètres (`public_primary.evaluation.*`) : aucune valeur n'est fixée ici. Les codes de mention viennent
 * de `mention_rule` (tous les critères minimaux maîtrisés / au moins un / aucun) ; l'échelle d'affichage vient de `scale`.
 */
final class MentionRules
{
    public const NOT_ASSESSED = 'not_assessed';

    public function __construct(
        /** occasions de vérification par critère */
        public readonly int $occasions,
        /** occasions réussies exigées pour qu'un critère soit maîtrisé */
        public readonly int $masteryMinSuccess,
        public readonly string $allCode,
        public readonly string $someCode,
        public readonly string $noneCode,
        /** le critère de perfectionnement n'est examiné que si la mention est celle de « tous maîtrisés » */
        public readonly bool $perfectionOnlyIfAll,
        /** identifiant de version de la règle, conservé avec chaque mention calculée */
        public readonly string $version,
    ) {
    }

    /**
     * @param array<string, mixed> $mentionRule all, some, none
     */
    public static function from(int $occasions, int $masteryMinSuccess, array $mentionRule, bool $perfectionOnlyIfAll): self
    {
        $all = $mentionRule['all'] ?? null;
        $some = $mentionRule['some'] ?? null;
        $none = $mentionRule['none'] ?? null;
        if (!is_string($all) || !is_string($some) || !is_string($none) || $occasions < 1 || $masteryMinSuccess < 1 || $masteryMinSuccess > $occasions) {
            throw new \InvalidArgumentException('Règle de mention incomplète ou incohérente (public_primary.evaluation.*).');
        }
        $version = sprintf('occ%d-min%d-%s-%s-%s-%s', $occasions, $masteryMinSuccess, $all, $some, $none, $perfectionOnlyIfAll ? 'pa' : 'pany');

        return new self($occasions, $masteryMinSuccess, $all, $some, $none, $perfectionOnlyIfAll, $version);
    }
}
