<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Referential\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\CriterionInfo;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\FrameInfo;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\Referential;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\UnitInfo;

/** Référentiel (pp_frames, pp_criteria) et unités (pp_learning_units), bornés par client. */
final class PdoReferential implements Referential
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function framesFor(string $tenantId, string $subsystem, string $levelCode, string $date): array
    {
        $query = $this->connections->pdo()->prepare(
            'SELECT * FROM pp_frames WHERE tenant_id = ? AND subsystem = ? AND level_code = ? AND valid_from <= ? ORDER BY discipline_code, version DESC',
        );
        $query->execute([$tenantId, $subsystem, $levelCode, $date]);
        $latest = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $latest[(string) $row['discipline_code']] ??= $row;
        }

        return array_values(array_map(fn (array $row): FrameInfo => $this->frameOf($tenantId, $row), $latest));
    }

    public function frame(string $tenantId, string $frameId): ?FrameInfo
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pp_frames WHERE tenant_id = ? AND id = ?');
        $query->execute([$tenantId, $frameId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->frameOf($tenantId, $row) : null;
    }

    /** @return list<array<string, mixed>> versions d'une discipline ou toutes les disciplines d'un niveau, après le curseur */
    public function listFrames(string $tenantId, ?string $subsystem, ?string $levelCode, ?string $afterId, int $limit): array
    {
        $sql = 'SELECT * FROM pp_frames WHERE tenant_id = ? AND id > ?';
        $params = [$tenantId, $afterId ?? ''];
        if ($subsystem !== null) {
            $sql .= ' AND subsystem = ?';
            $params[] = $subsystem;
        }
        if ($levelCode !== null) {
            $sql .= ' AND level_code = ?';
            $params[] = $levelCode;
        }
        $query = $this->connections->pdo()->prepare($sql . ' ORDER BY id LIMIT ' . max(1, $limit));
        $query->execute($params);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function nextVersion(string $tenantId, string $subsystem, string $levelCode, string $disciplineCode): int
    {
        $query = $this->connections->pdo()->prepare('SELECT MAX(version) FROM pp_frames WHERE tenant_id = ? AND subsystem = ? AND level_code = ? AND discipline_code = ?');
        $query->execute([$tenantId, $subsystem, $levelCode, $disciplineCode]);

        return (int) $query->fetchColumn() + 1;
    }

    /**
     * @param array<string, mixed> $frame
     * @param list<array{code: string, label_fr: string, label_en: string, kind: string}> $criteria
     */
    public function insertFrame(string $tenantId, array $frame, array $criteria, string $actorId, string $at): string
    {
        $pdo = $this->connections->pdo();
        $id = (string) new Ulid();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO pp_frames (id, tenant_id, subsystem, level_code, discipline_code, label_fr, label_en, version, valid_from, is_key_discipline, source_ref, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $tenantId, $frame['subsystem'], $frame['level_code'], $frame['discipline_code'], $frame['label_fr'], $frame['label_en'], $frame['version'], $frame['valid_from'], $frame['is_key_discipline'] ? 1 : 0, $frame['source_ref'], $actorId, $at]);
            $insert = $pdo->prepare('INSERT INTO pp_criteria (id, tenant_id, frame_id, code, label_fr, label_en, criterion_kind, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($criteria as $index => $criterion) {
                $insert->execute([(string) new Ulid(), $tenantId, $id, $criterion['code'], $criterion['label_fr'], $criterion['label_en'], $criterion['kind'], $index + 1]);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }

        return $id;
    }

    public function unit(string $tenantId, string $unitId): ?UnitInfo
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pp_learning_units WHERE tenant_id = ? AND id = ?');
        $query->execute([$tenantId, $unitId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::unitOf($row) : null;
    }

    public function unitsOf(string $tenantId, string $yearLabel, string $levelCode): array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pp_learning_units WHERE tenant_id = ? AND year_label = ? AND level_code = ? ORDER BY unit_no');
        $query->execute([$tenantId, $yearLabel, $levelCode]);

        return array_values(array_map(static fn (array $row): UnitInfo => self::unitOf($row), $query->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** Crée ou remplace l'unité de ce numéro (année, niveau) ; rend son identifiant. */
    public function saveUnit(string $tenantId, UnitInfo $unit, string $actorId, string $at): string
    {
        $pdo = $this->connections->pdo();
        $query = $pdo->prepare('SELECT id FROM pp_learning_units WHERE tenant_id = ? AND year_label = ? AND level_code = ? AND unit_no = ?');
        $query->execute([$tenantId, $unit->yearLabel, $unit->levelCode, $unit->unitNo]);
        $existing = $query->fetchColumn();
        if (is_string($existing)) {
            $pdo->prepare('UPDATE pp_learning_units SET period_id = ?, starts_on = ?, ends_on = ?, evaluation_week_from = ?, is_final_integration = ?, updated_at = ? WHERE tenant_id = ? AND id = ?')
                ->execute([$unit->periodId, $unit->startsOn, $unit->endsOn, $unit->evaluationWeekFrom, $unit->isFinalIntegration ? 1 : 0, $at, $tenantId, $existing]);

            return $existing;
        }
        $id = (string) new Ulid();
        $pdo->prepare('INSERT INTO pp_learning_units (id, tenant_id, year_label, level_code, unit_no, period_id, starts_on, ends_on, evaluation_week_from, is_final_integration, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $unit->yearLabel, $unit->levelCode, $unit->unitNo, $unit->periodId, $unit->startsOn, $unit->endsOn, $unit->evaluationWeekFrom, $unit->isFinalIntegration ? 1 : 0, $actorId, $at, $at]);

        return $id;
    }

    /** @param array<string, mixed> $row */
    private function frameOf(string $tenantId, array $row): FrameInfo
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pp_criteria WHERE tenant_id = ? AND frame_id = ? ORDER BY sort_order, code');
        $query->execute([$tenantId, (string) $row['id']]);
        $criteria = array_values(array_map(static fn (array $c): CriterionInfo => new CriterionInfo(
            (string) $c['id'],
            (string) $c['frame_id'],
            (string) $c['code'],
            (string) $c['label_fr'],
            (string) $c['label_en'],
            (string) $c['criterion_kind'],
            (int) $c['sort_order'],
        ), $query->fetchAll(PDO::FETCH_ASSOC)));

        return new FrameInfo(
            (string) $row['id'],
            (string) $row['subsystem'],
            (string) $row['level_code'],
            (string) $row['discipline_code'],
            (string) $row['label_fr'],
            (string) $row['label_en'],
            (int) $row['version'],
            (string) $row['valid_from'],
            (int) $row['is_key_discipline'] === 1,
            $criteria,
        );
    }

    /** @param array<string, mixed> $row */
    private static function unitOf(array $row): UnitInfo
    {
        return new UnitInfo(
            (string) $row['id'],
            (string) $row['year_label'],
            (string) $row['level_code'],
            (int) $row['unit_no'],
            (string) $row['period_id'],
            (string) $row['starts_on'],
            (string) $row['ends_on'],
            (string) $row['evaluation_week_from'],
            (int) $row['is_final_integration'] === 1,
        );
    }
}
