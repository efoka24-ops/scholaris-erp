<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application\Guards;

use Scholaris\Modules\Socle\Assessment\Application\AssessmentSettings;
use Scholaris\Modules\Socle\Assessment\Contracts\DeliberationContext;
use Scholaris\Modules\Socle\Assessment\Contracts\DeliberationGuard;
use Scholaris\Modules\Socle\Assessment\Contracts\GuardRefusal;
use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Assessment\Domain\Lifecycle;
use Scholaris\Modules\Socle\Assessment\Infrastructure\CatalogRepository;

/**
 * Gardes de délibération du socle (S8), chacune inactive tant que son paramètre ne l'active pas :
 *  - assessment.deliberation.require_locked_grades : ouverture refusée si une épreuve de la session est encore en saisie (RG-DEL-1) ;
 *  - assessment.deliberation.require_2fa : double authentification récente pour clôture, signature, publication, réouverture ;
 *  - assessment.deliberation.signatures_required (president | all_present) : publication après signature du PV (RG-DEL-3) ;
 *  - assessment.deliberation.verify_fingerprint : signature et publication refusées si les décisions ne correspondent
 *    plus à l'empreinte SHA-256 calculée à la clôture.
 */
final class BuiltInDeliberationGuard implements DeliberationGuard
{
    public function __construct(
        private readonly AssessmentSettings $settings,
        private readonly CatalogRepository $catalog,
        private readonly RecentSecondFactor $secondFactor,
    ) {
    }

    public function refusals(DeliberationContext $context): array
    {
        $tenantId = $context->tenantId;
        $establishmentId = $context->establishmentId;
        $out = [];

        if ($this->settings->deliberationRequires2fa($tenantId, $establishmentId, $context->stage) && !$this->secondFactor->isRecent($tenantId, $context->userId)) {
            $out[] = GuardRefusal::forbidden('Cette étape de la délibération exige une double authentification récente. Confirmez votre second facteur puis recommencez.');
        }

        if ($context->stage === self::OPEN && $this->settings->deliberationRequiresLockedGrades($tenantId, $establishmentId)) {
            $unlocked = $this->unlocked($context, $this->settings->lifecycle($tenantId, $establishmentId));
            if ($unlocked !== []) {
                $out[] = GuardRefusal::conflict(sprintf(
                    'Toutes les notes doivent être verrouillées avant la délibération : %d épreuve(s) encore en saisie (%s).',
                    count($unlocked),
                    implode(', ', array_slice($unlocked, 0, 5)),
                ));
            }
        }

        if (in_array($context->stage, [self::SIGN, self::PUBLISH], true) && $this->settings->deliberationVerifiesFingerprint($tenantId, $establishmentId)
            && ($context->storedFingerprint === null || $context->storedFingerprint !== $context->currentFingerprint)) {
            $out[] = GuardRefusal::conflict('Les décisions ne correspondent plus à l\'empreinte du procès-verbal calculée à la clôture. Rouvrez la délibération pour établir un nouveau procès-verbal.');
        }

        if ($context->stage === self::PUBLISH) {
            $required = $this->settings->deliberationSignatures($tenantId, $establishmentId);
            if ($required === AssessmentSettings::SIGNATURES_PRESIDENT && ($context->presidentId === null || !$context->hasSigned($context->presidentId))) {
                $out[] = GuardRefusal::conflict('Le procès-verbal doit être signé par le président du jury avant la publication des résultats.');
            }
            if ($required === AssessmentSettings::SIGNATURES_ALL) {
                $missing = array_values(array_filter($context->membersPresent, static fn (string $p): bool => !$context->hasSigned($p)));
                if ($missing !== []) {
                    $out[] = GuardRefusal::conflict(sprintf('Le procès-verbal doit être signé par tous les membres présents avant la publication : %d signature(s) manquante(s).', count($missing)));
                }
            }
        }

        return $out;
    }

    public function after(DeliberationContext $context): void
    {
    }

    /** @return list<string> intitulés des épreuves de la session encore modifiables */
    private function unlocked(DeliberationContext $context, Lifecycle $lifecycle): array
    {
        $out = [];
        foreach ($this->catalog->forSheet($context->tenantId, $context->establishmentId, $context->structureId, $context->periodId) as $assessment) {
            $session = (string) ($assessment['exam_session'] ?? 'normal');
            if ($context->session === 'normal' && $session !== 'normal') {
                continue;
            }
            if ($lifecycle->isEditable((string) $assessment['status'])) {
                $out[] = (string) $assessment['title'];
            }
        }

        return $out;
    }
}
