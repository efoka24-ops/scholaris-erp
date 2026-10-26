<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Promotion\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\AnnualMentions;
use Scholaris\Modules\Apps\PublicPrimary\Promotion\Domain\PromotionPolicy;
use Scholaris\Modules\Apps\PublicPrimary\Promotion\Infrastructure\PdoPromotion;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryScope;
use Scholaris\Modules\Apps\PublicPrimary\Support\Reach;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritorySettings;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Passage de classe et conseil des maîtres (EX-PAS-01 à 04, T665) :
 *  - propositions automatiques selon la politique nationale versionnée (RG-PAS-1 : seul le niveau national la modifie, verrou `national`) ;
 *  - décisions du conseil (API-08) par le directeur : tout redoublement est motivé ; un deuxième redoublement dans le même niveau
 *    exige l'avis de l'inspecteur (RG-PAS-2, contrat Approvals : le directeur demande, l'inspecteur décide) ;
 *  - notification aux familles avec le délai de recours (`public_primary.promotion.appeal_days`), recours enregistré par le directeur ;
 *  - procès-verbal DOC-12 et statistiques de passage.
 */
final class PromotionService
{
    public const POLICY_KEY = 'public_primary.promotion.policy';
    public const NOTICE_TEMPLATE = 'pub.pas.parent';
    public const APPROVAL_KIND = 'public_primary.second_repeat';
    public const MINUTES_TYPE = 'pp_doc12_council_minutes';
    private const LABELS = [
        'promote' => 'passe en classe supérieure',
        'promote_with_remediation' => 'passe avec un plan de remédiation',
        'repeat' => 'redouble',
        'council_review' => 'examen par le conseil',
    ];

    public function __construct(
        private readonly PdoPromotion $repository,
        private readonly AnnualMentions $mentions,
        private readonly ClassRoster $classes,
        private readonly SchoolRegistry $schools,
        private readonly PersonDirectory $people,
        private readonly PublicMessages $messages,
        private readonly Approvals $approvals,
        private readonly DocumentIssuer $documents,
        private readonly UserPersonLink $users,
        private readonly TerritorySettings $territorial,
        private readonly PrimaryScope $scope,
        private readonly Rules $rules,
        private readonly PrimaryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    public function policy(string $tenantId, string $establishmentId): PromotionPolicy
    {
        try {
            return PromotionPolicy::from($this->rules->array($tenantId, $establishmentId, self::POLICY_KEY));
        } catch (\InvalidArgumentException $invalid) {
            throw new HttpException(500, 'Paramètres invalides', $invalid->getMessage());
        }
    }

    /**
     * RG-PAS-1 : la politique se règle au niveau national seulement (verrou `national`) ; un directeur reçoit 403.
     *
     * @param array<string, mixed> $input version, na_for_remediation, na_for_council
     */
    public function setPolicy(Principal $principal, string $establishmentId, array $input): PromotionPolicy
    {
        if ($this->scope->reach($principal, 'settings.manage', $establishmentId)->isEmpty()) {
            throw HttpException::forbidden('La politique de passage est fixée au niveau national ; l\'école ne peut pas la modifier (RG-PAS-1).');
        }
        try {
            $policy = PromotionPolicy::from($input);
        } catch (\InvalidArgumentException $invalid) {
            throw HttpException::validation(['policy' => [$invalid->getMessage()]]);
        }
        $this->territorial->set($principal->tenantId, null, self::POLICY_KEY, ['version' => $policy->version, 'na_for_remediation' => $policy->naForRemediation, 'na_for_council' => $policy->naForCouncil], 'national', $principal->userId);

        return $policy;
    }

    public function writer(Principal $principal, string $establishmentId, string $structureId): Reach
    {
        $reach = $this->scope->first($principal, $establishmentId, 'public_primary.promotion.write', 'public_primary.promotion.validate');
        if ($reach->isEmpty()) {
            throw HttpException::forbidden('Permission requise : public_primary.promotion.write ou validate.');
        }
        $this->scope->assertWritesClass($principal, $reach, $structureId, 'pp_promotion', $structureId);

        return $reach;
    }

    public function reader(Principal $principal, string $establishmentId, ?string $structureId): Reach
    {
        $reach = $this->scope->first($principal, $establishmentId, 'public_primary.promotion.validate', 'public_primary.promotion.write', 'public_primary.promotion.opinion');
        if ($reach->isEmpty()) {
            throw HttpException::notFound();
        }
        if ($structureId !== null && !$reach->isTerritorial()) {
            $this->scope->assertReadsClass($reach, $structureId);
        } elseif ($structureId === null && !$reach->wholeSchool && !$reach->isTerritorial()) {
            throw HttpException::notFound();
        }

        return $reach;
    }

    /**
     * Calcule (ou recalcule) les propositions d'une classe ; une décision déjà prise n'est jamais modifiée.
     *
     * @param array<string, mixed> $input structure_id, level_code?
     * @return list<array<string, mixed>>
     */
    public function propose(Principal $principal, string $establishmentId, string $yearLabel, array $input): array
    {
        $tenantId = $principal->tenantId;
        if (!Input::isYearLabel($yearLabel)) {
            throw HttpException::validation(['year' => ['Année scolaire AAAA-AAAA attendue.']]);
        }
        $structureId = is_string($input['structure_id'] ?? null) ? (string) $input['structure_id'] : '';
        $this->writer($principal, $establishmentId, $structureId);
        $class = $this->classes->find($tenantId, $establishmentId, $structureId) ?? throw HttpException::notFound();
        $level = $input['level_code'] ?? ($class->levelCodes[0] ?? null);
        if (!is_string($level) || !in_array($level, $class->levelCodes, true)) {
            throw HttpException::validation(['level_code' => ['Niveau de la classe requis.']]);
        }
        $school = $this->schools->find($tenantId, $establishmentId) ?? throw HttpException::validation(['school' => ['Fiche de l\'école requise.']]);
        $subsystem = $school->subsystem === 'anglophone' ? 'anglophone' : 'francophone';
        $policy = $this->policy($tenantId, $establishmentId);
        $none = $this->notAcquiredCode($tenantId, $establishmentId);
        $at = $this->clock->now();
        foreach ($this->classes->pupilIdsIn($tenantId, $establishmentId, $structureId, $this->clock->today()) as $personId) {
            $key = array_map(static fn (array $line): string => $line['mention'], array_values(array_filter($this->mentions->annual($tenantId, $establishmentId, $personId, $yearLabel, $level, $subsystem), static fn (array $line): bool => $line['is_key'])));
            $this->repository->saveProposal($tenantId, $establishmentId, $yearLabel, $structureId, $personId, $level, $policy->propose($key, $none), PromotionPolicy::countNotAcquired($key, $none), $policy->version, $at);
        }
        $this->audit->append($tenantId, $principal->userId, 'public_primary.promotion.proposed', 'pp_promotion', $yearLabel . ':' . $structureId, null, ['policy_version' => $policy->version]);

        return $this->list($tenantId, $establishmentId, $yearLabel, $structureId);
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantId, string $establishmentId, string $yearLabel, ?string $structureId): array
    {
        $rows = $this->repository->ofClass($tenantId, $establishmentId, $yearLabel, $structureId);
        $names = [];
        foreach ($this->people->findMany($tenantId, array_values(array_unique(array_map(static fn (array $r): string => (string) $r['person_id'], $rows)))) as $person) {
            $names[$person->id] = $person->fullName();
        }

        return array_map(static fn (array $row): array => self::resource($row) + ['name' => $names[(string) $row['person_id']] ?? ''], $rows);
    }

    /**
     * API-08 : décisions du conseil des maîtres d'une classe.
     *
     * @param array<string, mixed> $input structure_id, held_on, attendees[], decisions [{person_id, decision, reason?}]
     * @return array<string, mixed>
     */
    public function council(Principal $principal, string $establishmentId, string $yearLabel, array $input): array
    {
        $tenantId = $principal->tenantId;
        $reach = $this->scope->reach($principal, 'public_primary.promotion.validate', $establishmentId);
        $this->scope->assertWholeSchool($principal, $reach, 'pp_council', $yearLabel, 'Le conseil des maîtres est enregistré par le directeur.');
        $errors = [];
        $structureId = Input::text($input['structure_id'] ?? null, 40, 'structure_id', $errors, true);
        $heldOn = $input['held_on'] ?? null;
        if (!Input::isDate($heldOn)) {
            $errors['held_on'][] = 'Date de séance AAAA-MM-JJ requise.';
        }
        $attendees = $input['attendees'] ?? null;
        if (!is_array($attendees) || !array_is_list($attendees) || $attendees === [] || array_filter($attendees, static fn ($a): bool => !is_string($a) || trim($a) === '' || mb_strlen($a) > 120) !== []) {
            $errors['attendees'][] = 'Liste des présents requise (noms de 120 caractères au plus).';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $rows = [];
        foreach ($this->repository->ofClass($tenantId, $establishmentId, $yearLabel, (string) $structureId) as $row) {
            $rows[(string) $row['person_id']] = $row;
        }
        if ($rows === []) {
            throw HttpException::conflict('Aucune proposition pour cette classe : calculez d\'abord les propositions.');
        }
        $given = [];
        foreach ((array) ($input['decisions'] ?? []) as $index => $line) {
            $field = 'decisions.' . $index;
            $personId = is_array($line) ? ($line['person_id'] ?? null) : null;
            $decision = is_array($line) ? ($line['decision'] ?? null) : null;
            $reason = is_array($line) ? Input::text($line['reason'] ?? null, 1000, $field . '.reason', $errors) : null;
            if (!is_string($personId) || !isset($rows[$personId])) {
                $errors[$field . '.person_id'][] = 'Élève sans proposition dans cette classe.';
                continue;
            }
            if (!is_string($decision) || !in_array($decision, PromotionPolicy::DECISIONS, true)) {
                $errors[$field . '.decision'][] = 'Décision attendue : ' . implode(', ', PromotionPolicy::DECISIONS) . '.';
                continue;
            }
            if ($decision === PromotionPolicy::REPEAT && $reason === null) {
                $errors[$field . '.reason'][] = 'Tout redoublement est motivé dans le procès-verbal (RG-PAS-2).';
            }
            $given[$personId] = ['decision' => $decision, 'reason' => $reason];
        }
        foreach ($rows as $personId => $row) {
            if ($row['decision_state'] !== 'proposed') {
                unset($given[$personId]);
                continue;
            }
            if (!isset($given[$personId]) && $row['proposal'] === PromotionPolicy::COUNCIL_REVIEW) {
                $errors['decisions'][] = 'Une décision est attendue pour chaque élève proposé à l\'examen du conseil (' . $personId . ').';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $at = $this->clock->now();
        $councilId = $this->repository->insertCouncil($tenantId, $establishmentId, $yearLabel, (string) $structureId, (string) $heldOn, array_values(array_map('strval', (array) $attendees)), $principal->userId, $at);
        $appealUntil = (new DateTimeImmutable((string) $heldOn))->modify('+' . max(0, $this->rules->int($tenantId, $establishmentId, 'public_primary.promotion.appeal_days')) . ' days')->format('Y-m-d');
        foreach ($rows as $personId => $row) {
            if ($row['decision_state'] !== 'proposed') {
                continue;
            }
            $decision = $given[$personId]['decision'] ?? (string) $row['proposal'];
            $reason = $given[$personId]['reason'] ?? null;
            $values = ['decision' => $decision, 'reason' => $reason, 'council_id' => $councilId, 'appeal_until' => $appealUntil, 'decision_state' => 'final'];
            if ($decision === PromotionPolicy::REPEAT && $this->repository->previousRepeats($tenantId, (string) $personId, (string) $row['level_code'], $yearLabel) > 0) {
                $values['decision_state'] = 'pending_opinion';
                $values['inspector_opinion_approval_id'] = $this->approvals->request($tenantId, self::APPROVAL_KIND, 'pp_promotion_decision', (string) $row['id'], (string) $reason, $principal->userId);
            }
            $this->repository->update($tenantId, (string) $row['id'], $values, $at);
            if ($values['decision_state'] === 'final') {
                $this->notify($tenantId, $establishmentId, (string) $personId, $yearLabel, $decision, $appealUntil);
            }
        }
        $this->audit->append($tenantId, $principal->userId, 'public_primary.promotion.council_recorded', 'pp_council', $councilId, null, ['structure_id' => $structureId, 'held_on' => $heldOn]);

        return ['council_id' => $councilId, 'decisions' => $this->list($tenantId, $establishmentId, $yearLabel, (string) $structureId)];
    }

    /**
     * RG-PAS-2 : avis de l'inspecteur sur un deuxième redoublement. Favorable : le redoublement devient définitif ; défavorable :
     * l'élève passe avec un plan de remédiation (le redoublement n'est pas appliqué).
     *
     * @param array<string, mixed> $input opinion (favourable|unfavourable), comment
     * @return array<string, mixed>
     */
    public function opinion(Principal $principal, string $establishmentId, string $decisionId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $reach = $this->scope->reach($principal, 'public_primary.promotion.opinion', $establishmentId);
        if ($reach->isEmpty()) {
            throw HttpException::notFound();
        }
        $row = $this->repository->find($tenantId, $establishmentId, $decisionId) ?? throw HttpException::notFound();
        if ($row['decision_state'] !== 'pending_opinion' || $row['inspector_opinion_approval_id'] === null) {
            throw HttpException::conflict('Aucun avis attendu pour cette décision.');
        }
        $errors = [];
        $opinion = $input['opinion'] ?? null;
        if (!in_array($opinion, ['favourable', 'unfavourable'], true)) {
            $errors['opinion'][] = 'Valeur attendue : favourable ou unfavourable.';
        }
        $comment = Input::text($input['comment'] ?? null, 500, 'comment', $errors, true);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($opinion === 'favourable') {
            $this->approvals->approve($tenantId, (string) $row['inspector_opinion_approval_id'], $principal->userId);
        } else {
            $this->approvals->reject($tenantId, (string) $row['inspector_opinion_approval_id'], $principal->userId);
        }
        $decision = $opinion === 'favourable' ? PromotionPolicy::REPEAT : PromotionPolicy::PROMOTE_WITH_REMEDIATION;
        $this->repository->update($tenantId, $decisionId, ['decision' => $decision, 'decision_state' => 'final', 'inspector_opinion' => (string) $opinion, 'inspector_comment' => $comment], $this->clock->now());
        $this->audit->append($tenantId, $principal->userId, 'public_primary.promotion.inspector_opinion', 'pp_promotion_decision', $decisionId, null, ['opinion' => $opinion]);
        $this->notify($tenantId, $establishmentId, (string) $row['person_id'], (string) $row['year_label'], $decision, (string) $row['appeal_until']);

        return self::resource($this->repository->find($tenantId, $establishmentId, $decisionId) ?? $row);
    }

    /**
     * Recours de la famille, enregistré par le directeur dans le délai (EX-PAS-03), puis tranché (maintien ou révision motivée).
     *
     * @param array<string, mixed> $input action (lodge|uphold|revise), reason, decision?
     * @return array<string, mixed>
     */
    public function appeal(Principal $principal, string $establishmentId, string $decisionId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $reach = $this->scope->reach($principal, 'public_primary.promotion.validate', $establishmentId);
        $this->scope->assertWholeSchool($principal, $reach, 'pp_promotion_decision', $decisionId, 'Le recours est enregistré par le directeur.');
        $row = $this->repository->find($tenantId, $establishmentId, $decisionId) ?? throw HttpException::notFound();
        $errors = [];
        $action = $input['action'] ?? null;
        if (!in_array($action, ['lodge', 'uphold', 'revise'], true)) {
            $errors['action'][] = 'Valeur attendue : lodge, uphold ou revise.';
        }
        $reason = Input::text($input['reason'] ?? null, 1000, 'reason', $errors, true);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $at = $this->clock->now();
        if ($action === 'lodge') {
            if ($row['decision_state'] !== 'final' || $row['appeal_state'] !== null) {
                throw HttpException::conflict('Recours impossible : décision non définitive ou recours déjà enregistré.');
            }
            if ($row['appeal_until'] !== null && $this->clock->today() > (string) $row['appeal_until']) {
                throw HttpException::conflict('Le délai de recours est expiré (' . $row['appeal_until'] . ').');
            }
            $this->repository->update($tenantId, $decisionId, ['appeal_state' => 'lodged', 'appeal_reason' => $reason], $at);
        } else {
            if ($row['appeal_state'] !== 'lodged') {
                throw HttpException::conflict('Aucun recours en cours.');
            }
            $values = ['appeal_state' => $action === 'uphold' ? 'upheld' : 'revised', 'appeal_reason' => trim((string) $row['appeal_reason'] . ' | ' . $reason)];
            if ($action === 'revise') {
                $decision = $input['decision'] ?? null;
                if (!in_array($decision, [PromotionPolicy::PROMOTE, PromotionPolicy::PROMOTE_WITH_REMEDIATION], true)) {
                    throw HttpException::validation(['decision' => ['Révision : promote ou promote_with_remediation.']]);
                }
                $values['decision'] = $decision;
            }
            $this->repository->update($tenantId, $decisionId, $values, $at);
        }
        $this->audit->append($tenantId, $principal->userId, 'public_primary.promotion.appeal_' . $action, 'pp_promotion_decision', $decisionId, null, ['reason' => $reason]);

        return self::resource($this->repository->find($tenantId, $establishmentId, $decisionId) ?? $row);
    }

    /**
     * Procès-verbal DOC-12 du conseil (numéro, QR), émis par le directeur.
     *
     * @return array<string, mixed>
     */
    public function minutes(Principal $principal, string $establishmentId, string $councilId, string $locale): array
    {
        $tenantId = $principal->tenantId;
        $reach = $this->scope->reach($principal, 'public_primary.promotion.validate', $establishmentId);
        $this->scope->assertWholeSchool($principal, $reach, 'pp_council', $councilId, 'Le procès-verbal est émis par le directeur.');
        $council = $this->repository->council($tenantId, $establishmentId, $councilId) ?? throw HttpException::notFound();
        $decisions = $this->repository->decisionsOfCouncil($tenantId, $councilId);
        $names = [];
        foreach ($this->people->findMany($tenantId, array_map(static fn (array $r): string => (string) $r['person_id'], $decisions)) as $person) {
            $names[$person->id] = $person->fullName();
        }
        $lines = [];
        $counts = [];
        foreach ($decisions as $row) {
            $decision = (string) $row['decision'];
            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
            $lines[] = sprintf('%s : %s%s%s', $names[(string) $row['person_id']] ?? (string) $row['person_id'], self::LABELS[$decision] ?? $decision, $row['reason'] !== null ? ' (' . $row['reason'] . ')' : '', $row['decision_state'] === 'pending_opinion' ? ' [avis de l\'inspecteur attendu]' : '');
        }
        $class = $this->classes->find($tenantId, $establishmentId, (string) $council['structure_id']);
        $subject = $this->users->personIdForUser($tenantId, $principal->userId) ?? throw HttpException::validation(['user' => ['Compte sans personne liée : émission impossible.']]);
        $statistics = implode(', ', array_map(static fn (string $d, int $n): string => (self::LABELS[$d] ?? $d) . ' : ' . $n, array_keys($counts), array_values($counts)));
        $document = $this->documents->issue($tenantId, $establishmentId, self::MINUTES_TYPE, $subject, [
            'school_year' => (string) $council['year_label'], 'class_name' => $class->label ?? '', 'held_on' => (string) $council['held_on'],
            'attendees_line' => implode(', ', (array) json_decode((string) $council['attendees'], true)), 'decisions_block' => implode("\n", $lines),
            'statistics_line' => $statistics, 'policy_version' => (string) ($decisions[0]['policy_version'] ?? ''),
        ], $principal->userId, $locale === 'en' ? 'en' : 'fr');
        $this->repository->setMinutes($tenantId, $councilId, $document->id);

        return ['council_id' => $councilId, 'document' => ['id' => $document->id, 'number' => $document->number]];
    }

    /**
     * Statistiques de passage (EX-PAS-04), en effectifs : par classe, proposition et décision.
     *
     * @return array<string, mixed>
     */
    public function statistics(string $tenantId, string $establishmentId, string $yearLabel, ?string $structureId): array
    {
        $byClass = [];
        foreach ($this->repository->ofClass($tenantId, $establishmentId, $yearLabel, $structureId) as $row) {
            $class = (string) $row['structure_id'];
            $byClass[$class] ??= ['structure_id' => $class, 'pupils' => 0, 'proposals' => [], 'decisions' => [], 'pending' => 0];
            ++$byClass[$class]['pupils'];
            $byClass[$class]['proposals'][(string) $row['proposal']] = ($byClass[$class]['proposals'][(string) $row['proposal']] ?? 0) + 1;
            if ($row['decision_state'] === 'final') {
                $byClass[$class]['decisions'][(string) $row['decision']] = ($byClass[$class]['decisions'][(string) $row['decision']] ?? 0) + 1;
            } else {
                ++$byClass[$class]['pending'];
            }
        }

        return ['year_label' => $yearLabel, 'classes' => array_values($byClass)];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'structure_id' => (string) $row['structure_id'], 'year_label' => (string) $row['year_label'],
            'level_code' => (string) $row['level_code'], 'proposal' => (string) $row['proposal'], 'key_na_count' => (int) $row['key_na_count'],
            'decision' => $row['decision'], 'state' => (string) $row['decision_state'], 'reason' => $row['reason'], 'policy_version' => (string) $row['policy_version'],
            'inspector_opinion' => $row['inspector_opinion'], 'appeal_until' => $row['appeal_until'], 'appeal_state' => $row['appeal_state'], 'council_id' => $row['council_id'],
        ];
    }

    private function notify(string $tenantId, string $establishmentId, string $personId, string $yearLabel, string $decision, string $appealUntil): void
    {
        $this->messages->notifyGuardians($tenantId, $establishmentId, $personId, self::NOTICE_TEMPLATE, [
            'school_year' => $yearLabel, 'decision' => self::LABELS[$decision] ?? $decision, 'appeal_until' => $appealUntil,
        ]);
    }

    private function notAcquiredCode(string $tenantId, string $establishmentId): string
    {
        $rule = $this->rules->array($tenantId, $establishmentId, 'public_primary.evaluation.mention_rule');

        return is_string($rule['none'] ?? null) ? $rule['none'] : throw new HttpException(500, 'Paramètres invalides', 'public_primary.evaluation.mention_rule.none manquant.');
    }
}
