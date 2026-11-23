<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\NurserySettings;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\ConsentRecorder;

/**
 * Consentement photo d'un enfant (MQ-06, RG-MAT-6) : recueilli par l'école sur formulaire papier (référence de la pièce, date de
 * signature, signataire) ou donné par le parent depuis son portail (texte du consentement versionné par
 * `nursery.photo_consent.statement_version`). Révocable à tout moment par le parent ou l'école : la révocation est immédiate (le
 * filtre du cahier de vie relit le socle à chaque lecture). L'historique n'est jamais effacé. Le consentement en vigueur est celui du
 * socle (ConsentRecorder) : cette classe en garde la trace et ne crée jamais deux consentements actifs pour un même enfant.
 */
final class PhotoConsentService
{
    public const TABLE = 'nu_photo_consents';
    public const CHANNELS = ['paper', 'portal'];

    public function __construct(
        private readonly Store $store,
        private readonly ConsentRecorder $recorder,
        private readonly ConsentChecker $checker,
        private readonly NurserySettings $settings,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array{channel: string, evidence_ref?: ?string, signed_on?: ?string, signed_by_name: string} $data
     * @return array<string, mixed>
     */
    public function grant(Principal $principal, string $establishmentId, string $learnerId, array $data): array
    {
        $tenantId = $principal->tenantId;
        if ($this->active($tenantId, $establishmentId, $learnerId) !== null) {
            throw HttpException::conflict('Un consentement photo est déjà en vigueur pour cet enfant : révoquez-le avant d’en enregistrer un autre.');
        }
        $channel = $data['channel'];
        $evidence = isset($data['evidence_ref']) && $data['evidence_ref'] !== '' ? (string) $data['evidence_ref'] : null;
        if ($channel === 'paper' && $evidence === null) {
            throw HttpException::validation(['evidence_ref' => ['Référence du formulaire signé obligatoire (numéro de la pièce classée).']]);
        }
        $version = $this->settings->text($tenantId, $establishmentId, NurserySettings::CONSENT_STATEMENT_VERSION);
        if ($channel === 'portal') {
            if ($version === null) {
                throw HttpException::validation(['statement' => ['Le texte du consentement n’est pas paramétré par l’école : consentement sur formulaire seulement.']]);
            }
            $evidence = 'portal:' . $version . ':' . $principal->userId;
        }
        $consent = $this->recorder->grant($tenantId, $learnerId, ConsentChecker::PHOTO, $principal->userId, $evidence);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => $id, 'learner_id' => $learnerId, 'consent_ref' => $consent['id'], 'channel' => $channel, 'evidence_ref' => $evidence,
            'signed_on' => (string) ($data['signed_on'] ?? $this->clock->today()), 'signed_by_name' => $data['signed_by_name'],
            'statement_version' => $channel === 'portal' ? $version : null, 'recorded_by' => $principal->userId, 'granted_at' => $now, 'status' => 'active',
            'revoked_at' => null, 'revoked_by' => null, 'revoke_reason' => null,
        ]);
        $this->access->audit($principal, 'nursery.photo_consent.granted', 'child', $learnerId, null, ['channel' => $channel, 'consent_id' => $id]);

        return $this->summary($tenantId, $establishmentId, $learnerId);
    }

    /** @return array<string, mixed> révoque le consentement en vigueur (404 s'il n'y en a pas) */
    public function revoke(Principal $principal, string $establishmentId, string $learnerId, ?string $reason): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->active($tenantId, $establishmentId, $learnerId) ?? throw HttpException::notFound('Aucun consentement photo en vigueur pour cet enfant.');
        $this->recorder->revoke($tenantId, (string) $row['consent_ref'], $principal->userId);
        $this->store->update(self::TABLE, $tenantId, $establishmentId, [
            'status' => 'revoked', 'revoked_at' => $this->clock->now(), 'revoked_by' => $principal->userId, 'revoke_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
        ], ['id' => (string) $row['id']]);
        $this->access->audit($principal, 'nursery.photo_consent.revoked', 'child', $learnerId, ['consent_id' => (string) $row['id']], ['status' => 'revoked']);

        return $this->summary($tenantId, $establishmentId, $learnerId);
    }

    /**
     * État et historique : `active` reflète le socle (vérité lue à chaque photo servie), l'historique celui de l'école.
     *
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, string $establishmentId, string $learnerId): array
    {
        $history = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId], 'granted_at DESC, id', 200) as $row) {
            $history[] = [
                'id' => (string) $row['id'], 'channel' => (string) $row['channel'], 'evidence_ref' => $row['evidence_ref'] !== null ? (string) $row['evidence_ref'] : null,
                'signed_on' => (string) $row['signed_on'], 'signed_by_name' => (string) $row['signed_by_name'],
                'statement_version' => $row['statement_version'] !== null ? (string) $row['statement_version'] : null, 'granted_at' => (string) $row['granted_at'],
                'status' => (string) $row['status'], 'revoked_at' => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
                'revoke_reason' => $row['revoke_reason'] !== null ? (string) $row['revoke_reason'] : null,
            ];
        }

        return [
            'learner_id' => $learnerId,
            'active' => $this->checker->hasConsent($tenantId, $learnerId, ConsentChecker::PHOTO),
            'statement_version' => $this->settings->text($tenantId, $establishmentId, NurserySettings::CONSENT_STATEMENT_VERSION),
            'history' => $history,
        ];
    }

    /** @return array<string, mixed>|null */
    private function active(string $tenantId, string $establishmentId, string $learnerId): ?array
    {
        return $this->store->select(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId, 'status' => 'active'], 'granted_at DESC, id', 1)[0] ?? null;
    }
}
