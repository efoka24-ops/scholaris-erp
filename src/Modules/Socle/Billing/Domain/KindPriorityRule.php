<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

/**
 * Règle « par nature » (SS7, RG-FIN-4) : les sommes dues sont couvertes dans l'ordre des natures de frais
 * (`billing.allocation_kind_order`, ex. inscription, APEE, pension, puis le reste « * »), puis de l'échéance la plus ancienne,
 * puis, à échéance égale, dans l'ordre des enfants de la famille (`billing.allocation_sibling_order`) : un paiement familial est
 * réparti tranche par tranche entre les enfants. Une nature absente de la liste prend la place de « * », ou la dernière place
 * si « * » n'y figure pas.
 *
 * Ordre des enfants : rangs fournis par l'appelant (ex. aîné d'abord) ; à défaut, « enrolled_first » : l'enfant dont la première
 * facture est la plus ancienne (identifiants de facture chronologiques) passe d'abord.
 */
final class KindPriorityRule implements AllocationRule
{
    public const NAME = 'kind_priority';
    public const WILDCARD = '*';
    public const DEFAULT_KIND_ORDER = ['registration', 'apee', 'tuition', self::WILDCARD];
    public const SIBLING_ENROLLED_FIRST = 'enrolled_first';
    public const SIBLING_ELDEST_FIRST = 'eldest_first';
    public const SIBLING_ORDERS = [self::SIBLING_ENROLLED_FIRST, self::SIBLING_ELDEST_FIRST];

    /** @var list<string> */
    private readonly array $kindOrder;

    /**
     * @param list<string> $kindOrder natures dans l'ordre de priorité ; liste vide = ordre par défaut
     * @param array<string, int> $siblingRanks rang de chaque apprenant (0 = premier) ; vide = ordre des premières factures
     */
    public function __construct(array $kindOrder = self::DEFAULT_KIND_ORDER, private readonly array $siblingRanks = [])
    {
        $kinds = [];
        foreach ($kindOrder as $kind) {
            if ($kind !== '' && !in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }
        $this->kindOrder = $kinds === [] ? self::DEFAULT_KIND_ORDER : $kinds;
    }

    /** @param array<string, int> $siblingRanks */
    public function withSiblingRanks(array $siblingRanks): self
    {
        return new self($this->kindOrder, $siblingRanks);
    }

    /** @return list<string> */
    public function kindOrder(): array
    {
        return $this->kindOrder;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function version(): string
    {
        return self::NAME . '@1';
    }

    public function order(array $dues, string $today): array
    {
        $kindRanks = array_flip($this->kindOrder);
        $wildcard = $kindRanks[self::WILDCARD] ?? count($this->kindOrder);
        $siblings = $this->siblingRanks !== [] ? $this->siblingRanks : self::enrolledFirst($dues);
        $last = count($siblings) + 1;

        $key = static fn (Due $d): array => [
            $kindRanks[$d->kind] ?? $wildcard,
            $d->dueOn,
            $d->sequence,
            $siblings[$d->learnerId] ?? $last,
            $d->learnerId,
            $d->installmentId,
        ];
        usort($dues, static fn (Due $a, Due $b): int => $key($a) <=> $key($b));

        return $dues;
    }

    /**
     * @param list<Due> $dues
     * @return array<string, int>
     */
    private static function enrolledFirst(array $dues): array
    {
        $first = [];
        foreach ($dues as $due) {
            if (!isset($first[$due->learnerId]) || strcmp($due->invoiceId, $first[$due->learnerId]) < 0) {
                $first[$due->learnerId] = $due->invoiceId;
            }
        }
        $learners = array_map('strval', array_keys($first));
        usort($learners, static fn (string $a, string $b): int => [$first[$a], $a] <=> [$first[$b], $b]);
        $ranks = [];
        foreach ($learners as $rank => $learner) {
            $ranks[$learner] = $rank;
        }

        return $ranks;
    }
}
