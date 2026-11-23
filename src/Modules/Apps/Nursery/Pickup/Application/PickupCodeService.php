<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Messaging\Contracts\SafetyMessenger;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Code à usage unique du jour (EX-MAT-14) : rattaché à une autorisation valable ce jour-là, haché par HMAC avec un secret propre à
 * l'établissement, JAMAIS affiché ni conservé en clair après sa création. Le nombre d'essais erronés avant blocage vient de
 * `nursery.pickup.code_max_attempts` (sans valeur, les codes sont refusés). Un code ne remet jamais l'enfant seul : la remise
 * applique ensuite la même règle que pour une autorisation désignée par son identifiant.
 */
final class PickupCodeService
{
    public const TABLE = 'nu_pickup_codes';
    public const TEMPLATE_CODE = 'nursery.not05.code';

    public function __construct(
        private readonly Store $store,
        private readonly AuthorizedPickupRegistry $registry,
        private readonly NurserySettings $settings,
        private readonly NurseryClock $clock,
        private readonly Access $access,
        private readonly SafetyMessenger $messenger,
        private readonly PersonDirectory $people,
    ) {
    }

    /**
     * Crée un code pour une autorisation et le rend UNE SEULE fois (et l'envoie par SMS aux parents).
     *
     * @return array{id: string, code: string, learner_id: string, pickup_id: string, valid_on: string}
     */
    public function issue(Principal $principal, string $establishmentId, string $learnerId, string $pickupId, string $validOn): array
    {
        $pickup = $this->registry->find($principal->tenantId, $establishmentId, $pickupId);
        if ($pickup === null || $pickup->learnerId !== $learnerId) {
            throw HttpException::notFound('Autorisation introuvable pour cet enfant.');
        }
        if (!$pickup->isValidOn($validOn)) {
            throw HttpException::validation(['pickup_id' => ['Cette autorisation n’est pas valable à la date demandée.']]);
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE, $principal->tenantId, $establishmentId, [
            'id' => $id, 'learner_id' => $learnerId, 'pickup_id' => $pickupId, 'code_hash' => $this->hash($principal->tenantId, $establishmentId, $code),
            'valid_on' => $validOn, 'failed_attempts' => 0, 'status' => 'issued', 'used_event_id' => null, 'created_by' => $principal->userId,
            'created_at' => $this->clock->now(), 'used_at' => null,
        ]);
        $this->access->audit($principal, 'nursery.pickup_code.issued', 'pickup_code', $id, null, ['learner_id' => $learnerId, 'pickup_id' => $pickupId, 'valid_on' => $validOn]);
        $child = $this->people->find($principal->tenantId, $learnerId);
        foreach ($this->people->guarantorsOf($principal->tenantId, $learnerId) as $guardian) {
            $this->messenger->send($principal->tenantId, $guardian->id, self::TEMPLATE_CODE, [
                'child_name' => $child !== null ? $child->fullName() : '', 'date' => $validOn, 'reference' => $code, 'details' => $pickup->name,
            ]);
        }

        return ['id' => $id, 'code' => $code, 'learner_id' => $learnerId, 'pickup_id' => $pickupId, 'valid_on' => $validOn];
    }

    /**
     * Contrôle un code présenté. Rend la ligne du code correspondant, ou lève une erreur métier : `code_invalid` (essai compté),
     * `code_blocked` (essais épuisés). Le blocage est écrit AVANT de lever, donc conservé malgré l'exception.
     *
     * @return array<string, mixed>
     */
    public function verify(string $tenantId, string $establishmentId, string $learnerId, string $day, string $presented): array
    {
        $maxAttempts = $this->settings->int($tenantId, $establishmentId, NurserySettings::PICKUP_CODE_ATTEMPTS);
        $codes = $this->store->select(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId, 'valid_on' => $day], 'created_at, id', 50);
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
        foreach ($issued as $code) {
            $attempts = (int) $code['failed_attempts'] + 1;
            $reached = $reached || $attempts >= $maxAttempts;
            $this->store->update(self::TABLE, $tenantId, $establishmentId, ['failed_attempts' => $attempts, 'status' => $attempts >= $maxAttempts ? 'blocked' : 'issued'], ['id' => (string) $code['id']]);
        }
        throw new CodeRefused($reached ? 'code_blocked' : 'code_invalid', $reached ? (string) $issued[0]['id'] : null);
    }

    public function markUsed(string $tenantId, string $establishmentId, string $codeId, string $eventId): void
    {
        $this->store->update(self::TABLE, $tenantId, $establishmentId, ['status' => 'used', 'used_event_id' => $eventId, 'used_at' => $this->clock->now()], ['id' => $codeId]);
    }

    private function hash(string $tenantId, string $establishmentId, string $code): string
    {
        $appKey = (string) ($_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? '');
        if ($appKey === '') {
            throw new HttpException(503, 'Service indisponible', 'Aucune clé de signature : définissez APP_KEY dans l’environnement.');
        }
        $secret = hash_hmac('sha256', 'nursery-pickup|' . $tenantId . '|' . $establishmentId, $appKey, true);

        return hash_hmac('sha256', $code, $secret);
    }
}
