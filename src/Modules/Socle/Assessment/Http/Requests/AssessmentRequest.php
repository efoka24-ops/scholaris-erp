<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Requests;

use InvalidArgumentException;

/**
 * Requêtes du module Assessment : le contrôleur choisit le cas d'usage. Tout champ inconnu est refusé (tenant_id,
 * établissement, statut et versions viennent du serveur, jamais du client).
 */
final class AssessmentRequest extends ValidatedRequest
{
    public const SUBJECT = 'subject';
    public const SUBJECT_UPDATE = 'subject_update';
    public const ELEMENT = 'element';
    public const ASSESSMENT = 'assessment';
    public const ASSESSMENT_UPDATE = 'assessment_update';
    public const TRANSITION = 'transition';
    public const SHEET_TRANSITION = 'sheet_transition';
    public const ENTRY = 'entry';
    public const BATCH = 'batch';
    public const SYNC = 'sync';
    public const CORRECTION = 'correction';
    public const RULES = 'rules';
    public const COUNCIL = 'council';
    public const COUNCIL_LINE = 'council_line';
    public const DELIBERATION = 'deliberation';
    public const ATTENDANCE = 'attendance';
    public const DELIBERATION_LINE = 'deliberation_line';
    public const REOPEN = 'reopen';
    public const PUBLISH = 'publish';

    public function __construct(private readonly string $kind)
    {
    }

    protected function fields(): array
    {
        return match ($this->kind) {
            self::SUBJECT => [
                'code' => Field::string(40)->required(), 'name' => Field::string(160)->required(),
                'kind' => Field::enum(['subject', 'teaching_unit']), 'coefficient' => Field::number(0.0, 100.0), 'credits' => Field::int(0, 100),
                'elements' => Field::custom(fn (mixed $v): array => $this->elements($v)),
            ],
            self::SUBJECT_UPDATE => [
                'name' => Field::string(160), 'kind' => Field::enum(['subject', 'teaching_unit']), 'coefficient' => Field::number(0.0, 100.0),
                'credits' => Field::int(0, 100), 'active' => Field::bool(),
            ],
            self::ELEMENT => ['name' => Field::string(160)->required(), 'coefficient' => Field::number(0.0, 100.0), 'credits' => Field::int(0, 100)],
            self::ASSESSMENT => [
                'subject_id' => Field::id()->required(), 'element_id' => Field::id(), 'structure_id' => Field::id()->required(), 'period_id' => Field::id()->required(),
                'academic_year_id' => Field::id(), 'kind' => Field::string(40)->required(), 'title' => Field::string(160)->required(),
                'weight' => Field::number(0.01, 100.0), 'max_score' => Field::number(0.01, 1000.0), 'grading_mode' => Field::enum(['numeric', 'qualitative']),
                'anonymous' => Field::bool(), 'teacher_person_id' => Field::id(), 'held_on' => Field::date(),
                'score_step' => Field::number(0.01, 10.0), 'exam_session' => Field::enum(['normal', 'resit']),
            ],
            self::ASSESSMENT_UPDATE => [
                'title' => Field::string(160), 'kind' => Field::string(40), 'weight' => Field::number(0.01, 100.0),
                'max_score' => Field::number(0.01, 1000.0), 'held_on' => Field::date(), 'score_step' => Field::number(0.01, 10.0),
            ],
            self::TRANSITION => ['to' => Field::string(30)->required()],
            self::SHEET_TRANSITION => [
                'structure_id' => Field::id()->required(), 'period_id' => Field::id()->required(), 'subject_id' => Field::id(), 'to' => Field::string(30)->required(),
            ],
            self::ENTRY => ['assessment_id' => Field::id()->required(), 'person_id' => Field::id()->required()] + $this->cellFields(),
            self::BATCH => ['assessment_id' => Field::id()->required(), 'entries' => Field::custom(fn (mixed $v): array => $this->entries($v, false))->required()],
            self::SYNC => ['entries' => Field::custom(fn (mixed $v): array => $this->entries($v, true))->required()],
            self::CORRECTION => ['grade_id' => Field::id()->required(), 'reason' => Field::string(255)->required()] + $this->cellFields(false),
            self::RULES => [
                'academic_year_id' => Field::id()->required(), 'label' => Field::string(160)->required(), 'structure_id' => Field::id(),
                'definition' => Field::custom(static function (mixed $v): array {
                    if (!is_array($v) || ($v !== [] && array_is_list($v))) {
                        throw new InvalidArgumentException('La définition doit être un objet de règles, par exemple {"pass_mark": 10, "unjustified_absence": "excluded"}.');
                    }

                    return $v;
                })->required(),
            ],
            self::COUNCIL => [
                'structure_id' => Field::id()->required(), 'period_id' => Field::id()->required(), 'period_label' => Field::string(80),
                'held_on' => Field::date(), 'from' => Field::date(), 'to' => Field::date(),
            ],
            self::COUNCIL_LINE => [
                'decision' => Field::nullableString(60), 'reason' => Field::nullableString(255), 'appraisal' => Field::nullableString(255), 'conduct' => Field::nullableString(60),
            ],
            self::DELIBERATION => [
                'structure_id' => Field::id()->required(), 'period_id' => Field::id()->required(), 'period_label' => Field::string(80),
                'president_id' => Field::id()->required(), 'members_expected' => Field::int(1, 200)->required(),
                'exam_session' => Field::enum(['normal', 'resit']),
            ],
            self::ATTENDANCE => ['members_present' => Field::idList(200)->required()],
            self::DELIBERATION_LINE => ['decision' => Field::string(60)->required(), 'reason' => Field::nullableString(255)],
            self::REOPEN => ['reason' => Field::string(255)->required()],
            'batch_entry' => ['person_id' => Field::id()->required(), 'expected_version' => Field::int(0, 1000000)] + $this->cellFields(false),
            'sync_entry' => [
                'client_uuid' => Field::string(40)->required(), 'assessment_id' => Field::id()->required(), 'person_id' => Field::id()->required(),
                'base_version' => Field::int(0, 1000000)->required(),
            ] + $this->cellFields(false),
            default => ['structure_id' => Field::id()->required(), 'period_id' => Field::id()->required(), 'period_label' => Field::string(80), 'exam_session' => Field::enum(['normal', 'resit'])],
        };
    }

    /** @return array<string, Field> */
    private function cellFields(bool $withVersioning = true): array
    {
        $fields = [
            'value' => Field::custom(static function (mixed $v): ?float {
                if ($v === null) {
                    return null;
                }
                if (!is_int($v) && !is_float($v)) {
                    throw new InvalidArgumentException('La note doit être un nombre (ou null pour l\'effacer).');
                }

                return (float) $v;
            }),
            'absence' => Field::custom(static function (mixed $v): ?string {
                // Codes du socle (justified, unjustified) ou déclarés par l'application (assessment.absence_codes) : le
                // service vérifie la liste en vigueur.
                if ($v !== null && (!is_string($v) || preg_match('/^[a-z][a-z0-9_]{0,11}$/', $v) !== 1)) {
                    throw new InvalidArgumentException('L\'absence vaut « justified » (justifiée), « unjustified » (non justifiée) ou un code déclaré par l\'établissement.');
                }

                return $v;
            }),
            'qualitative' => Field::nullableString(30),
            'remark' => Field::nullableString(255),
        ];
        if ($withVersioning) {
            $fields['expected_version'] = Field::int(0, 1000000);
            $fields['client_uuid'] = Field::string(40);
        }

        return $fields;
    }

    /** @return list<array<string, mixed>> */
    private function elements(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            throw new InvalidArgumentException('Les éléments forment une liste de 50 au plus : [{"name": "Algèbre", "coefficient": 2}].');
        }
        $out = [];
        foreach ($value as $index => $element) {
            if (!is_array($element)) {
                throw new InvalidArgumentException(sprintf('Élément %d : objet attendu.', $index + 1));
            }
            try {
                $out[] = (new self(self::ELEMENT))->validate($element);
            } catch (\Scholaris\Core\Http\HttpException $e) {
                throw new InvalidArgumentException(sprintf('Élément %d : %s', $index + 1, implode(' ', array_merge(...array_values($e->errors)))));
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function entries(mixed $value, bool $offline): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 1000) {
            throw new InvalidArgumentException('Envoyez de 1 à 1 000 lignes.');
        }
        $shape = new self($offline ? 'sync_entry' : 'batch_entry');
        $out = [];
        foreach ($value as $index => $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException(sprintf('Ligne %d : objet attendu.', $index + 1));
            }
            try {
                $out[] = $shape->validate($entry);
            } catch (\Scholaris\Core\Http\HttpException $e) {
                throw new InvalidArgumentException(sprintf('Ligne %d : %s', $index + 1, implode(' ', array_merge(...array_values($e->errors)))));
            }
        }

        return $out;
    }
}
