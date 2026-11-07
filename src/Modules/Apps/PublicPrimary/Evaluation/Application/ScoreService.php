<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoEvaluation;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;

/**
 * Option « notes chiffrées » (EX-EVA-08, T666) : désactivée par défaut (`public_primary.evaluation.numeric_scores_enabled`), barème
 * `numeric_scale_max` (défaut /20, à confirmer). Une note accompagne la mention, ne la remplace jamais et ne sert à aucun rang (RG-EVA-1).
 * Les épreuves blanches de CEP/FSLC peuvent aussi passer par le moteur de notes du socle (/assessments, /grades).
 */
final class ScoreService
{
    public function __construct(
        private readonly ContextResolver $contexts,
        private readonly PdoEvaluation $repository,
        private readonly EvaluationService $evaluation,
        private readonly Rules $rules,
        private readonly PrimaryClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input structure_id, scores [{person_id, frame_id, score}]
     * @return array{saved: int, scale_max: int}
     */
    public function save(Principal $principal, string $establishmentId, string $unitId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $structureId = is_string($input['structure_id'] ?? null) ? (string) $input['structure_id'] : '';
        $this->evaluation->writer($principal, $establishmentId, $structureId, $unitId);
        if (!$this->rules->bool($tenantId, $establishmentId, 'public_primary.evaluation.numeric_scores_enabled')) {
            throw HttpException::conflict('Les notes chiffrées ne sont pas activées (paramètre public_primary.evaluation.numeric_scores_enabled).');
        }
        $context = $this->contexts->resolve($tenantId, $establishmentId, $unitId, $structureId);
        $max = max(1, $this->rules->int($tenantId, $establishmentId, 'public_primary.evaluation.numeric_scale_max'));
        $scores = $input['scores'] ?? null;
        if (!is_array($scores) || !array_is_list($scores) || $scores === []) {
            throw HttpException::validation(['scores' => ['Liste de notes attendue.']]);
        }
        $errors = [];
        $valid = [];
        foreach ($scores as $index => $line) {
            $person = is_array($line) ? ($line['person_id'] ?? null) : null;
            $frame = is_array($line) ? ($line['frame_id'] ?? null) : null;
            $score = is_array($line) ? ($line['score'] ?? null) : null;
            if (!is_string($person) || !in_array($person, $context->pupilIds, true) || !is_string($frame) || $context->frame($frame) === null
                || !(is_int($score) || is_float($score)) || $score < 0 || $score > $max) {
                $errors['scores.' . $index][] = 'Élève de la classe, discipline du référentiel et note de 0 à ' . $max . ' attendus.';
                continue;
            }
            $valid[] = [$person, $frame, (int) round($score * 100)];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        foreach ($valid as [$person, $frame, $hundredths]) {
            $this->repository->saveScore($tenantId, $establishmentId, $unitId, $structureId, $person, $frame, $hundredths, $max, $principal->userId, $this->clock->now());
        }

        return ['saved' => count($valid), 'scale_max' => $max];
    }

    /** @return list<array{person_id: string, frame_id: string, score: float, scale_max: int}> */
    public function list(string $tenantId, string $establishmentId, string $unitId, string $structureId): array
    {
        $context = $this->contexts->resolve($tenantId, $establishmentId, $unitId, $structureId);

        return array_map(static fn (array $row): array => [
            'person_id' => (string) $row['person_id'], 'frame_id' => (string) $row['frame_id'], 'score' => (int) $row['score_hundredths'] / 100, 'scale_max' => (int) $row['scale_max'],
        ], $this->repository->scores($tenantId, $establishmentId, $unitId, $context->pupilIds));
    }
}
