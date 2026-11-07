<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Promotion\Domain;

/**
 * Proposition automatique de passage (EX-PAS-01) selon la politique nationale fournie (paramètre `public_primary.promotion.policy`,
 * verrouillé au niveau national, RG-PAS-1) : on compte les disciplines clés en mention « non acquise » ; à partir de
 * `naForCouncil` : examen par le conseil ; à partir de `naForRemediation` : passage avec remédiation ; sinon passage. Le conseil
 * décide, l'application propose et trace. Aucun seuil ici : tous viennent de la politique.
 */
final class PromotionPolicy
{
    public const PROMOTE = 'promote';
    public const PROMOTE_WITH_REMEDIATION = 'promote_with_remediation';
    public const COUNCIL_REVIEW = 'council_review';
    public const REPEAT = 'repeat';
    public const DECISIONS = [self::PROMOTE, self::PROMOTE_WITH_REMEDIATION, self::REPEAT];

    public function __construct(
        public readonly string $version,
        public readonly int $naForRemediation,
        public readonly int $naForCouncil,
    ) {
    }

    /** @param array<string, mixed> $policy */
    public static function from(array $policy): self
    {
        $version = $policy['version'] ?? null;
        $remediation = $policy['na_for_remediation'] ?? null;
        $council = $policy['na_for_council'] ?? null;
        if (!is_string($version) || $version === '' || !is_int($remediation) || !is_int($council) || $remediation < 1 || $council < $remediation) {
            throw new \InvalidArgumentException('Politique de passage incomplète ou incohérente (public_primary.promotion.policy).');
        }

        return new self($version, $remediation, $council);
    }

    /**
     * @param list<string> $keyMentions mentions annuelles des disciplines clés
     */
    public function propose(array $keyMentions, string $notAcquiredCode): string
    {
        $na = count(array_filter($keyMentions, static fn (string $mention): bool => $mention === $notAcquiredCode));
        if ($na >= $this->naForCouncil) {
            return self::COUNCIL_REVIEW;
        }

        return $na >= $this->naForRemediation ? self::PROMOTE_WITH_REMEDIATION : self::PROMOTE;
    }

    /** @param list<string> $keyMentions */
    public static function countNotAcquired(array $keyMentions, string $notAcquiredCode): int
    {
        return count(array_filter($keyMentions, static fn (string $mention): bool => $mention === $notAcquiredCode));
    }
}
