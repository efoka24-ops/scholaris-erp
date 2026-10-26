<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\CriterionInfo;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\FrameInfo;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Domain\MentionCalculator;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Domain\MentionResult;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Domain\MentionRules;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoEvaluation;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoReportCards;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryScope;
use Scholaris\Modules\Apps\PublicPrimary\Support\Reach;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Saisie des critères par occasion (EX-EVA-03, API-06, T663) et lecture de la grille élève × critère × occasion. Après chaque saisie,
 * la mention de chaque discipline touchée est recalculée (MentionCalculator, paramètres fournis), la liste de remédiation est mise à
 * jour (EX-EVA-05) et le parent d'un élève « non acquis » reçoit une proposition de rencontre (une fois par unité et discipline).
 *
 * Idempotence : un même `client_uuid` rejoué avec le même contenu rend `replayed` ; avec un autre contenu : 409 (jamais d'écrasement).
 * Une unité dont le bulletin de l'élève est publié est figée (RG-EVA-2) : 409. Périmètre : l'enseignant titulaire de la classe
 * (`public_primary.evaluation.write`) ou le directeur (`public_primary.evaluation.validate`).
 */
final class EvaluationService
{
    public const NA_TEMPLATE = 'pub.eva_na.parent';
    public const WRITERS = ['public_primary.evaluation.write', 'public_primary.evaluation.validate'];
    private const MAX_CELLS = 5000;

    public function __construct(
        private readonly ContextResolver $contexts,
        private readonly PdoEvaluation $repository,
        private readonly PdoReportCards $cards,
        private readonly PrimaryScope $scope,
        private readonly PersonDirectory $people,
        private readonly PublicMessages $messages,
        private readonly Rules $rules,
        private readonly PrimaryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    public function writer(Principal $principal, string $establishmentId, string $structureId, string $objectId): Reach
    {
        $reach = $this->scope->first($principal, $establishmentId, ...self::WRITERS);
        if ($reach->isEmpty()) {
            throw HttpException::forbidden('Permission requise : ' . implode(' ou ', self::WRITERS) . '.');
        }
        $this->scope->assertWritesClass($principal, $reach, $structureId, 'pp_unit', $objectId);

        return $reach;
    }

    /** Périmètre d'évaluation de l'acteur dans l'école (vide : aucune permission d'évaluation). */
    public function anyReach(Principal $principal, string $establishmentId): Reach
    {
        return $this->scope->first($principal, $establishmentId, ...self::WRITERS);
    }

    public function reader(Principal $principal, string $establishmentId, string $structureId): Reach
    {
        $reach = $this->scope->first($principal, $establishmentId, ...self::WRITERS);
        $this->scope->assertReadsClass($reach, $structureId);

        return $reach;
    }

    /**
     * @param array<string, mixed> $input structure_id, client_uuid?, checks [{person_id, criterion_id, occasion_no, outcome}]
     * @return array{applied: int, replayed: bool, mentions: list<array<string, mixed>>}
     */
    public function saveChecks(Principal $principal, string $establishmentId, string $unitId, array $input, ?string $clientUuid = null): array
    {
        $structureId = $input['structure_id'] ?? null;
        if (!is_string($structureId) || $structureId === '') {
            throw HttpException::validation(['structure_id' => ['Classe requise.']]);
        }
        $this->writer($principal, $establishmentId, $structureId, $unitId);
        $tenantId = $principal->tenantId;
        $context = $this->contexts->resolve($tenantId, $establishmentId, $unitId, $structureId);
        $rules = $this->contexts->mentionRules($tenantId, $establishmentId);
        $clientUuid ??= is_string($input['client_uuid'] ?? null) && $input['client_uuid'] !== '' ? (string) $input['client_uuid'] : null;
        if ($clientUuid !== null && strlen($clientUuid) > 40) {
            throw HttpException::validation(['client_uuid' => ['Identifiant client de 40 caractères au plus.']]);
        }
        $cells = $this->cells($input['checks'] ?? null, $context, $rules);
        $fingerprint = hash('sha256', $unitId . '|' . $structureId . '|' . json_encode($cells, JSON_THROW_ON_ERROR));

        if ($clientUuid !== null) {
            $previous = $this->repository->batch($tenantId, $clientUuid);
            if ($previous !== null) {
                if ($previous['fingerprint'] !== $fingerprint) {
                    throw HttpException::conflict('Ce lot a déjà été enregistré avec un autre contenu : rien n\'est écrasé.');
                }

                return ['applied' => (int) $previous['cells_count'], 'replayed' => true, 'mentions' => $this->mentionRows($tenantId, $establishmentId, $context, array_values(array_unique(array_column($cells, 'person_id'))))];
            }
        }
        foreach (array_unique(array_column($cells, 'person_id')) as $personId) {
            if ($this->cards->isFrozen($tenantId, $personId, 'unit', $unitId)) {
                throw HttpException::conflict('Le bulletin de cette unité est publié pour un élève du lot : la saisie est figée (RG-EVA-2).');
            }
        }

        $at = $this->clock->now();
        $pdo = $this->repository->pdo();
        $pdo->beginTransaction();
        try {
            foreach ($cells as $cell) {
                $this->repository->saveCell($tenantId, $establishmentId, $unitId, $structureId, $cell['person_id'], $cell['frame_id'], $cell['criterion_id'], $cell['occasion_no'], $cell['outcome'], $principal->userId, $clientUuid, $at);
            }
            if ($clientUuid !== null) {
                $this->repository->insertBatch($tenantId, $establishmentId, $unitId, $structureId, $clientUuid, $fingerprint, count($cells), $principal->userId, $at);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }

        $touched = [];
        foreach ($cells as $cell) {
            $touched[$cell['person_id']][$cell['frame_id']] = true;
        }
        foreach ($touched as $personId => $frames) {
            $this->recompute($principal, $establishmentId, $context, $rules, (string) $personId, array_keys($frames));
        }
        $this->audit->append($tenantId, $principal->userId, 'public_primary.evaluation.checks_saved', 'pp_unit', $unitId, null, [
            'establishment_id' => $establishmentId, 'structure_id' => $structureId, 'cells' => count($cells),
        ]);

        return ['applied' => count($cells), 'replayed' => false, 'mentions' => $this->mentionRows($tenantId, $establishmentId, $context, array_keys($touched))];
    }

    /** @return array<string, mixed> grille de saisie (et de consultation) d'une classe pour une unité */
    public function grid(string $tenantId, string $establishmentId, string $unitId, string $structureId): array
    {
        $context = $this->contexts->resolve($tenantId, $establishmentId, $unitId, $structureId);
        $rules = $this->contexts->mentionRules($tenantId, $establishmentId);
        $names = $this->names($tenantId, $context->pupilIds);
        $pupils = [];
        foreach ($context->pupilIds as $personId) {
            $pupils[] = ['person_id' => $personId, 'name' => $names[$personId] ?? ''];
        }
        usort($pupils, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return [
            'unit' => ['id' => $context->unit->id, 'unit_no' => $context->unit->unitNo, 'level_code' => $context->unit->levelCode, 'year_label' => $context->unit->yearLabel, 'starts_on' => $context->unit->startsOn, 'ends_on' => $context->unit->endsOn, 'evaluation_week_from' => $context->unit->evaluationWeekFrom],
            'class' => ['structure_id' => $structureId, 'label' => $context->class->label],
            'occasions' => $rules->occasions,
            'mastery_min_success' => $rules->masteryMinSuccess,
            'scale' => $this->contexts->scale($tenantId, $establishmentId),
            'frames' => array_map(static fn (FrameInfo $f): array => [
                'id' => $f->id, 'discipline_code' => $f->disciplineCode, 'label_fr' => $f->labelFr, 'label_en' => $f->labelEn, 'is_key_discipline' => $f->isKeyDiscipline,
                'criteria' => array_map(static fn (CriterionInfo $c): array => ['id' => $c->id, 'code' => $c->code, 'label_fr' => $c->labelFr, 'label_en' => $c->labelEn, 'kind' => $c->kind], $f->criteria),
            ], $context->frames),
            'pupils' => $pupils,
            'cells' => array_map(static fn (array $row): array => ['person_id' => (string) $row['person_id'], 'criterion_id' => (string) $row['criterion_id'], 'occasion_no' => (int) $row['occasion_no'], 'outcome' => (string) $row['outcome']], $this->repository->cells($tenantId, $establishmentId, $unitId, $context->pupilIds)),
            'mentions' => $this->mentionRows($tenantId, $establishmentId, $context, $context->pupilIds),
            'frozen' => array_values(array_filter($context->pupilIds, fn (string $id): bool => $this->cards->isFrozen($tenantId, $id, 'unit', $unitId))),
        ];
    }

    /**
     * Liste de remédiation de l'unité (DOC-11, EX-EVA-05) : par discipline et critère, les élèves concernés.
     *
     * @return list<array<string, mixed>>
     */
    public function remediationList(string $tenantId, string $establishmentId, string $unitId, string $structureId): array
    {
        $context = $this->contexts->resolve($tenantId, $establishmentId, $unitId, $structureId);
        $criteria = $context->criteria();
        $names = $this->names($tenantId, $context->pupilIds);
        $groups = [];
        foreach ($this->repository->remediationsOfUnit($tenantId, $establishmentId, $unitId, $context->pupilIds) as $row) {
            $criterionId = (string) $row['criterion_id'];
            if (!isset($criteria[$criterionId])) {
                continue;
            }
            ['frame' => $frame, 'criterion' => $criterion] = $criteria[$criterionId];
            $groups[$criterionId] ??= [
                'frame_id' => $frame->id, 'discipline_code' => $frame->disciplineCode, 'discipline_fr' => $frame->labelFr, 'discipline_en' => $frame->labelEn,
                'criterion_id' => $criterionId, 'criterion_code' => $criterion->code, 'criterion_fr' => $criterion->labelFr, 'criterion_en' => $criterion->labelEn,
                'pupils' => [],
            ];
            $groups[$criterionId]['pupils'][] = ['remediation_id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'name' => $names[(string) $row['person_id']] ?? '', 'state' => (string) $row['remediation_state']];
        }

        return array_values($groups);
    }

    /**
     * Recalcule et enregistre les mentions d'unité d'un élève pour les disciplines données.
     *
     * @param list<string> $frameIds
     */
    private function recompute(Principal $principal, string $establishmentId, UnitContext $context, MentionRules $rules, string $personId, array $frameIds): void
    {
        $tenantId = $principal->tenantId;
        $outcomes = [];
        foreach ($this->repository->cells($tenantId, $establishmentId, $context->unit->id, [$personId]) as $row) {
            $outcomes[(string) $row['criterion_id']][(int) $row['occasion_no']] = (string) $row['outcome'];
        }
        $at = $this->clock->now();
        foreach ($frameIds as $frameId) {
            $frame = $context->frame($frameId);
            if ($frame === null) {
                continue;
            }
            $result = MentionCalculator::compute($rules, array_map(static fn (CriterionInfo $c): array => ['id' => $c->id, 'kind' => $c->kind], $frame->criteria), $outcomes);
            $this->repository->saveMention($tenantId, $establishmentId, $personId, $frameId, $context->class->structureId, 'unit', $context->unit->id, $result->mention, $result->perfectionMastered, $rules->version, $at);
            $this->repository->syncRemediations(
                $tenantId,
                $establishmentId,
                $context->unit->id,
                $context->class->structureId,
                $personId,
                $frameId,
                array_map(static fn (CriterionInfo $c): string => $c->id, $frame->minimalCriteria()),
                $result->isAssessed() ? $result->notMastered : [],
                $principal->userId,
                $at,
            );
            $this->notifyIfNotAcquired($tenantId, $establishmentId, $context, $rules, $frame, $personId, $result);
        }
    }

    private function notifyIfNotAcquired(string $tenantId, string $establishmentId, UnitContext $context, MentionRules $rules, FrameInfo $frame, string $personId, MentionResult $result): void
    {
        if ($result->mention !== $rules->noneCode) {
            return;
        }
        $mention = $this->repository->mention($tenantId, $personId, $frame->id, 'unit', $context->unit->id);
        if ($mention === null || $mention['parent_notified_at'] !== null) {
            return;
        }
        $this->messages->notifyGuardians($tenantId, $establishmentId, $personId, self::NA_TEMPLATE, [
            'discipline' => $frame->labelFr, 'unit_no' => (string) $context->unit->unitNo,
        ]);
        $this->repository->markNotified($tenantId, $personId, $frame->id, 'unit', $context->unit->id, $this->clock->now());
    }

    /**
     * @param list<string> $personIds
     * @return list<array<string, mixed>>
     */
    private function mentionRows(string $tenantId, string $establishmentId, UnitContext $context, array $personIds): array
    {
        $criteria = $context->criteria();
        $remediation = [];
        foreach ($this->repository->remediationsOfUnit($tenantId, $establishmentId, $context->unit->id, $personIds) as $row) {
            $code = $criteria[(string) $row['criterion_id']]['criterion']->code ?? null;
            if ($code !== null) {
                $remediation[(string) $row['person_id']][(string) $row['frame_id']][] = $code;
            }
        }
        $rows = [];
        foreach ($this->repository->mentionsFor($tenantId, $establishmentId, 'unit', $context->unit->id, $personIds) as $row) {
            $frame = $context->frame((string) $row['frame_id']);
            $rows[] = [
                'person_id' => (string) $row['person_id'], 'frame_id' => (string) $row['frame_id'], 'discipline_code' => $frame?->disciplineCode,
                'mention' => (string) $row['mention'], 'perfection_mastered' => (int) $row['perfection_mastered'] === 1,
                'to_remediate' => $remediation[(string) $row['person_id']][(string) $row['frame_id']] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * Valide et normalise les cases saisies.
     *
     * @return list<array{person_id: string, frame_id: string, criterion_id: string, occasion_no: int, outcome: string}>
     */
    private function cells(mixed $value, UnitContext $context, MentionRules $rules): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > self::MAX_CELLS) {
            throw HttpException::validation(['checks' => ['Liste de cases attendue (1 à ' . self::MAX_CELLS . ').']]);
        }
        $criteria = $context->criteria();
        $errors = [];
        $cells = [];
        foreach ($value as $index => $cell) {
            $field = 'checks.' . $index;
            if (!is_array($cell)) {
                $errors[$field][] = 'Case invalide.';
                continue;
            }
            $personId = $cell['person_id'] ?? null;
            $criterionId = $cell['criterion_id'] ?? null;
            $occasion = $cell['occasion_no'] ?? null;
            $outcome = $cell['outcome'] ?? null;
            if (!is_string($personId) || !in_array($personId, $context->pupilIds, true)) {
                $errors[$field . '.person_id'][] = 'Élève absent de la classe.';
            }
            if (!is_string($criterionId) || !isset($criteria[$criterionId])) {
                $errors[$field . '.criterion_id'][] = 'Critère absent du référentiel de ce niveau.';
            }
            if (!is_int($occasion) || $occasion < 1 || $occasion > $rules->occasions) {
                $errors[$field . '.occasion_no'][] = 'Occasion de 1 à ' . $rules->occasions . ' attendue.';
            }
            if (!is_string($outcome) || !in_array($outcome, MentionCalculator::OUTCOMES, true)) {
                $errors[$field . '.outcome'][] = 'Valeur attendue : ' . implode(', ', MentionCalculator::OUTCOMES) . '.';
            }
            if (is_string($personId) && is_string($criterionId) && isset($criteria[$criterionId]) && is_int($occasion) && is_string($outcome)) {
                $cells[$personId . '|' . $criterionId . '|' . $occasion] = [
                    'person_id' => $personId, 'frame_id' => $criteria[$criterionId]['frame']->id, 'criterion_id' => $criterionId, 'occasion_no' => $occasion, 'outcome' => $outcome,
                ];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        ksort($cells);

        return array_values($cells);
    }

    /**
     * @param list<string> $personIds
     * @return array<string, string>
     */
    public function names(string $tenantId, array $personIds): array
    {
        $names = [];
        foreach ($this->people->findMany($tenantId, $personIds) as $person) {
            $names[$person->id] = $person->fullName();
        }

        return $names;
    }

    public function numericScoresEnabled(string $tenantId, string $establishmentId): bool
    {
        return $this->rules->bool($tenantId, $establishmentId, 'public_primary.evaluation.numeric_scores_enabled');
    }
}
