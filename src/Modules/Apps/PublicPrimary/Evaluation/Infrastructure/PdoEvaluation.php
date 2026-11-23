<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;

/**
 * Tables de l'évaluation (pp_criterion_checks, pp_check_batches, pp_mentions, pp_remediations, pp_remediation_sessions, pp_unit_scores),
 * toujours bornées par client et par école.
 */
final class PdoEvaluation
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /** @return array<string, mixed>|null */
    public function batch(string $tenantId, string $clientUuid): ?array
    {
        return $this->one('SELECT * FROM pp_check_batches WHERE tenant_id = ? AND client_uuid = ?', [$tenantId, $clientUuid]);
    }

    public function insertBatch(string $tenantId, string $establishmentId, string $unitId, string $structureId, string $clientUuid, string $fingerprint, int $cells, string $actorId, string $at): void
    {
        $this->pdo()->prepare('INSERT INTO pp_check_batches (id, tenant_id, establishment_id, unit_id, structure_id, client_uuid, fingerprint, cells_count, entered_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string) new Ulid(), $tenantId, $establishmentId, $unitId, $structureId, $clientUuid, $fingerprint, $cells, $actorId, $at]);
    }

    public function saveCell(string $tenantId, string $establishmentId, string $unitId, string $structureId, string $personId, string $frameId, string $criterionId, int $occasion, string $outcome, string $actorId, ?string $clientUuid, string $at): void
    {
        $update = $this->pdo()->prepare('UPDATE pp_criterion_checks SET outcome = ?, structure_id = ?, entered_by = ?, client_uuid = ?, entered_at = ? WHERE tenant_id = ? AND unit_id = ? AND person_id = ? AND criterion_id = ? AND occasion_no = ?');
        $update->execute([$outcome, $structureId, $actorId, $clientUuid, $at, $tenantId, $unitId, $personId, $criterionId, $occasion]);
        if ($update->rowCount() > 0) {
            return;
        }
        $this->pdo()->prepare('INSERT INTO pp_criterion_checks (id, tenant_id, establishment_id, unit_id, structure_id, person_id, frame_id, criterion_id, occasion_no, outcome, entered_by, client_uuid, entered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string) new Ulid(), $tenantId, $establishmentId, $unitId, $structureId, $personId, $frameId, $criterionId, $occasion, $outcome, $actorId, $clientUuid, $at]);
    }

    /**
     * @param list<string> $personIds
     * @return list<array<string, mixed>>
     */
    public function cells(string $tenantId, string $establishmentId, string $unitId, array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $query = $this->pdo()->prepare(sprintf(
            'SELECT person_id, frame_id, criterion_id, occasion_no, outcome FROM pp_criterion_checks WHERE tenant_id = ? AND establishment_id = ? AND unit_id = ? AND person_id IN (%s) ORDER BY person_id, criterion_id, occasion_no',
            implode(', ', array_fill(0, count($personIds), '?')),
        ));
        $query->execute([$tenantId, $establishmentId, $unitId, ...$personIds]);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function mention(string $tenantId, string $personId, string $frameId, string $kind, string $ref): ?array
    {
        return $this->one('SELECT * FROM pp_mentions WHERE tenant_id = ? AND person_id = ? AND frame_id = ? AND scope_kind = ? AND scope_ref = ?', [$tenantId, $personId, $frameId, $kind, $ref]);
    }

    /** Enregistre la mention calculée ; une mention modifiée par le directeur (motif) n'est jamais recalculée. */
    public function saveMention(string $tenantId, string $establishmentId, string $personId, string $frameId, string $structureId, string $kind, string $ref, string $mention, bool $perfection, string $ruleVersion, string $at): void
    {
        $existing = $this->mention($tenantId, $personId, $frameId, $kind, $ref);
        if ($existing === null) {
            $this->pdo()->prepare('INSERT INTO pp_mentions (id, tenant_id, establishment_id, person_id, frame_id, structure_id, scope_kind, scope_ref, mention, perfection_mastered, rule_version, computed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(string) new Ulid(), $tenantId, $establishmentId, $personId, $frameId, $structureId, $kind, $ref, $mention, $perfection ? 1 : 0, $ruleVersion, $at]);

            return;
        }
        if ($existing['overridden_reason'] !== null) {
            return;
        }
        $this->pdo()->prepare('UPDATE pp_mentions SET mention = ?, perfection_mastered = ?, rule_version = ?, structure_id = ?, computed_at = ? WHERE tenant_id = ? AND id = ?')
            ->execute([$mention, $perfection ? 1 : 0, $ruleVersion, $structureId, $at, $tenantId, $existing['id']]);
    }

    public function overrideMention(string $tenantId, string $establishmentId, string $personId, string $frameId, string $structureId, string $kind, string $ref, string $mention, string $reason, string $actorId, string $at): void
    {
        if ($this->mention($tenantId, $personId, $frameId, $kind, $ref) === null) {
            $this->saveMention($tenantId, $establishmentId, $personId, $frameId, $structureId, $kind, $ref, $mention, false, 'override', $at);
        }
        $this->pdo()->prepare('UPDATE pp_mentions SET mention = ?, overridden_reason = ?, overridden_by = ?, computed_at = ? WHERE tenant_id = ? AND person_id = ? AND frame_id = ? AND scope_kind = ? AND scope_ref = ?')
            ->execute([$mention, $reason, $actorId, $at, $tenantId, $personId, $frameId, $kind, $ref]);
    }

    public function markNotified(string $tenantId, string $personId, string $frameId, string $kind, string $ref, string $at): void
    {
        $this->pdo()->prepare('UPDATE pp_mentions SET parent_notified_at = ? WHERE tenant_id = ? AND person_id = ? AND frame_id = ? AND scope_kind = ? AND scope_ref = ?')
            ->execute([$at, $tenantId, $personId, $frameId, $kind, $ref]);
    }

    /**
     * @param list<string> $personIds
     * @return list<array<string, mixed>>
     */
    public function mentionsFor(string $tenantId, string $establishmentId, string $kind, string $ref, array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $query = $this->pdo()->prepare(sprintf(
            'SELECT * FROM pp_mentions WHERE tenant_id = ? AND establishment_id = ? AND scope_kind = ? AND scope_ref = ? AND person_id IN (%s) ORDER BY person_id, frame_id',
            implode(', ', array_fill(0, count($personIds), '?')),
        ));
        $query->execute([$tenantId, $establishmentId, $kind, $ref, ...$personIds]);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Mentions d'unité d'un élève pour une liste d'unités.
     *
     * @param list<string> $unitIds
     * @return list<array<string, mixed>>
     */
    public function unitMentionsOf(string $tenantId, string $personId, array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }
        $query = $this->pdo()->prepare(sprintf(
            "SELECT * FROM pp_mentions WHERE tenant_id = ? AND person_id = ? AND scope_kind = 'unit' AND scope_ref IN (%s)",
            implode(', ', array_fill(0, count($unitIds), '?')),
        ));
        $query->execute([$tenantId, $personId, ...$unitIds]);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, int> mentions de l'école à la portée donnée, par « discipline;mention » (agrégats) */
    public function mentionCounts(string $tenantId, string $establishmentId, string $kind): array
    {
        $query = $this->pdo()->prepare('SELECT frame_id, mention, COUNT(*) AS n FROM pp_mentions WHERE tenant_id = ? AND establishment_id = ? AND scope_kind = ? GROUP BY frame_id, mention');
        $query->execute([$tenantId, $establishmentId, $kind]);
        $counts = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['frame_id'] . '|' . (string) $row['mention']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Synchronise la liste de remédiation d'un élève pour une discipline et une unité : les critères à remédier sont ouverts (ou
     * conservés), les critères désormais maîtrisés encore « ouverts » sont retirés ; une remédiation commencée n'est jamais effacée.
     *
     * @param list<string> $criterionIds critères de la discipline
     * @param list<string> $toRemediate critères non maîtrisés
     */
    public function syncRemediations(string $tenantId, string $establishmentId, string $unitId, string $structureId, string $personId, string $frameId, array $criterionIds, array $toRemediate, string $actorId, string $at): void
    {
        $pdo = $this->pdo();
        foreach ($criterionIds as $criterionId) {
            $existing = $this->one('SELECT * FROM pp_remediations WHERE tenant_id = ? AND unit_id = ? AND person_id = ? AND criterion_id = ?', [$tenantId, $unitId, $personId, $criterionId]);
            $needed = in_array($criterionId, $toRemediate, true);
            if ($needed && $existing === null) {
                $pdo->prepare('INSERT INTO pp_remediations (id, tenant_id, establishment_id, unit_id, structure_id, person_id, frame_id, criterion_id, remediation_state, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([(string) new Ulid(), $tenantId, $establishmentId, $unitId, $structureId, $personId, $frameId, $criterionId, 'open', $actorId, $at, $at]);
            } elseif (!$needed && $existing !== null && $existing['remediation_state'] === 'open') {
                $pdo->prepare('DELETE FROM pp_remediations WHERE tenant_id = ? AND id = ?')->execute([$tenantId, $existing['id']]);
            }
        }
    }

    /**
     * @param list<string> $personIds
     * @return list<array<string, mixed>>
     */
    public function remediationsOfUnit(string $tenantId, string $establishmentId, string $unitId, array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $query = $this->pdo()->prepare(sprintf(
            'SELECT * FROM pp_remediations WHERE tenant_id = ? AND establishment_id = ? AND unit_id = ? AND person_id IN (%s) ORDER BY frame_id, criterion_id, person_id',
            implode(', ', array_fill(0, count($personIds), '?')),
        ));
        $query->execute([$tenantId, $establishmentId, $unitId, ...$personIds]);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function remediationsOfPerson(string $tenantId, string $establishmentId, string $personId): array
    {
        $query = $this->pdo()->prepare('SELECT * FROM pp_remediations WHERE tenant_id = ? AND establishment_id = ? AND person_id = ? ORDER BY created_at, id');
        $query->execute([$tenantId, $establishmentId, $personId]);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function remediation(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->one('SELECT * FROM pp_remediations WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    public function updateRemediation(string $tenantId, string $id, string $state, ?string $form, ?string $note, string $actorId, string $at): void
    {
        $this->pdo()->prepare('UPDATE pp_remediations SET remediation_state = ?, remediation_form = ?, outcome_note = ?, updated_by = ?, updated_at = ? WHERE tenant_id = ? AND id = ?')
            ->execute([$state, $form, $note, $actorId, $at, $tenantId, $id]);
    }

    /**
     * @param list<string> $criterionIds
     * @param list<string> $personIds
     */
    public function insertSession(string $tenantId, string $establishmentId, string $structureId, string $frameId, ?string $unitId, string $heldOn, int $minutes, string $form, array $criterionIds, array $personIds, ?string $notes, string $actorId, string $at): string
    {
        $id = (string) new Ulid();
        $this->pdo()->prepare('INSERT INTO pp_remediation_sessions (id, tenant_id, establishment_id, structure_id, frame_id, unit_id, held_on, duration_minutes, remediation_form, criterion_ids, person_ids, notes, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $establishmentId, $structureId, $frameId, $unitId, $heldOn, $minutes, $form, json_encode($criterionIds, JSON_THROW_ON_ERROR), json_encode($personIds, JSON_THROW_ON_ERROR), $notes, $actorId, $at]);

        return $id;
    }

    /** @return list<array<string, mixed>> séances de la classe après le curseur */
    public function sessions(string $tenantId, string $establishmentId, string $structureId, ?string $from, ?string $to, ?string $afterId, int $limit): array
    {
        $sql = 'SELECT * FROM pp_remediation_sessions WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ? AND id > ?';
        $params = [$tenantId, $establishmentId, $structureId, $afterId ?? ''];
        if ($from !== null) {
            $sql .= ' AND held_on >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND held_on <= ?';
            $params[] = $to;
        }
        $query = $this->pdo()->prepare($sql . ' ORDER BY id LIMIT ' . max(1, $limit));
        $query->execute($params);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> séances qui comptent l'élève */
    public function sessionsOfPerson(string $tenantId, string $establishmentId, string $personId): array
    {
        $query = $this->pdo()->prepare('SELECT * FROM pp_remediation_sessions WHERE tenant_id = ? AND establishment_id = ? AND person_ids LIKE ? ORDER BY held_on, id');
        $query->execute([$tenantId, $establishmentId, '%"' . $personId . '"%']);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveScore(string $tenantId, string $establishmentId, string $unitId, string $structureId, string $personId, string $frameId, int $hundredths, int $scaleMax, string $actorId, string $at): void
    {
        $update = $this->pdo()->prepare('UPDATE pp_unit_scores SET score_hundredths = ?, scale_max = ?, structure_id = ?, entered_by = ?, entered_at = ? WHERE tenant_id = ? AND unit_id = ? AND person_id = ? AND frame_id = ?');
        $update->execute([$hundredths, $scaleMax, $structureId, $actorId, $at, $tenantId, $unitId, $personId, $frameId]);
        if ($update->rowCount() > 0) {
            return;
        }
        $this->pdo()->prepare('INSERT INTO pp_unit_scores (id, tenant_id, establishment_id, unit_id, structure_id, person_id, frame_id, score_hundredths, scale_max, entered_by, entered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string) new Ulid(), $tenantId, $establishmentId, $unitId, $structureId, $personId, $frameId, $hundredths, $scaleMax, $actorId, $at]);
    }

    /**
     * @param list<string> $personIds
     * @return list<array<string, mixed>>
     */
    public function scores(string $tenantId, string $establishmentId, string $unitId, array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $query = $this->pdo()->prepare(sprintf(
            'SELECT person_id, frame_id, score_hundredths, scale_max FROM pp_unit_scores WHERE tenant_id = ? AND establishment_id = ? AND unit_id = ? AND person_id IN (%s)',
            implode(', ', array_fill(0, count($personIds), '?')),
        ));
        $query->execute([$tenantId, $establishmentId, $unitId, ...$personIds]);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $query = $this->pdo()->prepare($sql);
        $query->execute($params);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
