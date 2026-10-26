<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Domain;

/** Résultat du calcul de mention d'une discipline pour une unité. */
final class MentionResult
{
    /**
     * @param list<string> $mastered critères minimaux maîtrisés
     * @param list<string> $notMastered critères minimaux à remédier
     * @param array<string, int> $successes occasions réussies par critère
     */
    public function __construct(
        public readonly string $mention,
        public readonly array $mastered,
        public readonly array $notMastered,
        public readonly bool $perfectionMastered,
        public readonly array $successes,
    ) {
    }

    public function isAssessed(): bool
    {
        return $this->mention !== MentionRules::NOT_ASSESSED;
    }
}
