<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Pickup\Application;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Code à usage unique du jour (T724, FR-M04) : rattaché à une autorisation valable ce jour-là, haché par HMAC avec un secret propre à
 * l'établissement, JAMAIS conservé en clair après sa création (rendu une fois, envoyé par SMS aux parents). Valable un seul jour et une
 * seule remise ; le nombre d'essais erronés avant blocage vient de `public_nursery.pickup.code_max_attempts` (sans valeur, les codes
 * sont refusés). Un code ne remet jamais l'enfant seul : la remise applique ensuite la même règle que pour une autorisation désignée par
 * son identifiant (autorisation encore valide, aucune restriction de garde).
 */
final class PickupCodeService
{
    public const TABLE = 'pn_pickup_codes';
    public const TEMPLATE = 'pn.pickup_code';
    public const MAX_ATTEMPTS_KEY = 'public_nursery.pickup.code_max_attempts';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly AuthorizedPickupRegistry $registry,
        private readonly SettingsReader $settings,
        private readonly SafetyMessenger $messenger,
        private readonly PersonDirectory $people,
        private readonly PublicNurseryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Crée un code pour une autorisation et le rend UNE SEULE fois (et l'envoie par SMS aux parents).
     *
     * @return array{id: string, code: string, learner_id: string, pickup_id: string, valid_on: string}
     */
    public function issue(string $tenantId, string $establishmentId, string $learnerId, string $pickupId, string $validOn, string $actorId): array
    {
        $pickup = $this->registry->find($tenantId, $establishmentId, $pickupId);
        if ($pickup === null || $pickup->learnerId !== $learnerId) {
            throw HttpException::notFound('Autorisation introuvable pour cet enfant.');
        }
        if (!$pickup->isValidOn($validOn)) {
            throw HttpException::validation(['pickup_id' => ['Cette autorisation n’est pas valable à la date demandée.']]);
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $id = (string) new Ulid();
        $this->connections->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . ' (id, tenant_id, establishment_id, learner_id, pickup_id, code_hash, valid_on, failed_attempts, status, used_event_id, created_by, created_at, used_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, NULL, ?, ?, NULL)',
        )->execute([$id, $tenantId, $establishmentId, $learnerId, $pickupId, $this->hash($tenantId, $establishmentId, $code), $validOn, 'issued', $actorId, $this->clock->now()]);
        $this->audit->append($tenantId, $actorId, 'public_nursery.pickup_code.issued', 'pickup_code', $id, null, ['learner_id' => $learnerId, 'pickup_id' => $pickupId, 'valid_on' => $validOn]);
        $child = $this->people->find($tenantId, $learnerId);
        foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guardian) {
            $this->messenger->send($tenantId, $guardian->id, self::TEMPLATE, ['child_name' => $child !== null ? $child->fullName() : '', 'reference' => $code, 'date' => $validOn]);
        }

        return ['id' => $id, 'code' => $code, 'learner_id' => $learnerId, 'pickup_id' => $pickupId, 'valid_on' => $validOn];
    }

    /**
     * Contrôle un code présenté pour cet enfant ce jour. Rend la ligne du code correspondant ou lève CodeRefused : `code_invalid`
     * (essai compté) ou `code_blocked` (essais épuisés, ou aucun nombre d'essais paramétré). Le blocage est écrit AVANT de lever.
     *
     * @return array<string, mixed>
     */
    public function verify(string $tenantId, string $establishmentId, string $learnerId, string $day, string $presented): array
    {
        $max = $this->settings->value($tenantId, $establishmentId, self::MAX_ATTEMPTS_KEY, null);
        $maxAttempts = is_int($max) && $max >= 1 ? $max : null;
        $codes = $this->forDay($tenantId, $establishmentId, $learnerId, $day);
        $issued = array_values(array_filter($codes, static fn (array $c): bool => $c['status'] === 'issued'));
        $blocked = array_filter($codes, static fn (array $c): bool => $c['status'] === 'blocked');
        if ($maxAttempts === null || ($issued === [] && $blocked !== [])) {
            throw new CodeRefused('code_blocked', null);
        }
        if ($issued === []) {
            throw new CodeRefused('code_invalid', null);
        }
        $hash = $this->hash($tenantId, $establishmentId, $presented);
        foreach ($issued as $code) {
            if (hash_equals((string) $code['code_hash'], $hash)) {
                return $code;
            }
        }
        $reached = false;
        $update = $this->connections->pdo()->prepare('UPDATE ' . self::TABLE . ' SET failed_attempts = ?, status = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?');
        foreach ($issued as $code) {
            $attempts = (int) $code['failed_attempts'] + 1;
            $reached = $reached || $attempts >= $maxAttempts;
            $update->execute([$attempts, $attempts >= $maxAttempts ? 'blocked' : 'issued', $tenantId, $establishmentId, (string) $code['id']]);
        }
        throw new CodeRefused($reached ? 'code_blocked' : 'code_invalid', $reached ? (string) $issued[0]['id'] : null);
    }

    public function markUsed(string $tenantId, string $establishmentId, string $codeId, string $eventId): void
    {
        $this->connections->pdo()->prepare('UPDATE ' . self::TABLE . ' SET status = ?, used_event_id = ?, used_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?')
            ->execute(['used', $eventId, $this->clock->now(), $tenantId, $establishmentId, $codeId]);
    }

    /** @return list<array<string, mixed>> */
    private function forDay(string $tenantId, string $establishmentId, string $learnerId, string $day): array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE tenant_id = ? AND establishment_id = ? AND learner_id = ? AND valid_on = ? ORDER BY created_at, id LIMIT 50');
        $query->execute([$tenantId, $establishmentId, $learnerId, $day]);

        return array_values($query->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function hash(string $tenantId, string $establishmentId, string $code): string
    {
        $appKey = (string) ($_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? '');
        if ($appKey === '') {
            throw new HttpException(503, 'Service indisponible', 'Aucune clé de signature : définissez APP_KEY dans l’environnement.');
        }
        $secret = hash_hmac('sha256', 'public-nursery-pickup|' . $tenantId . '|' . $establishmentId, $appKey, true);

        return hash_hmac('sha256', $code, $secret);
    }
}
