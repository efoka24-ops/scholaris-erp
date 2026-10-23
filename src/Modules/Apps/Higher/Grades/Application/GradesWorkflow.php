<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Modules\Apps\Higher\Grades\Domain\GradeLifecycle;
use Scholaris\Modules\Apps\Higher\Grades\Domain\IpesDefaults;
use Scholaris\Modules\Apps\Higher\Workflows\HigherWorkflows;
use Scholaris\Modules\Socle\Workflows\Contracts\ResolvedStep;
use Scholaris\Modules\Socle\Workflows\Contracts\WorkflowResolver;

/**
 * Applique à la saisie des notes les circuits de validation réglés par l'administrateur (`grades_lifecycle`, `grades_correction`).
 * SANS réglage, ou si le cycle / la chaîne déclarés par l'établissement ne sont pas ceux de l'IPES, le cycle et la chaîne reçus
 * sont rendus tels quels : comportement strictement historique. Ce que le réglage ne peut jamais toucher (séparation saisie /
 * verrouillage, procès-verbal signé avant publication, immuabilité) ne passe pas par ici : ces gardes restent dans les services.
 */
final class GradesWorkflow
{
    public function __construct(private readonly WorkflowResolver $workflows)
    {
    }

    public function lifecycle(GradeLifecycle $base, string $tenantId, string $establishmentId): GradeLifecycle
    {
        $resolved = $this->workflows->resolve($tenantId, $establishmentId, HigherWorkflows::APPLICATION, HigherWorkflows::GRADES);
        if (!$resolved->customised || !$this->isIpesShape($base)) {
            return $base;
        }
        $validate = $resolved->step('validate');
        $lock = $resolved->step('lock');
        $publish = $resolved->step('publish');
        $sendBack = $validate->enabled ? $validate : $lock;
        $transitions = [];
        foreach ($base->transitions as $t) {
            $key = $t['from'] . '>' . $t['to'];
            if ($key === IpesDefaults::SUBMITTED . '>' . IpesDefaults::VALIDATED && !$validate->enabled) {
                continue;
            }
            $transitions[] = match ($key) {
                IpesDefaults::SUBMITTED . '>' . IpesDefaults::VALIDATED => self::with($t, $validate),
                IpesDefaults::SUBMITTED . '>' . IpesDefaults::DRAFT => self::with($t, $sendBack, $t['require_recent_2fa']),
                IpesDefaults::VALIDATED . '>' . IpesDefaults::LOCKED => self::with($t, $lock),
                IpesDefaults::LOCKED . '>' . IpesDefaults::PUBLISHED => self::with($t, $publish),
                default => $t,
            };
        }
        if (!$validate->enabled) {
            // Étape facultative sautée : la saisie soumise peut être verrouillée directement.
            $transitions[] = self::with(['from' => IpesDefaults::SUBMITTED, 'to' => IpesDefaults::LOCKED, 'permission' => '', 'require_recent_2fa' => true], $lock);
        }

        return $base->withTransitions($transitions);
    }

    /**
     * @param list<array{permission: string, role: ?string, label: string, require_recent_2fa: bool}> $base
     * @return list<array{permission: string, role: ?string, label: string, require_recent_2fa: bool}>
     */
    public function correctionChain(array $base, string $tenantId, string $establishmentId): array
    {
        $resolved = $this->workflows->resolve($tenantId, $establishmentId, HigherWorkflows::APPLICATION, HigherWorkflows::CORRECTION);
        if (!$resolved->customised || $base !== IpesDefaults::CORRECTION_CHAIN) {
            return $base;
        }
        $chain = [];
        foreach (['department', 'school'] as $key) {
            $step = $resolved->step($key);
            if (!$step->enabled) {
                continue;
            }
            $chain[] = ['permission' => $step->permission(), 'role' => $step->role(), 'label' => $step->actor->labelFr, 'require_recent_2fa' => $step->recentConfirmation];
        }

        return $chain;
    }

    /**
     * @param array{from: string, to: string, permission: string, require_recent_2fa: bool, role?: string|null} $transition
     * @return array{from: string, to: string, permission: string, require_recent_2fa: bool, role?: string|null}
     */
    private static function with(array $transition, ResolvedStep $step, ?bool $confirmation = null): array
    {
        $out = [
            'from' => $transition['from'], 'to' => $transition['to'], 'permission' => $step->permission(),
            'require_recent_2fa' => $confirmation ?? $step->recentConfirmation,
        ];
        if ($step->role() !== null) {
            $out['role'] = $step->role();
        }

        return $out;
    }

    /** Le réglage ne s'applique qu'au cycle IPES à cinq états : un cycle déclaré autrement par l'établissement garde la main. */
    private function isIpesShape(GradeLifecycle $lifecycle): bool
    {
        if ($lifecycle->states !== IpesDefaults::LIFECYCLE['states']) {
            return false;
        }
        foreach (IpesDefaults::LIFECYCLE['transitions'] as $expected) {
            if ($lifecycle->transition($expected['from'], $expected['to']) === null) {
                return false;
            }
        }

        return true;
    }
}
