<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\Referential;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\UnitInfo;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Domain\MentionRules;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoEvaluation;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoReportCards;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryScope;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PublicMessages;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilRegistry;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentReissuer;

/**
 * Bulletins de la Primaire publique (EX-EVA-06, 07, T664) : bulletin d'unité (DOC-08), bulletin trimestriel (DOC-09), livret annuel
 * (DOC-10). Préparés en brouillon par l'enseignant (appréciation, banque de formulations), publiés par le directeur seul (API-07,
 * RG-EVA-2) qui émet le document officiel (numéro, QR pour DOC-09 et DOC-10) et prévient les familles (NOT-04) ; le bulletin est alors
 * figé. Une mention ne change ensuite que par le directeur, avec motif : réédition « Rectificatif » au même numéro (RG-EVA-3, PP15).
 * Jamais de rang (RG-EVA-1) ni de donnée APEE (RG-DOC-3) : le contenu ne lit que l'évaluation.
 */
final class ReportCardService
{
    public const KINDS = ['unit', 'trimester', 'year'];
    public const DOCUMENT_TYPES = ['unit' => 'pp_doc08_unit_report', 'trimester' => 'pp_doc09_trimester_report', 'year' => 'pp_doc10_annual_booklet'];
    public const NOTICE_TEMPLATE = 'pub.not04.parent';

    public function __construct(
        private readonly PdoReportCards $cards,
        private readonly PdoEvaluation $evaluation,
        private readonly MentionReader $mentions,
        private readonly ContextResolver $contexts,
        private readonly Referential $referential,
        private readonly ClassRoster $classes,
        private readonly PupilRegistry $pupils,
        private readonly EvaluationService $service,
        private readonly PrimaryScope $scope,
        private readonly DocumentIssuer $documents,
        private readonly DocumentReissuer $reissuer,
        private readonly PublicMessages $messages,
        private readonly PrimaryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Prépare (ou met à jour) les brouillons d'une classe pour une portée : unité (`scope_ref` = unité), trimestre (`scope_ref` = période,
     * `year_label`, `level_code`) ou année (`scope_ref` = année scolaire, `level_code`).
     *
     * @param array<string, mixed> $input kind, scope_ref, structure_id, year_label?, level_code?
     * @return list<array<string, mixed>>
     */
    public function generate(Principal $principal, string $establishmentId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $errors = [];
        $kind = $input['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            $errors['kind'][] = 'Valeur attendue : ' . implode(', ', self::KINDS) . '.';
        }
        $ref = Input::text($input['scope_ref'] ?? null, 40, 'scope_ref', $errors, true);
        $structureId = Input::text($input['structure_id'] ?? null, 40, 'structure_id', $errors, true);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $this->service->writer($principal, $establishmentId, (string) $structureId, (string) $ref);
        $class = $this->classes->find($tenantId, $establishmentId, (string) $structureId) ?? throw HttpException::notFound();
        [$year, $level] = $this->yearAndLevel($tenantId, (string) $kind, (string) $ref, $input, $class->levelCodes);
        $at = $this->clock->now();
        $ids = [];
        foreach ($this->classes->pupilIdsIn($tenantId, $establishmentId, (string) $structureId, $this->clock->today()) as $personId) {
            $content = $this->content($tenantId, $establishmentId, $personId, (string) $kind, (string) $ref, $year, $level, $class->label, (string) $structureId);
            $existing = $this->cards->forScope($tenantId, $personId, (string) $kind, (string) $ref);
            if ($existing === null) {
                $ids[] = $this->cards->insert($tenantId, $establishmentId, $personId, (string) $structureId, (string) $kind, (string) $ref, $year, $content, $principal->userId, $at);
            } else {
                $this->cards->refreshDraft($tenantId, (string) $existing['id'], $content, $at);
                $ids[] = (string) $existing['id'];
            }
        }
        $this->audit->append($tenantId, $principal->userId, 'public_primary.report_cards.generated', 'pp_report_cards', (string) $ref, null, ['kind' => $kind, 'structure_id' => $structureId, 'count' => count($ids)]);

        return array_map(fn (string $id): array => self::resource($this->cards->find($tenantId, $establishmentId, $id) ?? []), $ids);
    }

    /**
     * @param array<string, mixed> $input appraisal
     * @return array<string, mixed>
     */
    public function appraise(Principal $principal, string $establishmentId, string $cardId, array $input): array
    {
        $card = $this->card($principal->tenantId, $establishmentId, $cardId);
        $this->service->writer($principal, $establishmentId, (string) $card['structure_id'], $cardId);
        if ($card['card_state'] !== 'draft') {
            throw HttpException::conflict('Bulletin publié : il est figé (RG-EVA-2).');
        }
        $errors = [];
        $appraisal = Input::text($input['appraisal'] ?? null, 1000, 'appraisal', $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $this->cards->setAppraisal($principal->tenantId, $cardId, $appraisal, $this->clock->now());

        return self::resource($this->card($principal->tenantId, $establishmentId, $cardId));
    }

    /**
     * API-07 : publication par le directeur, émission du document officiel, NOT-04 aux familles.
     *
     * @return array<string, mixed>
     */
    public function publish(Principal $principal, string $establishmentId, string $cardId, string $locale): array
    {
        $tenantId = $principal->tenantId;
        $card = $this->card($tenantId, $establishmentId, $cardId);
        $reach = $this->scope->reach($principal, 'public_primary.evaluation.publish', $establishmentId);
        $this->scope->assertWholeSchool($principal, $reach, 'pp_report_card', $cardId, 'Seul le directeur publie un bulletin (RG-EVA-2).');
        if ($card['card_state'] !== 'draft') {
            throw HttpException::conflict('Bulletin déjà publié.');
        }
        $class = $this->classes->find($tenantId, $establishmentId, (string) $card['structure_id']) ?? throw HttpException::notFound();
        $previous = (array) json_decode((string) $card['content'], true);
        $content = $this->content($tenantId, $establishmentId, (string) $card['person_id'], (string) $card['card_kind'], (string) $card['scope_ref'], (string) $card['year_label'], (string) ($previous['level_code'] ?? ($class->levelCodes[0] ?? '')), $class->label, (string) $card['structure_id']);
        $content['appraisal'] = $card['appraisal'];
        $document = $this->documents->issue($tenantId, $establishmentId, self::DOCUMENT_TYPES[(string) $card['card_kind']], (string) $card['person_id'], self::documentData($content, $locale), $principal->userId, $locale === 'en' ? 'en' : 'fr');
        $this->cards->publish($tenantId, $cardId, $content, $document->id, $principal->userId, $this->clock->now());
        $this->messages->notifyGuardians($tenantId, $establishmentId, (string) $card['person_id'], self::NOTICE_TEMPLATE, [
            'school_year' => (string) $card['year_label'], 'details' => self::summary($content, 'fr'),
        ]);
        $this->audit->append($tenantId, $principal->userId, 'public_primary.report_card.published', 'pp_report_card', $cardId, null, ['document_id' => $document->id, 'number' => $document->number]);

        return self::resource($this->card($tenantId, $establishmentId, $cardId)) + ['document' => ['id' => $document->id, 'number' => $document->number]];
    }

    /**
     * RG-EVA-3 : le directeur modifie une mention d'un bulletin publié, avec motif ; le document est réédité « Rectificatif ».
     *
     * @param array<string, mixed> $input frame_id, mention, reason
     * @return array<string, mixed>
     */
    public function rectify(Principal $principal, string $establishmentId, string $cardId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $card = $this->card($tenantId, $establishmentId, $cardId);
        $reach = $this->scope->reach($principal, 'public_primary.evaluation.rectify', $establishmentId);
        $this->scope->assertWholeSchool($principal, $reach, 'pp_report_card', $cardId, 'Seul le directeur modifie une mention publiée (RG-EVA-3).');
        if ($card['card_state'] === 'draft') {
            throw HttpException::conflict('Bulletin non publié : corrigez la saisie des critères.');
        }
        $errors = [];
        $reason = Input::text($input['reason'] ?? null, 500, 'reason', $errors, true);
        $frameId = $input['frame_id'] ?? null;
        $mention = $input['mention'] ?? null;
        $codes = array_column($this->contexts->scale($tenantId, $establishmentId), 'code');
        if (!is_string($mention) || !in_array($mention, [...$codes, MentionRules::NOT_ASSESSED], true)) {
            $errors['mention'][] = 'Mention attendue : ' . implode(', ', $codes) . ' ou not_assessed.';
        }
        $content = (array) json_decode((string) $card['content'], true);
        $index = null;
        foreach ((array) ($content['disciplines'] ?? []) as $i => $line) {
            if (is_array($line) && ($line['frame_id'] ?? null) === $frameId) {
                $index = $i;
            }
        }
        if ($index === null) {
            $errors['frame_id'][] = 'Discipline absente du bulletin.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $before = $content['disciplines'][$index]['mention'];
        $content['disciplines'][$index]['mention'] = $mention;
        $content['rectified'] = true;
        $this->evaluation->overrideMention($tenantId, $establishmentId, (string) $card['person_id'], (string) $frameId, (string) $card['structure_id'], (string) $card['card_kind'], (string) $card['scope_ref'], (string) $mention, (string) $reason, $principal->userId, $this->clock->now());
        $documentId = $card['document_id'] === null ? null : (string) $card['document_id'];
        if ($documentId !== null) {
            $document = $this->reissuer->reissue($tenantId, $establishmentId, $documentId, self::documentData($content, 'fr'), (string) $reason, $principal->userId);
            $documentId = $document->id;
        }
        $this->cards->rectify($tenantId, $cardId, $content, $documentId, (string) $reason, $this->clock->now());
        $this->audit->append($tenantId, $principal->userId, 'public_primary.report_card.rectified', 'pp_report_card', $cardId, ['frame_id' => $frameId, 'mention' => $before], ['frame_id' => $frameId, 'mention' => $mention, 'reason' => $reason]);

        return self::resource($this->card($tenantId, $establishmentId, $cardId));
    }

    /** @return array<string, mixed> */
    public function card(string $tenantId, string $establishmentId, string $cardId): array
    {
        return $this->cards->find($tenantId, $establishmentId, $cardId) ?? throw HttpException::notFound('Bulletin introuvable.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        if ($row === []) {
            return [];
        }

        return [
            'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'structure_id' => (string) $row['structure_id'],
            'kind' => (string) $row['card_kind'], 'scope_ref' => (string) $row['scope_ref'], 'year_label' => (string) $row['year_label'],
            'state' => (string) $row['card_state'], 'appraisal' => $row['appraisal'], 'document_id' => $row['document_id'],
            'published_at' => $row['published_at'], 'rectified_reason' => $row['rectified_reason'],
            'content' => (array) json_decode((string) $row['content'], true),
        ];
    }

    /**
     * Contenu du bulletin : mentions par discipline (et des unités du trimestre), critère de perfectionnement, remédiation prévue.
     *
     * @return array<string, mixed>
     */
    private function content(string $tenantId, string $establishmentId, string $personId, string $kind, string $ref, string $year, string $level, string $classLabel, string $structureId): array
    {
        $subsystem = $this->contexts->subsystem($tenantId, $establishmentId);
        $periodLabel = ['fr' => '', 'en' => ''];
        $remediation = [];
        if ($kind === 'unit') {
            $unit = $this->referential->unit($tenantId, $ref) ?? throw HttpException::notFound('Unité introuvable.');
            $context = $this->contexts->resolve($tenantId, $establishmentId, $ref, $structureId);
            $mentions = [];
            foreach ($this->evaluation->mentionsFor($tenantId, $establishmentId, 'unit', $ref, [$personId]) as $row) {
                $mentions[(string) $row['frame_id']] = $row;
            }
            $disciplines = [];
            foreach ($context->frames as $frame) {
                $disciplines[] = [
                    'frame_id' => $frame->id, 'discipline_code' => $frame->disciplineCode, 'label_fr' => $frame->labelFr, 'label_en' => $frame->labelEn,
                    'mention' => isset($mentions[$frame->id]) ? (string) $mentions[$frame->id]['mention'] : MentionRules::NOT_ASSESSED,
                    'perfection' => isset($mentions[$frame->id]) && (int) $mentions[$frame->id]['perfection_mastered'] === 1,
                ];
            }
            foreach ($this->evaluation->remediationsOfUnit($tenantId, $establishmentId, $ref, [$personId]) as $row) {
                $item = $context->criteria()[(string) $row['criterion_id']] ?? null;
                if ($item !== null) {
                    $remediation[] = $item['frame']->labelFr . ', ' . $item['criterion']->code . ' ' . $item['criterion']->labelFr;
                }
            }
            $periodLabel = ['fr' => 'Unité ' . $unit->unitNo, 'en' => 'Unit ' . $unit->unitNo];
        } elseif ($kind === 'trimester') {
            $disciplines = $this->mentions->trimester($tenantId, $establishmentId, $personId, $year, $level, $subsystem, $ref);
            $ordinal = $this->trimesterOrdinal($tenantId, $year, $level, $ref);
            $periodLabel = ['fr' => 'Trimestre ' . $ordinal, 'en' => 'Term ' . $ordinal];
            foreach ($this->evaluation->remediationsOfPerson($tenantId, $establishmentId, $personId) as $row) {
                if ($row['remediation_state'] !== 'resolved') {
                    $remediation[] = (string) $row['criterion_id'];
                }
            }
            $remediation = $remediation === [] ? [] : [count($remediation) . ' critère(s) en remédiation'];
        } else {
            $disciplines = array_map(static fn (array $line): array => $line + ['perfection' => false], $this->mentions->annual($tenantId, $establishmentId, $personId, $year, $level, $subsystem));
            $periodLabel = ['fr' => 'Année ' . $year, 'en' => 'Year ' . $year];
        }
        $pupil = $this->pupils->find($tenantId, $personId);

        return [
            'kind' => $kind, 'year_label' => $year, 'level_code' => $level, 'class_label' => $classLabel, 'period_label_fr' => $periodLabel['fr'],
            'period_label_en' => $periodLabel['en'], 'learner_number' => $pupil?->learnerNumber, 'birth_date' => $pupil?->birthDate,
            'disciplines' => $disciplines, 'remediation' => $remediation,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $classLevels
     * @return array{0: string, 1: string}
     */
    private function yearAndLevel(string $tenantId, string $kind, string $ref, array $input, array $classLevels): array
    {
        if ($kind === 'unit') {
            $unit = $this->referential->unit($tenantId, $ref);
            if ($unit instanceof UnitInfo) {
                return [$unit->yearLabel, $unit->levelCode];
            }
        }
        $year = $kind === 'year' ? $ref : ($input['year_label'] ?? null);
        $level = $input['level_code'] ?? ($classLevels[0] ?? null);
        if (!Input::isYearLabel($year) || !is_string($level) || !in_array($level, $classLevels, true)) {
            throw HttpException::validation(['year_label' => ['Année scolaire AAAA-AAAA et niveau de la classe requis.']]);
        }

        return [(string) $year, $level];
    }

    private function trimesterOrdinal(string $tenantId, string $year, string $level, string $periodId): int
    {
        $seen = [];
        foreach ($this->referential->unitsOf($tenantId, $year, $level) as $unit) {
            $seen[$unit->periodId] = true;
            if ($unit->periodId === $periodId) {
                return count($seen);
            }
        }

        return count($seen) + 1;
    }

    /**
     * Variables du modèle de document (aucune variable APEE : RG-DOC-3).
     *
     * @param array<string, mixed> $content
     * @return array<string, scalar|null>
     */
    public static function documentData(array $content, string $locale): array
    {
        return [
            'school_year' => (string) ($content['year_label'] ?? ''),
            'class_name' => (string) ($content['class_label'] ?? ''),
            'learner_number' => (string) ($content['learner_number'] ?? ''),
            'period_label' => (string) ($content[$locale === 'en' ? 'period_label_en' : 'period_label_fr'] ?? ''),
            'disciplines_block' => self::disciplinesBlock($content, $locale),
            'remediation_block' => implode("\n", array_map('strval', (array) ($content['remediation'] ?? []))),
            'appraisal' => (string) ($content['appraisal'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $content */
    private static function disciplinesBlock(array $content, string $locale): string
    {
        $lines = [];
        foreach ((array) ($content['disciplines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $label = (string) ($line[$locale === 'en' ? 'label_en' : 'label_fr'] ?? '');
            $units = '';
            if (isset($line['units']) && is_array($line['units'])) {
                $units = ' (' . implode(', ', array_map(static fn (array $u): string => ($locale === 'en' ? 'U' : 'U') . $u['unit_no'] . ' ' . $u['mention'], $line['units'])) . ')';
            }
            $perfection = ($line['perfection'] ?? false) === true ? ($locale === 'en' ? ', excellence criterion mastered' : ', avec maîtrise du critère de perfectionnement') : '';
            $lines[] = $label . ' : ' . (string) ($line['mention'] ?? '') . $perfection . $units;
        }

        return implode("\n", $lines);
    }

    /** Résumé court des mentions pour le SMS NOT-04. */
    /** @param array<string, mixed> $content */
    public static function summary(array $content, string $locale): string
    {
        $parts = [];
        foreach ((array) ($content['disciplines'] ?? []) as $line) {
            if (is_array($line)) {
                $parts[] = (string) ($line[$locale === 'en' ? 'label_en' : 'label_fr'] ?? '') . ' ' . (string) ($line['mention'] ?? '');
            }
        }

        return implode(', ', $parts);
    }
}
