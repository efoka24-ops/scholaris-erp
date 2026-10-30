<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Http\PupilInput;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Inscription groupée depuis un registre papier (EX-ELV-07, T631) : le directeur ou l'agent de saisie de l'inspection (R20, école sans
 * équipement) saisit les lignes du registre, avec la référence de la photo du registre jointe. Chaque ligne suit EXACTEMENT le chemin
 * de l'inscription unitaire (API-02 : doublons, identifiant national, acte facultatif, âge averti jamais refusé, aucun champ APEE) ;
 * une ligne en erreur ou en doublon n'empêche pas les autres. Le lot est idempotent par `client_uuid` (rejeu = même résultat).
 */
final class BatchRegistrationService
{
    public const MAX_ROWS = 200;

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly PupilEnrolmentService $enrolment,
        private readonly PupilClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input client_uuid, register_photo_ref?, register_label?, class_id?, rows [corps d'inscription]
     * @return array<string, mixed>
     */
    public function register(Principal $principal, string $establishmentId, array $input, bool $proxy): array
    {
        $tenantId = $principal->tenantId;
        $errors = [];
        $uuid = $input['client_uuid'] ?? null;
        if (!is_string($uuid) || $uuid === '' || strlen($uuid) > 40) {
            $errors['client_uuid'][] = 'Identifiant client du lot requis (40 caractères au plus).';
        }
        $photo = $input['register_photo_ref'] ?? null;
        if ($photo !== null && (!is_string($photo) || mb_strlen($photo) > 255)) {
            $errors['register_photo_ref'][] = 'Référence de la photo du registre (255 caractères au plus).';
        }
        $label = $input['register_label'] ?? null;
        if ($label !== null && (!is_string($label) || mb_strlen($label) > 160)) {
            $errors['register_label'][] = 'Libellé du registre (160 caractères au plus).';
        }
        $rows = $input['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > self::MAX_ROWS) {
            $errors['rows'][] = 'Lignes du registre attendues (1 à ' . self::MAX_ROWS . ').';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $previous = $this->batch($tenantId, (string) $uuid);
        if ($previous !== null) {
            return self::resource($previous) + ['replayed' => true];
        }
        $defaultClass = is_string($input['class_id'] ?? null) ? (string) $input['class_id'] : null;
        $results = [];
        $created = 0;
        foreach ((array) $rows as $index => $row) {
            $results[] = $this->row($principal, $establishmentId, (int) $index, is_array($row) ? $row : [], $defaultClass, (string) $uuid, $created);
        }
        $id = (string) new Ulid();
        $this->connections->pdo()->prepare('INSERT INTO pub_registration_batches (id, tenant_id, establishment_id, client_uuid, register_photo_ref, register_label, entered_by, entered_by_proxy, rows_count, created_count, results, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $tenantId, $establishmentId, $uuid, $photo, $label, $principal->userId, $proxy ? 1 : 0, count((array) $rows), $created, json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->clock->now()]);
        $this->audit->append($tenantId, $principal->userId, 'public_schools.pupils.batch_registered', 'pub_registration_batch', $id, null, [
            'establishment_id' => $establishmentId, 'rows' => count((array) $rows), 'created' => $created, 'proxy' => $proxy,
        ]);

        return self::resource($this->batch($tenantId, (string) $uuid) ?? []) + ['replayed' => false];
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantId, string $establishmentId, ?string $afterId, int $limit): array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_registration_batches WHERE tenant_id = ? AND establishment_id = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit));
        $query->execute([$tenantId, $establishmentId, $afterId ?? '']);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return $row === [] ? [] : [
            'id' => (string) $row['id'], 'client_uuid' => (string) $row['client_uuid'], 'register_photo_ref' => $row['register_photo_ref'],
            'register_label' => $row['register_label'], 'entered_by_proxy' => (int) $row['entered_by_proxy'] === 1, 'rows_count' => (int) $row['rows_count'],
            'created_count' => (int) $row['created_count'], 'results' => (array) json_decode((string) $row['results'], true), 'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(Principal $principal, string $establishmentId, int $index, array $row, ?string $defaultClass, string $batchUuid, int &$created): array
    {
        if (!isset($row['class_id']) && $defaultClass !== null) {
            $row['class_id'] = $defaultClass;
        }
        $row['client_uuid'] ??= self::lineUuid($batchUuid, $index);
        try {
            $input = PupilInput::registration($row);
            $result = $this->enrolment->register($principal->tenantId, $establishmentId, $input['draft'], $input['class_id'], $input['guardians'], $principal->userId, $input['confirm_different'], $input['override_reason']);
        } catch (HttpException $refused) {
            return ['row' => $index, 'status' => $refused->status === 409 ? 'duplicate' : 'rejected', 'errors' => $refused->errors, 'message' => $refused->getMessage(), 'extensions' => $refused->extensions];
        } catch (\InvalidArgumentException $invalid) {
            return ['row' => $index, 'status' => 'rejected', 'errors' => [], 'message' => $invalid->getMessage()];
        }
        $pupil = $result['registration']->pupil;
        $created += $result['replayed'] ? 0 : 1;

        return ['row' => $index, 'status' => $result['replayed'] ? 'replayed' : 'created', 'person_id' => $pupil->personId, 'learner_number' => $pupil->learnerNumber, 'warnings' => $result['registration']->warnings];
    }

    /** UUID déterministe d'une ligne du lot (idempotence ligne par ligne si le lot est rejoué après une coupure). */
    private static function lineUuid(string $batchUuid, int $index): string
    {
        $hash = md5($batchUuid . '#' . $index);

        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-4' . substr($hash, 13, 3) . '-8' . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
    }

    /** @return array<string, mixed>|null */
    private function batch(string $tenantId, string $uuid): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_registration_batches WHERE tenant_id = ? AND client_uuid = ?');
        $query->execute([$tenantId, $uuid]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
