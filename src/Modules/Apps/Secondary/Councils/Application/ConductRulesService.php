<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Application;

use DomainException;
use InvalidArgumentException;
use Scholaris\Modules\Apps\Secondary\Councils\Contracts\ConductRules;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\ConductScale;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\Defaults;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\SanctionProposal;

/** Adaptateur du contrat ConductRules : délègue à ConductScale et SanctionProposal, sans copier leur logique. */
final class ConductRulesService implements ConductRules
{
    public function exampleScale(): array
    {
        return Defaults::CONDUCT_SCALE;
    }

    public function scaleErrors(array $scale): array
    {
        if (!is_array($scale['tiers'] ?? null) || !array_is_list($scale['tiers'])) {
            return ['Le barème doit porter une liste de paliers « tiers ».'];
        }
        foreach ($scale['tiers'] as $tier) {
            if (!is_array($tier)) {
                return ['Chaque palier du barème est un objet.'];
            }
        }
        try {
            /** @var array{tiers?: list<array<string, mixed>>, repeat_offence_code?: string} $scale */
            new ConductScale($scale);
        } catch (InvalidArgumentException $e) {
            return [$e->getMessage()];
        }

        return [];
    }

    public function propose(array $scale, string $personId, float $unjustifiedHours, bool $repeatOffence = false): ?array
    {
        $errors = $this->scaleErrors($scale);
        if ($errors !== []) {
            throw new InvalidArgumentException($errors[0]);
        }
        /** @var array{tiers?: list<array<string, mixed>>, repeat_offence_code?: string} $scale */
        $proposal = (new ConductScale($scale))->propose($personId, $unjustifiedHours, $repeatOffence);

        return $proposal === null ? null : self::describe($proposal);
    }

    public function decide(array $proposal, bool $apply, string $decidedBy, ?string $reason = null): array
    {
        if ($proposal['status'] !== SanctionProposal::PROPOSED) {
            throw new DomainException('Cette sanction a déjà fait l\'objet d\'une décision.');
        }
        $open = SanctionProposal::propose($proposal['person_id'], $proposal['code'], $proposal['label'], $proposal['level'], $proposal['hours']);
        $decided = $apply ? $open->apply($decidedBy) : $open->reject($decidedBy, (string) $reason);

        return self::describe($decided);
    }

    /** @return array{person_id: string, code: string, label: string, level: string, hours: float, status: string, decided_by: ?string, reason: ?string} */
    private static function describe(SanctionProposal $p): array
    {
        return [
            'person_id' => $p->personId, 'code' => $p->code, 'label' => $p->label, 'level' => $p->level, 'hours' => $p->hours,
            'status' => $p->status, 'decided_by' => $p->decidedBy, 'reason' => $p->reason,
        ];
    }
}
