<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Referential\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\CriterionInfo;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\FrameInfo;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\UnitInfo;
use Scholaris\Modules\Apps\PublicPrimary\Referential\Infrastructure\PdoReferential;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Référentiel national versionné (EX-EVA-01, T660) et calendrier des unités d'apprentissage (EX-EVA-02, T661). Le référentiel officiel
 * n'est pas publié (Q8) : un jeu d'exemple `example_*` (repris de l'exemple « Paul E. » du CDC) peut être importé pour démarrer ; il est
 * marqué comme tel dans `source_ref`. Le nombre d'unités et la durée de la semaine d'évaluation sont des paramètres (Rules).
 */
final class ReferentialService
{
    public const SUBSYSTEMS = ['francophone', 'anglophone'];
    public const EXAMPLE_SOURCE = 'Exemple du CDC public (cas « Paul E. », CE1), à remplacer par le référentiel officiel validé (Q8)';

    public function __construct(
        private readonly PdoReferential $repository,
        private readonly Rules $rules,
        private readonly PrimaryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Nouvelle version d'une discipline (jamais de réécriture d'une version existante).
     *
     * @param array<string, mixed> $input
     */
    public function createFrame(Principal $principal, array $input): FrameInfo
    {
        $errors = [];
        $subsystem = $input['subsystem'] ?? null;
        if (!is_string($subsystem) || !in_array($subsystem, self::SUBSYSTEMS, true)) {
            $errors['subsystem'][] = 'Valeur attendue : ' . implode(', ', self::SUBSYSTEMS) . '.';
        }
        $level = Input::text($input['level_code'] ?? null, 16, 'level_code', $errors, true);
        $discipline = Input::text($input['discipline_code'] ?? null, 40, 'discipline_code', $errors, true);
        if ($discipline !== null && preg_match('/^[a-z][a-z0-9_]*$/', $discipline) !== 1) {
            $errors['discipline_code'][] = 'Code en minuscules, chiffres et soulignés.';
        }
        $labelFr = Input::text($input['label_fr'] ?? null, 160, 'label_fr', $errors, true);
        $labelEn = Input::text($input['label_en'] ?? null, 160, 'label_en', $errors, true);
        $validFrom = $input['valid_from'] ?? $this->clock->today();
        if (!Input::isDate($validFrom)) {
            $errors['valid_from'][] = 'Date AAAA-MM-JJ attendue.';
        }
        $isKey = $input['is_key_discipline'] ?? false;
        if (!is_bool($isKey)) {
            $errors['is_key_discipline'][] = 'Booléen attendu.';
        }
        $source = Input::text($input['source_ref'] ?? null, 200, 'source_ref', $errors);
        $criteria = $this->criteria($input['criteria'] ?? null, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $version = $this->repository->nextVersion($principal->tenantId, (string) $subsystem, (string) $level, (string) $discipline);
        $id = $this->repository->insertFrame($principal->tenantId, [
            'subsystem' => $subsystem, 'level_code' => $level, 'discipline_code' => $discipline, 'label_fr' => $labelFr, 'label_en' => $labelEn,
            'version' => $version, 'valid_from' => $validFrom, 'is_key_discipline' => $isKey === true, 'source_ref' => $source,
        ], $criteria, $principal->userId, $this->clock->now());
        $this->audit->append($principal->tenantId, $principal->userId, 'public_primary.referential.frame_created', 'pp_frame', $id, null, [
            'subsystem' => $subsystem, 'level_code' => $level, 'discipline_code' => $discipline, 'version' => $version,
        ]);

        return $this->repository->frame($principal->tenantId, $id) ?? throw HttpException::notFound();
    }

    /**
     * Importe le référentiel d'exemple (trois disciplines clés, trois critères minimaux et un critère de perfectionnement chacune).
     *
     * @return list<FrameInfo>
     */
    public function importExample(Principal $principal, string $subsystem, string $levelCode, ?string $validFrom): array
    {
        $frames = [];
        foreach (ExampleReferential::disciplines($subsystem) as $discipline) {
            $frames[] = $this->createFrame($principal, $discipline + [
                'subsystem' => $subsystem, 'level_code' => $levelCode, 'is_key_discipline' => true, 'source_ref' => self::EXAMPLE_SOURCE,
                'valid_from' => $validFrom ?? $this->clock->today(),
            ]);
        }

        return $frames;
    }

    /**
     * Génère les unités de l'année pour un niveau (EX-EVA-02) : `public_primary.evaluation.units_per_year` unités réparties entre les
     * trimestres fournis au prorata de leur durée, découpées en parts égales ; la semaine d'évaluation est la fin de chaque unité
     * (`public_primary.evaluation.evaluation_week_days`) ; la dernière unité porte l'évaluation d'intégration de fin d'année.
     *
     * @param array<string, mixed> $input year_label, level_code, trimesters [{period_id, starts_on, ends_on}], units_count?
     * @return list<UnitInfo>
     */
    public function generateUnits(Principal $principal, string $establishmentId, array $input): array
    {
        $errors = [];
        $year = $input['year_label'] ?? null;
        if (!Input::isYearLabel($year)) {
            $errors['year_label'][] = 'Année scolaire AAAA-AAAA attendue.';
        }
        $level = Input::text($input['level_code'] ?? null, 16, 'level_code', $errors, true);
        $trimesters = $this->trimesters($input['trimesters'] ?? null, $errors);
        $count = $input['units_count'] ?? $this->rules->int($principal->tenantId, $establishmentId, 'public_primary.evaluation.units_per_year');
        if (!is_int($count) || $count < 1 || $count > 40) {
            $errors['units_count'][] = 'Nombre d\'unités entier de 1 à 40 attendu.';
        }
        if ($errors === [] && is_int($count) && $count < count($trimesters)) {
            $errors['units_count'][] = 'Au moins une unité par trimestre.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $weekDays = max(1, $this->rules->int($principal->tenantId, $establishmentId, 'public_primary.evaluation.evaluation_week_days'));

        $units = [];
        $number = 0;
        foreach (self::allocate((int) $count, $trimesters) as $index => $share) {
            $trimester = $trimesters[$index];
            $start = new DateTimeImmutable($trimester['starts_on']);
            $days = (int) $start->diff(new DateTimeImmutable($trimester['ends_on']))->days + 1;
            for ($i = 0; $i < $share; ++$i) {
                $from = $start->modify('+' . intdiv($days * $i, $share) . ' days');
                $to = $start->modify('+' . (intdiv($days * ($i + 1), $share) - 1) . ' days');
                $evaluation = $to->modify('-' . ($weekDays - 1) . ' days');
                ++$number;
                $units[] = new UnitInfo(
                    '',
                    (string) $year,
                    (string) $level,
                    $number,
                    $trimester['period_id'],
                    $from->format('Y-m-d'),
                    $to->format('Y-m-d'),
                    ($evaluation < $from ? $from : $evaluation)->format('Y-m-d'),
                    $number === (int) $count,
                );
            }
        }
        $saved = [];
        foreach ($units as $unit) {
            $id = $this->repository->saveUnit($principal->tenantId, $unit, $principal->userId, $this->clock->now());
            $saved[] = $this->repository->unit($principal->tenantId, $id) ?? throw HttpException::notFound();
        }
        $this->audit->append($principal->tenantId, $principal->userId, 'public_primary.referential.units_generated', 'pp_learning_units', $year . ':' . $level, null, ['count' => count($saved)]);

        return $saved;
    }

    /**
     * Enregistre (crée ou remplace) une unité précise.
     *
     * @param array<string, mixed> $input
     */
    public function saveUnit(Principal $principal, array $input): UnitInfo
    {
        $errors = [];
        $year = $input['year_label'] ?? null;
        if (!Input::isYearLabel($year)) {
            $errors['year_label'][] = 'Année scolaire AAAA-AAAA attendue.';
        }
        $level = Input::text($input['level_code'] ?? null, 16, 'level_code', $errors, true);
        $number = $input['unit_no'] ?? null;
        if (!is_int($number) || $number < 1) {
            $errors['unit_no'][] = 'Numéro d\'unité entier positif attendu.';
        }
        $period = Input::text($input['period_id'] ?? null, 40, 'period_id', $errors, true);
        foreach (['starts_on', 'ends_on', 'evaluation_week_from'] as $field) {
            if (!Input::isDate($input[$field] ?? null)) {
                $errors[$field][] = 'Date AAAA-MM-JJ attendue.';
            }
        }
        if ($errors === [] && !((string) $input['starts_on'] <= (string) $input['evaluation_week_from'] && (string) $input['evaluation_week_from'] <= (string) $input['ends_on'])) {
            $errors['evaluation_week_from'][] = 'La semaine d\'évaluation doit tomber dans l\'unité.';
        }
        $final = $input['is_final_integration'] ?? false;
        if (!is_bool($final)) {
            $errors['is_final_integration'][] = 'Booléen attendu.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = $this->repository->saveUnit($principal->tenantId, new UnitInfo('', (string) $year, (string) $level, (int) $number, (string) $period, (string) $input['starts_on'], (string) $input['ends_on'], (string) $input['evaluation_week_from'], $final === true), $principal->userId, $this->clock->now());

        return $this->repository->unit($principal->tenantId, $id) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed> */
    public static function frameResource(FrameInfo $frame): array
    {
        return [
            'id' => $frame->id, 'subsystem' => $frame->subsystem, 'level_code' => $frame->levelCode, 'discipline_code' => $frame->disciplineCode,
            'label_fr' => $frame->labelFr, 'label_en' => $frame->labelEn, 'version' => $frame->version, 'valid_from' => $frame->validFrom,
            'is_key_discipline' => $frame->isKeyDiscipline,
            'criteria' => array_map(static fn (CriterionInfo $c): array => ['id' => $c->id, 'code' => $c->code, 'label_fr' => $c->labelFr, 'label_en' => $c->labelEn, 'kind' => $c->kind, 'sort_order' => $c->sortOrder], $frame->criteria),
        ];
    }

    /** @return array<string, mixed> */
    public static function unitResource(UnitInfo $unit): array
    {
        return [
            'id' => $unit->id, 'year_label' => $unit->yearLabel, 'level_code' => $unit->levelCode, 'unit_no' => $unit->unitNo, 'period_id' => $unit->periodId,
            'starts_on' => $unit->startsOn, 'ends_on' => $unit->endsOn, 'evaluation_week_from' => $unit->evaluationWeekFrom, 'is_final_integration' => $unit->isFinalIntegration,
        ];
    }

    /**
     * Répartition du nombre d'unités entre les trimestres au prorata de leur durée (au moins une par trimestre, plus forts restes).
     *
     * @param list<array{period_id: string, starts_on: string, ends_on: string}> $trimesters
     * @return list<int>
     */
    public static function allocate(int $count, array $trimesters): array
    {
        $days = [];
        foreach ($trimesters as $trimester) {
            $days[] = (int) (new DateTimeImmutable($trimester['starts_on']))->diff(new DateTimeImmutable($trimester['ends_on']))->days + 1;
        }
        $total = max(1, array_sum($days));
        $shares = [];
        $rests = [];
        foreach ($days as $index => $length) {
            $exact = $count * $length / $total;
            $shares[$index] = max(1, (int) floor($exact));
            $rests[$index] = $exact - floor($exact);
        }
        arsort($rests);
        while (array_sum($shares) < $count) {
            foreach (array_keys($rests) as $index) {
                if (array_sum($shares) >= $count) {
                    break;
                }
                ++$shares[$index];
            }
        }
        while ($shares !== [] && array_sum($shares) > $count) {
            $largest = (int) array_search(max($shares), $shares, true);
            --$shares[$largest];
        }

        return array_values($shares);
    }

    /**
     * @param array<string, list<string>> $errors
     * @return list<array{code: string, label_fr: string, label_en: string, kind: string}>
     */
    private function criteria(mixed $value, array &$errors): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            $errors['criteria'][] = 'Liste de critères attendue.';

            return [];
        }
        $criteria = [];
        $codes = [];
        $minimal = 0;
        foreach ($value as $index => $criterion) {
            $field = 'criteria.' . $index;
            if (!is_array($criterion)) {
                $errors[$field][] = 'Critère invalide.';
                continue;
            }
            $code = Input::text($criterion['code'] ?? null, 8, $field . '.code', $errors, true);
            $fr = Input::text($criterion['label_fr'] ?? null, 200, $field . '.label_fr', $errors, true);
            $en = Input::text($criterion['label_en'] ?? null, 200, $field . '.label_en', $errors, true);
            $kind = $criterion['kind'] ?? CriterionInfo::MINIMAL;
            if (!in_array($kind, [CriterionInfo::MINIMAL, CriterionInfo::PERFECTION], true)) {
                $errors[$field . '.kind'][] = 'Valeur attendue : minimal ou perfection.';
                continue;
            }
            if ($code !== null && in_array($code, $codes, true)) {
                $errors[$field . '.code'][] = 'Code déjà utilisé dans cette discipline.';
            }
            $codes[] = (string) $code;
            $minimal += $kind === CriterionInfo::MINIMAL ? 1 : 0;
            $criteria[] = ['code' => (string) $code, 'label_fr' => (string) $fr, 'label_en' => (string) $en, 'kind' => (string) $kind];
        }
        if ($minimal === 0) {
            $errors['criteria'][] = 'Au moins un critère minimal.';
        }

        return $criteria;
    }

    /**
     * @param array<string, list<string>> $errors
     * @return list<array{period_id: string, starts_on: string, ends_on: string}>
     */
    private function trimesters(mixed $value, array &$errors): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            $errors['trimesters'][] = 'Liste des trimestres (period_id, starts_on, ends_on) attendue.';

            return [];
        }
        $list = [];
        $previousEnd = '';
        foreach ($value as $index => $trimester) {
            $field = 'trimesters.' . $index;
            if (!is_array($trimester) || !is_string($trimester['period_id'] ?? null) || $trimester['period_id'] === ''
                || !Input::isDate($trimester['starts_on'] ?? null) || !Input::isDate($trimester['ends_on'] ?? null)
                || $trimester['starts_on'] > $trimester['ends_on'] || $trimester['starts_on'] <= $previousEnd) {
                $errors[$field][] = 'Trimestre invalide : période, dates AAAA-MM-JJ croissantes, sans chevauchement.';
                continue;
            }
            $previousEnd = (string) $trimester['ends_on'];
            $list[] = ['period_id' => (string) $trimester['period_id'], 'starts_on' => (string) $trimester['starts_on'], 'ends_on' => (string) $trimester['ends_on']];
        }

        return $list;
    }
}
