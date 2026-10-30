<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Numbering\SequenceService;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentWebhookEvent;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Domain\Calendar;
use Scholaris\Modules\Socle\Platform\Domain\CameroonPhone;
use Scholaris\Modules\Socle\Platform\Domain\Subscription;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Demandes de régularisation par déclaration de paiement.
 *
 * Le responsable d'un établissement déclare un paiement mobile money fait à la plateforme (numéro payeur, total ou partiel,
 * moyen, référence). La déclaration ne débloque JAMAIS : seule une vérification le fait, soit automatiquement quand la
 * passerelle confirme un paiement qui correspond (référence, montant dans la tolérance, numéro payeur), soit par le super admin
 * qui constate le paiement sur son relevé. Le déblocage passe par le chemin existant des paiements manuels (reçu, période
 * prolongée, état actif, blocage levé) : rien n'y est dupliqué.
 *
 * Paiement partiel confirmé : le montant est porté au crédit du solde, la période est prolongée proportionnellement (réglage
 * `regularisation_partial_mode`) et l'établissement reste à son niveau tant que le solde n'est pas couvert, sauf promesse de
 * paiement accordée par le super admin. Une même référence ne peut valider qu'une demande ; une demande non confirmée expire.
 * Rien de ce qui est journalisé ne contient un numéro de téléphone complet.
 */
final class RegularisationRequests
{
    public const DECLARED = 'declared';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';
    public const EXPIRED = 'expired';
    /** Paiement confirmé puis annulé par le super admin (motif dans decision_reason) : voir ManualPayments::cancel(). */
    public const CANCELLED = 'cancelled';
    public const STATES = [self::DECLARED, self::VERIFIED, self::REJECTED, self::EXPIRED, self::CANCELLED];
    public const TOTAL = 'total';
    public const PARTIAL = 'partial';
    public const KINDS = [self::TOTAL, self::PARTIAL];
    public const VIA_WEBHOOK = 'webhook';
    public const VIA_MANUAL = 'manual';
    public const WEBHOOK_ACTOR = 'svc:webhook';

    public function __construct(
        private readonly PlatformDb $db,
        private readonly Catalog $catalog,
        private readonly EntitlementService $entitlements,
        private readonly RegularisationService $regularisation,
        private readonly ManualPayments $manual,
        private readonly SequenceService $sequences,
        private readonly PlatformSettings $settings,
        private readonly PlatformNotifier $notifier,
        private readonly PlatformAudit $audit,
        private readonly PlatformClock $clock,
    ) {
    }

    /**
     * Déclare un paiement. Idempotente : la même clé renvoie la demande déjà créée ; une seule demande ouverte à la fois par
     * application. Ne modifie ni l'état ni le niveau de blocage.
     *
     * @return array{0: array<string, mixed>, 1: bool} [demande, créée maintenant]
     */
    public function declare(string $tenantId, string $appKey, string $kind, ?int $amountXaf, string $channel, string $payerPhone, string $reference, ?string $idempotencyKey, string $actorId, ?string $ip): array
    {
        $this->expireStale($tenantId);
        if ($idempotencyKey !== null) {
            $existing = $this->db->one('SELECT * FROM platform_regularisation_requests WHERE tenant_id = ? AND idempotency_key = ?', [$tenantId, $idempotencyKey]);
            if ($existing !== null) {
                return [$this->present($existing), false];
            }
        }
        $row = $this->entitlements->rowOrFail($tenantId, $appKey);
        if ($row['state'] === ApplicationAccess::CLOSED) {
            throw HttpException::conflict('Cette application est fermée : demandez sa réouverture.');
        }
        $item = $this->regularisation->itemOf($row);
        if ($item === null || (int) $item['balance_xaf'] <= 0) {
            throw HttpException::conflict('Aucun solde à régulariser pour cette application.');
        }
        $balance = (int) $item['balance_xaf'];
        $openKey = $tenantId . '|' . $appKey;
        if ($this->db->one('SELECT id FROM platform_regularisation_requests WHERE open_key = ?', [$openKey]) !== null) {
            throw HttpException::conflict('Une déclaration de paiement est déjà en cours de vérification pour cette application.');
        }

        $amount = $balance;
        if ($kind === self::PARTIAL) {
            $min = $this->settings->value(PlatformSettings::PARTIAL_MIN);
            if ($min > $balance - 1) {
                throw HttpException::validation(['kind' => ['Le solde est inférieur au minimum d\'un paiement partiel : déclarez le paiement total.']]);
            }
            if ($amountXaf === null || $amountXaf < $min || $amountXaf > $balance - 1) {
                throw HttpException::validation(['amount_xaf' => [sprintf('Montant attendu entre %d et %d FCFA (le solde dû est de %d FCFA).', $min, $balance - 1, $balance)]]);
            }
            $amount = $amountXaf;
        }
        $referenceKey = self::referenceKey($reference);
        if (strlen($referenceKey) < 4) {
            throw HttpException::validation(['reference' => ['La référence doit comporter au moins 4 lettres ou chiffres.']]);
        }
        if ($this->db->one('SELECT id FROM platform_regularisation_requests WHERE verified_key = ?', [$referenceKey]) !== null) {
            throw HttpException::conflict('Cette référence de paiement a déjà servi à valider une autre demande.');
        }

        $id = (string) new Ulid();
        $now = $this->clock->iso();
        $expiresOn = Calendar::addDays($this->clock->today(), $this->settings->value(PlatformSettings::REQUEST_EXPIRY_DAYS));
        try {
            $this->db->insert('platform_regularisation_requests', [
                'id' => $id, 'tenant_id' => $tenantId, 'tenant_application_id' => (string) $row['id'], 'app_key' => $appKey,
                'idempotency_key' => $idempotencyKey, 'kind' => $kind, 'channel' => $channel, 'payer_phone' => $payerPhone,
                'reference' => $reference, 'reference_key' => $referenceKey, 'declared_amount_xaf' => $amount, 'balance_xaf' => $balance,
                'cycle_due_on' => (string) $item['due_on'], 'state' => self::DECLARED, 'open_key' => $openKey, 'verified_key' => null,
                'verified_via' => null, 'confirmed_amount_xaf' => null, 'confirmed_reference' => null, 'payment_id' => null,
                'decision_reason' => null, 'review_note' => null, 'declared_by' => $actorId, 'declared_at' => $now, 'expires_on' => $expiresOn,
                'decided_by' => null, 'decided_at' => null, 'expired_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (PDOException) {
            throw HttpException::conflict('Une déclaration de paiement est déjà en cours pour cette application.');
        }
        $this->audit->record($tenantId, $actorId, 'platform.regularisation_declared', 'regularisation_request', $id, [
            'app_key' => $appKey, 'kind' => $kind, 'channel' => $channel, 'amount_xaf' => $amount, 'balance_xaf' => $balance,
            'payer_phone' => CameroonPhone::mask($payerPhone), 'reference' => $reference, 'state' => ['before' => null, 'after' => self::DECLARED],
        ], $ip);
        $this->notifyOperator($tenantId, $row, $amount, $reference, $expiresOn);

        return [$this->present($this->findOrFail($id)), true];
    }

    /**
     * Confirme le paiement (super admin ou webhook) : montant réellement reçu, référence constatée. Un paiement qui couvre le
     * solde débloque (état actif, période prolongée, reçu, blocage levé) par le chemin des paiements manuels ; sinon il est
     * porté au crédit du solde. Une promesse de paiement peut être accordée dans le même geste (paiement partiel).
     *
     * @return array<string, mixed>
     */
    public function confirm(string $id, ?int $receivedXaf, ?string $reference, ?string $note, ?string $promiseUntil, string $via, string $actorId, ?string $ip): array
    {
        $request = $this->findOrFail($id);
        if (!in_array($request['state'], [self::DECLARED, self::EXPIRED], true)) {
            throw HttpException::conflict('Cette demande est déjà traitée.');
        }
        $received = $receivedXaf ?? (int) $request['declared_amount_xaf'];
        $verifiedKey = $reference !== null ? self::referenceKey($reference) : (string) $request['reference_key'];
        if (strlen($verifiedKey) < 4) {
            throw HttpException::validation(['reference' => ['La référence doit comporter au moins 4 lettres ou chiffres.']]);
        }
        if ($this->db->one('SELECT id FROM platform_regularisation_requests WHERE verified_key = ? AND id <> ?', [$verifiedKey, $id]) !== null) {
            throw HttpException::conflict('Cette référence a déjà validé une autre demande : un même paiement ne peut pas débloquer deux fois.');
        }

        $tenantId = (string) $request['tenant_id'];
        $appKey = (string) $request['app_key'];
        $outcome = $this->db->transaction(function () use ($request, $received, $verifiedKey, $reference, $note, $promiseUntil, $via, $actorId, $ip, $tenantId, $appKey): array {
            $row = $this->entitlements->rowOrFail($tenantId, $appKey);
            if ($row['state'] === ApplicationAccess::CLOSED) {
                throw HttpException::conflict('Cette application est fermée : rouvrez-la avant de confirmer un paiement.');
            }
            $months = $row['billing_period_months'] === null ? 1 : (int) $row['billing_period_months'];
            $due = $this->entitlements->totalOf($row) * $months;
            $credit = (int) ($row['regularisation_credit_xaf'] ?? 0);
            $anchor = $row['regularisation_credit_anchor'] === null ? null : (string) $row['regularisation_credit_anchor'];
            $tolerance = $this->settings->value(PlatformSettings::AMOUNT_TOLERANCE);
            $covers = $credit + $received + $tolerance >= $due;
            $before = ['state' => $row['state'], 'regularisation' => $row['regularisation'] ?? null, 'credit_xaf' => $credit, 'paid_until' => $row['paid_until']];
            $text = $note ?? sprintf('Régularisation par déclaration (référence %s)', $verifiedKey);

            if ($covers) {
                if ($anchor !== null) {
                    // Le paiement qui couvre le solde repart de l'échéance du cycle impayé, pas de l'avance accordée au prorata.
                    $this->db->update('tenant_applications', ['paid_until' => $anchor, 'updated_at' => $this->clock->iso()], ['tenant_id' => $tenantId, 'id' => (string) $row['id']]);
                }
                $payment = $this->manual->record($tenantId, $appKey, $months, (string) $request['channel'], $received, $text, $actorId, $ip, false);
                $this->sendConfirmed($tenantId, $row, $payment);
                $unlocked = true;
            } else {
                $payment = $this->recordPartial($row, $request, $received, $credit, $anchor, $due, $months, $text, $actorId);
                $unlocked = false;
                if ($promiseUntil !== null) {
                    $this->regularisation->grantDelay($tenantId, $appKey, $promiseUntil, 'Promesse de paiement accordée avec un paiement partiel (demande ' . $request['id'] . ').', $actorId, $ip);
                }
            }
            $now = $this->clock->iso();
            $this->db->update('platform_regularisation_requests', [
                'state' => self::VERIFIED, 'open_key' => null, 'verified_key' => $verifiedKey, 'verified_via' => $via, 'confirmed_amount_xaf' => $received,
                'confirmed_reference' => $reference, 'payment_id' => (string) $payment['id'], 'decided_by' => $actorId, 'decided_at' => $now, 'updated_at' => $now,
            ], ['tenant_id' => $tenantId, 'id' => (string) $request['id']]);
            $after = $this->entitlements->rowOrFail($tenantId, $appKey);
            $this->audit->record($tenantId, $actorId, 'platform.regularisation_confirmed', 'regularisation_request', (string) $request['id'], [
                'app_key' => $appKey, 'via' => $via, 'declared_amount_xaf' => (int) $request['declared_amount_xaf'], 'received_amount_xaf' => $received,
                'payment_id' => (string) $payment['id'], 'declared_by' => (string) $request['declared_by'], 'unlocked' => $unlocked,
                'before' => $before, 'after' => ['state' => $after['state'], 'regularisation' => $after['regularisation'] ?? null, 'credit_xaf' => (int) ($after['regularisation_credit_xaf'] ?? 0), 'paid_until' => $after['paid_until']],
                'request_state' => ['before' => $request['state'], 'after' => self::VERIFIED],
            ], $ip);

            return ['unlocked' => $unlocked];
        });

        return $this->present($this->findOrFail($id)) + ['unlocked' => $outcome['unlocked']];
    }

    /**
     * Rejette la demande (motif obligatoire) et en informe le client.
     *
     * @return array<string, mixed>
     */
    public function reject(string $id, string $reason, string $actorId, ?string $ip): array
    {
        $request = $this->findOrFail($id);
        if (!in_array($request['state'], [self::DECLARED, self::EXPIRED], true)) {
            throw HttpException::conflict('Cette demande est déjà traitée.');
        }
        $now = $this->clock->iso();
        $this->db->update('platform_regularisation_requests', [
            'state' => self::REJECTED, 'open_key' => null, 'decision_reason' => $reason, 'decided_by' => $actorId, 'decided_at' => $now, 'updated_at' => $now,
        ], ['tenant_id' => (string) $request['tenant_id'], 'id' => $id]);
        $tenantId = (string) $request['tenant_id'];
        $this->audit->record($tenantId, $actorId, 'platform.regularisation_rejected', 'regularisation_request', $id, [
            'app_key' => $request['app_key'], 'reason' => $reason, 'declared_by' => (string) $request['declared_by'],
            'request_state' => ['before' => $request['state'], 'after' => self::REJECTED],
        ], $ip);
        $this->notifier->toClient($tenantId, 'platform_regularisation_rejected', [
            'label' => $this->label((string) $request['app_key']), 'establishment_name' => $this->entitlements->tenantName($tenantId),
            'reference' => (string) $request['reference'], 'details' => $reason,
        ]);

        return $this->present($this->findOrFail($id));
    }

    /**
     * Rapprochement automatique d'un paiement confirmé par la passerelle, qui n'est celui d'aucun paiement initié par la
     * plateforme : référence, montant (dans la tolérance) et numéro payeur doivent correspondre à une demande ouverte.
     *
     * @return string|null résultat à journaliser, null si aucune demande ne porte cette référence
     */
    public function reconcile(PaymentWebhookEvent $event): ?string
    {
        $keys = array_values(array_unique(array_filter([self::referenceKey($event->providerReference), $event->reference === null ? '' : self::referenceKey($event->reference)], static fn (string $k): bool => strlen($k) >= 4)));
        if ($keys === []) {
            return null;
        }
        $request = $this->db->one(
            'SELECT * FROM platform_regularisation_requests WHERE state IN (?, ?) AND reference_key IN (' . implode(', ', array_fill(0, count($keys), '?')) . ') ORDER BY declared_at DESC LIMIT 1',
            [self::DECLARED, self::EXPIRED, ...$keys],
        );
        if ($request === null) {
            return null;
        }
        $tolerance = $this->settings->value(PlatformSettings::AMOUNT_TOLERANCE);
        if (abs($event->amountXaf - (int) $request['declared_amount_xaf']) > $tolerance) {
            return $this->flag($request, sprintf('Écart de montant : reçu %d FCFA, déclaré %d FCFA.', $event->amountXaf, (int) $request['declared_amount_xaf']), 'regularisation_amount_mismatch');
        }
        if ($event->phone !== null && CameroonPhone::significant($event->phone) !== CameroonPhone::significant((string) $request['payer_phone'])) {
            return $this->flag($request, 'Le numéro payeur de la passerelle ne correspond pas au numéro déclaré.', 'regularisation_phone_mismatch');
        }
        try {
            $this->confirm((string) $request['id'], $event->amountXaf, $event->providerReference, 'Confirmé par la passerelle de paiement', null, self::VIA_WEBHOOK, self::WEBHOOK_ACTOR, null);
        } catch (HttpException) {
            return $this->flag($request, 'La confirmation automatique a été refusée (référence déjà utilisée ou application fermée).', 'regularisation_conflict');
        }

        return 'regularisation_verified';
    }

    /**
     * Passe en « expirée » les demandes non confirmées à temps et relance le client. Rejouable sans effet double.
     *
     * @return int demandes expirées
     */
    public function expireStale(?string $tenantId = null): int
    {
        $today = $this->clock->today();
        $sql = 'SELECT * FROM platform_regularisation_requests WHERE state = ? AND expires_on < ?';
        $params = [self::DECLARED, $today];
        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = ?';
            $params[] = $tenantId;
        }
        $done = 0;
        foreach ($this->db->all($sql . ' ORDER BY declared_at', $params) as $request) {
            $now = $this->clock->iso();
            $changed = $this->db->update('platform_regularisation_requests', ['state' => self::EXPIRED, 'open_key' => null, 'expired_at' => $now, 'updated_at' => $now], [
                'tenant_id' => (string) $request['tenant_id'], 'id' => (string) $request['id'], 'state' => self::DECLARED,
            ]);
            if ($changed === 0) {
                continue;
            }
            ++$done;
            $tenant = (string) $request['tenant_id'];
            $this->audit->record($tenant, null, 'platform.regularisation_expired', 'regularisation_request', (string) $request['id'], [
                'app_key' => $request['app_key'], 'expires_on' => $request['expires_on'], 'request_state' => ['before' => self::DECLARED, 'after' => self::EXPIRED],
            ]);
            $this->notifier->toClient($tenant, 'platform_regularisation_expired', [
                'label' => $this->label((string) $request['app_key']), 'establishment_name' => $this->entitlements->tenantName($tenant),
                'reference' => (string) $request['reference'], 'date' => Calendar::display((string) $request['expires_on']), 'amount' => (int) $request['balance_xaf'],
            ]);
        }

        return $done;
    }

    /**
     * Demandes d'un client (les plus récentes d'abord), numéro payeur masqué.
     *
     * @return list<array<string, mixed>>
     */
    public function forClient(string $tenantId, int $limit = 10): array
    {
        $this->expireStale($tenantId);

        return array_map(fn (array $r): array => $this->present($r), $this->db->all(
            'SELECT * FROM platform_regularisation_requests WHERE tenant_id = ? ORDER BY declared_at DESC, id DESC LIMIT ' . max(1, $limit),
            [$tenantId],
        ));
    }

    /**
     * Demandes pour le super admin, filtrées par état, moyen et client.
     *
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function overview(?string $state, ?string $channel, ?string $tenantId): array
    {
        $this->expireStale();
        $sql = 'SELECT * FROM platform_regularisation_requests WHERE 1 = 1';
        $params = [];
        foreach (['state' => $state, 'channel' => $channel, 'tenant_id' => $tenantId] as $column => $value) {
            if ($value !== null) {
                $sql .= ' AND ' . $column . ' = ?';
                $params[] = $value;
            }
        }
        $items = array_map(fn (array $r): array => $this->present($r, true), $this->db->all($sql . ' ORDER BY declared_at DESC, id DESC LIMIT 500', $params));
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($this->db->all('SELECT state, COUNT(*) AS n FROM platform_regularisation_requests GROUP BY state') as $row) {
            $counts[(string) $row['state']] = (int) $row['n'];
        }

        return ['items' => $items, 'counts' => $counts];
    }

    /** Référence normalisée : majuscules, lettres et chiffres seulement (« ab-12 34 » et « AB1234 » sont la même référence). */
    public static function referenceKey(string $reference): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $reference));
    }

    /** @return array<string, mixed> */
    public function findOrFail(string $id): array
    {
        return $this->db->one('SELECT * FROM platform_regularisation_requests WHERE id = ?', [$id]) ?? throw HttpException::notFound('Demande introuvable.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(array $row, bool $admin = false): array
    {
        $tenantId = (string) $row['tenant_id'];

        return [
            'id' => (string) $row['id'],
            'tenant_id' => $tenantId,
            'app_key' => (string) $row['app_key'],
            'label' => $this->label((string) $row['app_key']),
            'kind' => (string) $row['kind'],
            'channel' => (string) $row['channel'],
            'payer_phone_masked' => CameroonPhone::mask((string) $row['payer_phone']),
            'reference' => (string) $row['reference'],
            'declared_amount_xaf' => (int) $row['declared_amount_xaf'],
            'balance_xaf' => (int) $row['balance_xaf'],
            'state' => (string) $row['state'],
            'verified_via' => $row['verified_via'],
            'confirmed_amount_xaf' => $row['confirmed_amount_xaf'] === null ? null : (int) $row['confirmed_amount_xaf'],
            'decision_reason' => $row['decision_reason'],
            'declared_at' => (string) $row['declared_at'],
            'expires_on' => (string) $row['expires_on'],
            'decided_at' => $row['decided_at'],
        ] + ($admin ? [
            'tenant_name' => $this->entitlements->tenantName($tenantId),
            'review_note' => $row['review_note'],
            'confirmed_reference' => $row['confirmed_reference'],
            'declared_by' => (string) $row['declared_by'],
            'decided_by' => $row['decided_by'],
            'payment_id' => $row['payment_id'],
        ] : []);
    }

    /**
     * Paiement partiel : reçu, crédit du solde et, selon le réglage, prolongation proportionnelle de la période (le niveau de
     * blocage ne change pas).
     *
     * @param array<string, mixed> $row ligne de tenant_applications
     * @param array<string, mixed> $request
     * @return array<string, mixed> le paiement enregistré
     */
    private function recordPartial(array $row, array $request, int $received, int $credit, ?string $anchor, int $due, int $months, string $note, string $actorId): array
    {
        $tenantId = (string) $row['tenant_id'];
        $dueOn = Subscription::dueOn($row['paid_until'] === null ? null : (string) $row['paid_until'], (string) $row['trial_ends_on']);
        $anchor ??= $dueOn;
        $newCredit = $credit + $received;
        $paidUntil = null;
        if ($this->settings->value(PlatformSettings::PARTIAL_MODE) === 0) {
            $periodDays = Calendar::daysBetween($anchor, Calendar::addMonths($anchor, $months));
            $extra = intdiv($periodDays * $newCredit, max(1, $due));
            $paidUntil = $extra > 0 ? Calendar::addDays($anchor, $extra) : null;
        }
        $id = (string) new Ulid();
        $now = $this->clock->iso();
        $year = $this->clock->year();
        $receipt = SequenceService::format('SCH', $year, $this->sequences->next(PlatformAudit::PLATFORM_TENANT, PlatformAudit::PLATFORM_TENANT, 'platform_receipt', $year));
        $this->db->insert('platform_payments', [
            'id' => $id, 'tenant_id' => $tenantId, 'tenant_application_id' => (string) $row['id'], 'app_key' => (string) $row['app_key'],
            'reference' => 'PRT-' . $id, 'provider_reference' => null, 'idempotency_key' => null, 'amount_xaf' => $received, 'months' => 0,
            'channel' => (string) $request['channel'], 'payer_phone' => (string) $request['payer_phone'], 'status' => SubscriptionPayments::SUCCEEDED,
            'receipt_number' => $receipt, 'covered_from' => $paidUntil === null ? null : Calendar::addDays($anchor, 1), 'covered_until' => $paidUntil,
            'initiated_by' => $actorId, 'paid_at' => $now, 'payment_note' => $note, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $values = ['regularisation_credit_xaf' => $newCredit, 'regularisation_credit_anchor' => $anchor, 'updated_at' => $now];
        if ($paidUntil !== null) {
            $values['paid_until'] = $paidUntil;
        }
        $this->db->update('tenant_applications', $values, ['tenant_id' => $tenantId, 'id' => (string) $row['id']]);
        $this->audit->record($tenantId, $actorId, 'platform.payment_recorded', 'platform_payment', $id, [
            'app_key' => $row['app_key'], 'amount_xaf' => $received, 'partial' => true, 'balance_after_xaf' => max(0, $due - $newCredit), 'receipt_number' => $receipt,
        ]);
        $this->notifier->toClient($tenantId, 'platform_regularisation_partial', [
            'label' => $this->label((string) $row['app_key']), 'establishment_name' => $this->entitlements->tenantName($tenantId),
            'amount' => $received, 'receipt_number' => $receipt,
            'details' => sprintf('Solde restant : %d FCFA.', max(0, $due - $newCredit)),
        ]);

        return ['id' => $id];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payment
     */
    private function sendConfirmed(string $tenantId, array $row, array $payment): void
    {
        $this->notifier->toClient($tenantId, 'platform_regularisation_confirmed', [
            'label' => $this->label((string) $row['app_key']), 'establishment_name' => $this->entitlements->tenantName($tenantId),
            'amount' => (int) $payment['amount_xaf'], 'receipt_number' => (string) $payment['receipt_number'], 'due_on' => Calendar::display((string) $payment['covered_until']),
        ]);
    }

    /**
     * Prévient le super admin (contact de la plateforme) qu'une déclaration attend sa vérification.
     *
     * @param array<string, mixed> $row
     */
    private function notifyOperator(string $tenantId, array $row, int $amount, string $reference, string $expiresOn): void
    {
        $contact = $this->settings->contact();
        if ($contact['email'] === null && $contact['phone'] === null) {
            return;
        }
        $this->notifier->send('platform_regularisation_declared', 'fr', $contact['email'], $contact['phone'], [
            'label' => $this->label((string) $row['app_key']), 'establishment_name' => $this->entitlements->tenantName($tenantId),
            'amount' => $amount, 'reference' => $reference, 'date' => Calendar::display($expiresOn),
        ]);
    }

    /**
     * Note l'écart relevé par le rapprochement automatique ; la demande reste à vérifier par le super admin.
     *
     * @param array<string, mixed> $request
     */
    private function flag(array $request, string $note, string $outcome): string
    {
        $this->db->update('platform_regularisation_requests', ['review_note' => $note, 'updated_at' => $this->clock->iso()], ['tenant_id' => (string) $request['tenant_id'], 'id' => (string) $request['id']]);
        $this->audit->record((string) $request['tenant_id'], null, 'platform.regularisation_mismatch', 'regularisation_request', (string) $request['id'], ['outcome' => $outcome, 'note' => $note]);

        return $outcome;
    }

    private function label(string $appKey): string
    {
        return (string) ($this->catalog->find($appKey)['label'] ?? $appKey);
    }
}
