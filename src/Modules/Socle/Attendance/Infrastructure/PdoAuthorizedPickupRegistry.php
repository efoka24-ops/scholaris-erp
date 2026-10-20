<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Infrastructure;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Attendance\Application\PickupService;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupInfo;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;

/**
 * AuthorizedPickupRegistry sur la table `authorized_pickups`. La lecture est un SQL borné au client et à l'établissement ;
 * l'écriture passe par PickupService (mêmes règles et même journalisation que les routes `/authorized-pickups`, qui restent inchangées).
 */
final class PdoAuthorizedPickupRegistry implements AuthorizedPickupRegistry
{
    public function __construct(private readonly Db $db, private readonly PickupService $service)
    {
    }

    public function validOn(string $tenantId, string $establishmentId, array $learnerIds, string $date): array
    {
        $learnerIds = array_values(array_unique($learnerIds));
        if ($learnerIds === []) {
            return [];
        }
        $rows = $this->db->all(
            sprintf(
                "SELECT * FROM authorized_pickups WHERE tenant_id = ? AND establishment_id = ? AND status = 'active'
                 AND learner_id IN (%s) AND (grant_type = 'permanent' OR (grant_type = 'day' AND valid_on = ?))
                 ORDER BY learner_id, id",
                implode(', ', array_fill(0, count($learnerIds), '?')),
            ),
            [$tenantId, $establishmentId, ...$learnerIds, $date],
        );

        return array_map($this->info(...), $rows);
    }

    public function find(string $tenantId, string $establishmentId, string $id): ?AuthorizedPickupInfo
    {
        $row = $this->db->one(
            'SELECT * FROM authorized_pickups WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
            [$tenantId, $establishmentId, $id],
        );

        return $row === null ? null : $this->info($row);
    }

    public function grant(string $tenantId, string $establishmentId, string $learnerId, array $data, string $actorId): string
    {
        $row = $this->service->grant(new Principal($actorId, $tenantId), $establishmentId, ['learner_id' => $learnerId] + $this->validated($data));

        return (string) $row['id'];
    }

    public function revoke(string $tenantId, string $establishmentId, string $id, string $actorId): void
    {
        $this->service->revoke(new Principal($actorId, $tenantId), $establishmentId, $id);
    }

    /**
     * Mêmes bornes que la route (PickupRequest) ; les règles de fond (enfant de l'établissement, date selon le type) restent à PickupService.
     *
     * @param array<string, mixed> $data
     * @return array{pickup_name: string, relationship: string, phone: string|null, photo_document_ref: string|null, grant_type: string, valid_on: string|null}
     */
    private function validated(array $data): array
    {
        $errors = [];
        $name = $this->text($data['name'] ?? null, 160, true, 'name', $errors);
        $relationship = $this->text($data['relationship'] ?? null, 60, true, 'relationship', $errors);
        $phone = $this->text($data['phone'] ?? null, 30, false, 'phone', $errors);
        $photo = $this->text($data['photo_ref'] ?? null, 80, false, 'photo_ref', $errors);
        $type = $data['grant_type'] ?? null;
        if (!in_array($type, [AuthorizedPickupInfo::PERMANENT, AuthorizedPickupInfo::DAY], true)) {
            $errors['grant_type'] = ['Le type doit être « permanent » ou « day ».'];
        }
        $validOn = $data['valid_on'] ?? null;
        if ($validOn !== null && !$this->isDate($validOn)) {
            $errors['valid_on'] = ['La date doit être au format AAAA-MM-JJ.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'pickup_name' => (string) $name, 'relationship' => (string) $relationship, 'phone' => $phone, 'photo_document_ref' => $photo,
            'grant_type' => (string) $type, 'valid_on' => $validOn === null ? null : (string) $validOn,
        ];
    }

    /** @param array<string, list<string>> $errors */
    private function text(mixed $value, int $max, bool $required, string $field, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                $errors[$field] = ['Ce champ est obligatoire.'];
            }

            return null;
        }
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
            $errors[$field] = [sprintf('Texte de %d caractères au plus attendu.', $max)];

            return null;
        }

        return trim($value);
    }

    private function isDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** @param array<string, mixed> $row */
    private function info(array $row): AuthorizedPickupInfo
    {
        return new AuthorizedPickupInfo(
            (string) $row['id'],
            (string) $row['learner_id'],
            (string) $row['pickup_name'],
            (string) $row['relationship'],
            $row['phone'] === null ? null : (string) $row['phone'],
            $row['photo_document_ref'] === null ? null : (string) $row['photo_document_ref'],
            (string) $row['grant_type'],
            $row['valid_on'] === null ? null : (string) $row['valid_on'],
            (string) $row['status'],
        );
    }
}
