<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Transition\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;

final class TransitionService
{
    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(string $tenantId, string $establishmentId, string $schoolYear): array
    {
        $statement = $this->connections->pdo()->prepare(
            'SELECT * FROM pn_transition_decisions WHERE tenant_id = ? AND establishment_id = ? AND school_year = ? ORDER BY learner_id, id',
        );
        $statement->execute([$tenantId, $establishmentId, $schoolYear]);

        return array_values(array_map(self::cast(...), $statement->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /**
     * Lit la base : deux appels successifs peuvent rendre des résultats différents (avant et après une écriture).
     *
     * @phpstan-impure
     * @return array<string, mixed>|null
     */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_transition_decisions WHERE tenant_id = ? AND establishment_id = ? AND id = ? LIMIT 1');
        $statement->execute([$tenantId, $establishmentId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByLearnerYear(string $tenantId, string $establishmentId, string $learnerId, string $schoolYear): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_transition_decisions WHERE tenant_id = ? AND establishment_id = ? AND learner_id = ? AND school_year = ? LIMIT 1');
        $statement->execute([$tenantId, $establishmentId, $learnerId, $schoolYear]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /** @return array<string, mixed> */
    public function propose(string $tenantId, string $establishmentId, string $learnerId, string $schoolYear, string $fromLevel, ?string $educatorOpinion = null): array
    {
        $level = $this->assertLevel($fromLevel);
        $existing = $this->findByLearnerYear($tenantId, $establishmentId, $learnerId, $schoolYear);
        if ($existing !== null && in_array((string) $existing['status'], ['decided', 'applied'], true)) {
            return $existing;
        }

        $proposal = $level === 'gs' ? 'to_primary' : 'promote';
        $now = self::now();
        if ($existing === null) {
            $this->connections->pdo()->prepare(
                'INSERT INTO pn_transition_decisions (id, tenant_id, establishment_id, learner_id, school_year, from_level, proposal, decision, educator_opinion, parent_request_ref, parent_request_on, inspector_opinion, inspector_id, inspector_on, decided_by, decided_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL, NULL, NULL, NULL, NULL, NULL, NULL, ?, ?, ?)',
            )->execute([(string) new Ulid(), $tenantId, $establishmentId, $learnerId, $schoolYear, $level, $proposal, $this->trimmed($educatorOpinion), 'proposed', $now, $now]);
        } else {
            $this->connections->pdo()->prepare(
                'UPDATE pn_transition_decisions SET from_level = ?, proposal = ?, educator_opinion = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
            )->execute([$level, $proposal, $this->trimmed($educatorOpinion), $now, $tenantId, $establishmentId, $existing['id']]);
        }

        return $this->findByLearnerYear($tenantId, $establishmentId, $learnerId, $schoolYear) ?? throw new \LogicException('Décision introuvable après proposition.');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function decide(string $tenantId, string $establishmentId, string $id, string $actorId, array $data): array
    {
        $row = $this->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Décision de passage introuvable.');
        $decision = $this->assertDecision((string) ($data['decision'] ?? ''));
        $parentRequestRef = $this->trimmed($data['parent_request_ref'] ?? null);
        $parentRequestOn = $this->trimmed($data['parent_request_on'] ?? null);
        $inspectorOpinion = $this->trimmed($data['inspector_opinion'] ?? null);
        $inspectorId = $this->trimmed($data['inspector_id'] ?? null);
        $inspectorOn = $this->trimmed($data['inspector_on'] ?? null);
        $educatorOpinion = $this->trimmed($data['educator_opinion'] ?? $row['educator_opinion'] ?? null);

        $errors = [];
        if ($decision === 'retain') {
            if ($parentRequestRef === null) {
                $errors['parent_request_ref'][] = 'Référence de demande parentale obligatoire pour un maintien.';
            }
            if (!in_array($inspectorOpinion, ['favourable', 'unfavourable'], true)) {
                $errors['inspector_opinion'][] = 'Avis de l\'inspecteur obligatoire pour un maintien.';
            }
        } elseif ($inspectorOpinion !== null && !in_array($inspectorOpinion, ['favourable', 'unfavourable'], true)) {
            $errors['inspector_opinion'][] = 'Valeur attendue : favourable ou unfavourable.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $now = self::now();
        $this->connections->pdo()->prepare(
            'UPDATE pn_transition_decisions SET decision = ?, educator_opinion = ?, parent_request_ref = ?, parent_request_on = ?, inspector_opinion = ?, inspector_id = ?, inspector_on = ?, decided_by = ?, decided_at = ?, status = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
        )->execute([$decision, $educatorOpinion, $parentRequestRef, $parentRequestOn, $inspectorOpinion, $inspectorId, $inspectorOn, $actorId, $now, 'decided', $now, $tenantId, $establishmentId, $id]);

        return $this->find($tenantId, $establishmentId, $id) ?? throw new \LogicException('Décision introuvable après validation.');
    }

    private function assertLevel(string $fromLevel): string
    {
        $level = strtolower(trim($fromLevel));
        if (!in_array($level, ['ps', 'ms', 'gs'], true)) {
            throw HttpException::validation(['from_level' => ['Valeur attendue : ps, ms ou gs.']]);
        }

        return $level;
    }

    private function assertDecision(string $decision): string
    {
        $value = strtolower(trim($decision));
        if (!in_array($value, ['promote', 'to_primary', 'retain'], true)) {
            throw HttpException::validation(['decision' => ['Valeur attendue : promote, to_primary ou retain.']]);
        }

        return $value;
    }

    private function trimmed(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function cast(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'tenant_id' => (string) $row['tenant_id'],
            'establishment_id' => (string) $row['establishment_id'],
            'learner_id' => (string) $row['learner_id'],
            'school_year' => (string) $row['school_year'],
            'from_level' => (string) $row['from_level'],
            'proposal' => (string) $row['proposal'],
            'decision' => $row['decision'] !== null ? (string) $row['decision'] : null,
            'educator_opinion' => $row['educator_opinion'] !== null ? (string) $row['educator_opinion'] : null,
            'parent_request_ref' => $row['parent_request_ref'] !== null ? (string) $row['parent_request_ref'] : null,
            'parent_request_on' => $row['parent_request_on'] !== null ? (string) $row['parent_request_on'] : null,
            'inspector_opinion' => $row['inspector_opinion'] !== null ? (string) $row['inspector_opinion'] : null,
            'inspector_id' => $row['inspector_id'] !== null ? (string) $row['inspector_id'] : null,
            'inspector_on' => $row['inspector_on'] !== null ? (string) $row['inspector_on'] : null,
            'decided_by' => $row['decided_by'] !== null ? (string) $row['decided_by'] : null,
            'decided_at' => $row['decided_at'] !== null ? (string) $row['decided_at'] : null,
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
    }
}
