<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Pickup\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

final class CustodyRestrictionService
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly EnrollmentDirectory $enrollments,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantId, string $establishmentId, string $learnerId, ?string $onDate = null, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM pn_custody_restrictions WHERE tenant_id = ? AND establishment_id = ? AND learner_id = ?';
        $params = [$tenantId, $establishmentId, $learnerId];
        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }
        if ($onDate !== null && $onDate !== '') {
            if (!self::isDate($onDate)) {
                throw HttpException::validation(['on_date' => ['Date AAAA-MM-JJ attendue.']]);
            }
            $sql .= ' AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)';
            $params[] = $onDate;
            $params[] = $onDate;
        }
        $sql .= ' ORDER BY valid_from DESC, created_at DESC, id DESC';
        $statement = $this->connections->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values(array_map(self::cast(...), $statement->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /** @return list<array<string, mixed>> */
    public function activeFor(string $tenantId, string $establishmentId, string $learnerId, string $onDate): array
    {
        return $this->list($tenantId, $establishmentId, $learnerId, $onDate, true);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function record(string $tenantId, string $establishmentId, string $learnerId, array $data, string $actorId): array
    {
        if (!in_array($learnerId, $this->enrollments->personIdsInEstablishment($tenantId, $establishmentId), true)) {
            throw HttpException::notFound('Enfant introuvable dans cet établissement.');
        }
        $payload = $this->validate($data);
        $id = (string) new Ulid();
        $now = self::now();
        $this->connections->pdo()->prepare(
            'INSERT INTO pn_custody_restrictions (id, tenant_id, establishment_id, learner_id, restricted_name, restricted_person_id, restricted_pickup_id, nature, decision_reference, decision_document_ref, valid_from, valid_to, status, recorded_by, lifted_reason, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)',
        )->execute([
            $id,
            $tenantId,
            $establishmentId,
            $learnerId,
            $payload['restricted_name'],
            $payload['restricted_person_id'],
            $payload['restricted_pickup_id'],
            $payload['nature'],
            $payload['decision_reference'],
            $payload['decision_document_ref'],
            $payload['valid_from'],
            $payload['valid_to'],
            'active',
            $actorId,
            $now,
            $now,
        ]);
        $row = $this->find($tenantId, $establishmentId, $id) ?? throw new \LogicException('Restriction introuvable après création.');
        $this->audit->append($tenantId, $actorId, 'public_nursery.custody_restriction.recorded', 'pn_custody_restriction', $id, null, $row);

        return $row;
    }

    /** @return array<string, mixed> */
    public function lift(string $tenantId, string $establishmentId, string $id, string $reason, string $actorId): array
    {
        $row = $this->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Restriction introuvable.');
        $trimmedReason = trim($reason);
        if ($trimmedReason === '' || mb_strlen($trimmedReason) > 500) {
            throw HttpException::validation(['reason' => ['Motif requis (500 caractères max).']]);
        }
        if ((string) $row['status'] === 'active') {
            $now = self::now();
            $this->connections->pdo()->prepare(
                'UPDATE pn_custody_restrictions SET status = ?, lifted_reason = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
            )->execute(['lifted', $trimmedReason, $now, $tenantId, $establishmentId, $id]);
        }
        $after = $this->find($tenantId, $establishmentId, $id) ?? throw new \LogicException('Restriction introuvable après levée.');
        $this->audit->append($tenantId, $actorId, 'public_nursery.custody_restriction.lifted', 'pn_custody_restriction', $id, $row, $after);

        return $after;
    }

    /**
     * Lit la base : deux appels successifs peuvent rendre des résultats différents (avant et après une écriture).
     *
     * @phpstan-impure
     * @return array<string, mixed>|null
     */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM pn_custody_restrictions WHERE tenant_id = ? AND establishment_id = ? AND id = ? LIMIT 1');
        $statement->execute([$tenantId, $establishmentId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    public function recordRead(string $tenantId, string $actorId, string $learnerId, int $count): void
    {
        $this->audit->append($tenantId, $actorId, 'public_nursery.custody_restriction.read', 'learner', $learnerId, null, ['count' => $count]);
    }

    /** @param array<string, mixed> $data
     * @return array{restricted_name: string, restricted_person_id: ?string, restricted_pickup_id: ?string, nature: string, decision_reference: string, decision_document_ref: ?string, valid_from: string, valid_to: ?string}
     */
    private function validate(array $data): array
    {
        $errors = [];
        $name = $this->text($data['restricted_name'] ?? null, 160, true, 'restricted_name', $errors);
        $nature = $this->text($data['nature'] ?? null, 20, true, 'nature', $errors);
        $reference = $this->text($data['decision_reference'] ?? null, 120, true, 'decision_reference', $errors);
        $documentRef = $this->text($data['decision_document_ref'] ?? null, 120, false, 'decision_document_ref', $errors);
        $validFrom = $this->date($data['valid_from'] ?? null, 'valid_from', $errors, true);
        $validTo = $this->date($data['valid_to'] ?? null, 'valid_to', $errors, false);
        $restrictedPersonId = $this->text($data['restricted_person_id'] ?? null, 40, false, 'restricted_person_id', $errors);
        $restrictedPickupId = $this->text($data['restricted_pickup_id'] ?? null, 40, false, 'restricted_pickup_id', $errors);
        if ($validFrom !== null && $validTo !== null && strcmp($validTo, $validFrom) < 0) {
            $errors['valid_to'][] = 'La date de fin doit être postérieure ou égale à la date de début.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'restricted_name' => (string) $name,
            'restricted_person_id' => $restrictedPersonId,
            'restricted_pickup_id' => $restrictedPickupId,
            'nature' => (string) $nature,
            'decision_reference' => (string) $reference,
            'decision_document_ref' => $documentRef,
            'valid_from' => (string) $validFrom,
            'valid_to' => $validTo,
        ];
    }

    /** @param array<string, list<string>> $errors */
    private function text(mixed $value, int $max, bool $required, string $field, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                $errors[$field][] = 'Ce champ est obligatoire.';
            }

            return null;
        }
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
            $errors[$field][] = sprintf('Texte de %d caractères au plus attendu.', $max);

            return null;
        }

        return trim($value);
    }

    /** @param array<string, list<string>> $errors */
    private function date(mixed $value, string $field, array &$errors, bool $required): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                $errors[$field][] = 'Ce champ est obligatoire.';
            }

            return null;
        }
        if (!is_string($value) || !self::isDate($value)) {
            $errors[$field][] = 'Date AAAA-MM-JJ attendue.';

            return null;
        }

        return $value;
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
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
            'restricted_name' => (string) $row['restricted_name'],
            'restricted_person_id' => $row['restricted_person_id'] !== null ? (string) $row['restricted_person_id'] : null,
            'restricted_pickup_id' => $row['restricted_pickup_id'] !== null ? (string) $row['restricted_pickup_id'] : null,
            'nature' => (string) $row['nature'],
            'decision_reference' => (string) $row['decision_reference'],
            'decision_document_ref' => $row['decision_document_ref'] !== null ? (string) $row['decision_document_ref'] : null,
            'valid_from' => (string) $row['valid_from'],
            'valid_to' => $row['valid_to'] !== null ? (string) $row['valid_to'] : null,
            'status' => (string) $row['status'],
            'recorded_by' => (string) $row['recorded_by'],
            'lifted_reason' => $row['lifted_reason'] !== null ? (string) $row['lifted_reason'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
    }
}
