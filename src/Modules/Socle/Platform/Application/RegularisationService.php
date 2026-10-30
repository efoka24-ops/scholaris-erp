<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Domain\Calendar;
use Scholaris\Modules\Socle\Platform\Domain\Regularisation;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Mode régularisation de paiement : blocage à la main (motif obligatoire) ou automatique (commande quotidienne), déblocage,
 * promesse de paiement (report du blocage jusqu'à une date), liste « À régulariser » de la plateforme et vue du client.
 * Le blocage ne supprime jamais de données. Tout est journalisé avec l'état avant et après.
 */
final class RegularisationService
{
    public const BUCKET_PAST_DUE = 'past_due';
    public const BUCKET_REGULARISATION = 'regularisation';
    public const BUCKET_PROMISE = 'promise';
    public const DUE_SOON_STAGE = 7;

    public function __construct(
        private readonly PlatformDb $db,
        private readonly Catalog $catalog,
        private readonly EntitlementService $entitlements,
        private readonly PlatformSettings $settings,
        private readonly PlatformNotifier $notifier,
        private readonly PlatformAudit $audit,
        private readonly PlatformClock $clock,
    ) {
    }

    /**
     * Bloque l'application ciblée, ou toutes les applications ouvertes du client, au niveau demandé (sinon celui du client).
     *
     * @return list<array<string, mixed>> droits d'usage après blocage
     */
    public function lock(string $tenantId, ?string $appKey, ?string $level, string $reason, string $actorId, ?string $ip): array
    {
        $today = $this->clock->today();
        $done = [];
        foreach ($this->targets($tenantId, $appKey) as $row) {
            $state = (string) $row['state'];
            $applied = $level ?? $this->entitlements->lockLevelOf($row);
            if ($state === ApplicationAccess::SUSPENDED && ($row['regularisation'] ?? null) === $applied) {
                continue;
            }
            $now = $this->clock->iso();
            $this->db->update('tenant_applications', [
                'state' => ApplicationAccess::SUSPENDED,
                'suspended_at' => $row['suspended_at'] ?? $now,
                'state_reason' => $reason,
                'regularisation' => $applied,
                'regularisation_origin' => Regularisation::ORIGIN_MANUAL,
                'regularisation_since' => $row['regularisation_since'] ?? $today,
                'promise_until' => null,
                'promise_reason' => null,
                'updated_at' => $now,
            ], ['tenant_id' => $tenantId, 'id' => (string) $row['id']]);
            $after = $this->entitlements->rowOrFail($tenantId, (string) $row['app_key']);
            $this->audit->record($tenantId, $actorId, 'platform.regularisation_locked', 'tenant_application', (string) $row['id'], [
                'app_key' => $row['app_key'], 'level' => $applied, 'reason' => $reason,
                'before' => $this->snapshot($row), 'after' => $this->snapshot($after),
            ], $ip);
            $this->notifier->toClient($tenantId, 'platform_regularisation_locked', [
                'label' => (string) ($this->catalog->find((string) $row['app_key'])['label'] ?? $row['app_key']),
                'establishment_name' => $this->entitlements->tenantName($tenantId),
                'amount' => $this->entitlements->totalOf($after),
            ]);
            $done[] = $this->entitlements->present($after);
        }
        if ($done === []) {
            throw HttpException::conflict('Aucune application à bloquer : elles sont déjà bloquées à ce niveau.');
        }

        return $done;
    }

    /**
     * Débloque (motif obligatoire). Si l'application serait aussitôt re-bloquée faute de paiement, une promesse de paiement
     * est posée jusqu'à $until (par défaut 7 jours) : le déblocage manuel ne remplace pas le paiement.
     *
     * @return list<array<string, mixed>>
     */
    public function unlock(string $tenantId, ?string $appKey, ?string $until, string $reason, string $actorId, ?string $ip): array
    {
        $this->assertPromiseDate($until);
        $done = [];
        foreach ($this->targets($tenantId, $appKey) as $row) {
            if ($row['state'] !== ApplicationAccess::SUSPENDED || !$this->isUnpaidSuspension($row)) {
                continue;
            }
            $done[] = $this->release($row, $until, $reason, 'platform.regularisation_unlocked', $actorId, $ip);
        }
        if ($done === []) {
            throw HttpException::conflict('Aucune application bloquée pour impayé.');
        }

        return $done;
    }

    /**
     * Promesse de paiement : reporte le blocage jusqu'à $until. Une application déjà bloquée est débloquée jusqu'à cette date.
     *
     * @return list<array<string, mixed>>
     */
    public function grantDelay(string $tenantId, ?string $appKey, string $until, string $reason, string $actorId, ?string $ip): array
    {
        $this->assertPromiseDate($until, true);
        $done = [];
        foreach ($this->targets($tenantId, $appKey) as $row) {
            $state = (string) $row['state'];
            if ($state === ApplicationAccess::SUSPENDED && $this->isUnpaidSuspension($row)) {
                $done[] = $this->release($row, $until, $reason, 'platform.regularisation_delay_granted', $actorId, $ip);
            } elseif ($state === ApplicationAccess::PAST_DUE || (in_array($state, [ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE], true) && strcmp($this->clock->today(), $this->dueOn($row)) > 0)) {
                $this->db->update('tenant_applications', ['promise_until' => $until, 'promise_reason' => $reason, 'updated_at' => $this->clock->iso()], ['tenant_id' => $tenantId, 'id' => (string) $row['id']]);
                $after = $this->entitlements->rowOrFail($tenantId, (string) $row['app_key']);
                $this->audit->record($tenantId, $actorId, 'platform.regularisation_delay_granted', 'tenant_application', (string) $row['id'], [
                    'app_key' => $row['app_key'], 'until' => $until, 'reason' => $reason, 'before' => $this->snapshot($row), 'after' => $this->snapshot($after),
                ], $ip);
                $done[] = $this->entitlements->present($after);
            }
        }
        if ($done === []) {
            throw HttpException::conflict('Aucune application impayée ou bloquée : aucun délai à accorder.');
        }

        return $done;
    }

    /**
     * Niveau de blocage choisi pour tout le client (null : retour au réglage de la plateforme), motif obligatoire.
     *
     * @return list<array<string, mixed>>
     */
    public function setClientLevel(string $tenantId, ?string $level, string $reason, string $actorId, ?string $ip): array
    {
        $done = [];
        foreach ($this->targets($tenantId, null) as $row) {
            $done[] = $this->entitlements->setTerms($tenantId, (string) $row['app_key'], ['regularisation_level' => $level], $reason, $actorId, $ip);
        }

        return $done;
    }

    /**
     * Liste « À régulariser » : impayés, applications en régularisation et promesses en cours, du plus en retard au moins en retard.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(?string $bucket = null): array
    {
        $today = $this->clock->today();
        $default = $this->settings->value(PlatformSettings::REGULARISATION_LEVEL);
        $items = [];
        foreach ($this->db->all('SELECT * FROM tenant_applications WHERE state IN (?, ?, ?, ?) ORDER BY tenant_id, app_key', [
            ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE, ApplicationAccess::PAST_DUE, ApplicationAccess::SUSPENDED,
        ]) as $row) {
            $item = $this->item($row, $today, $default);
            if ($item === null || ($bucket !== null && $item['bucket'] !== $bucket)) {
                continue;
            }
            $items[] = $item + ['tenant_name' => $this->entitlements->tenantName((string) $row['tenant_id'])];
        }
        usort($items, static fn (array $a, array $b): int => [$b['days_late'], $a['tenant_name']] <=> [$a['days_late'], $b['tenant_name']]);

        return $items;
    }

    /**
     * Vue du client : mode courant, détail par application (montant dû, période, jours écoulés), reçus et contact.
     *
     * @return array<string, mixed>
     */
    public function forClient(string $tenantId, bool $canPay): array
    {
        $today = $this->clock->today();
        $default = $this->settings->value(PlatformSettings::REGULARISATION_LEVEL);
        $items = [];
        $mode = 'ok';
        $contact = $this->settings->contact();
        $rank = ['ok' => 0, 'past_due' => 1, Regularisation::READ_ONLY => 2, Regularisation::LOCKED => 3];
        foreach ($this->db->all('SELECT * FROM tenant_applications WHERE tenant_id = ? ORDER BY app_key', [$tenantId]) as $row) {
            $item = $this->item($row, $today, $default);
            if ($item === null) {
                continue;
            }
            $item['receipts'] = array_map(static fn (array $r): array => [
                'id' => (string) $r['id'], 'receipt_number' => (string) $r['receipt_number'], 'amount_xaf' => (int) $r['amount_xaf'], 'paid_at' => $r['paid_at'],
            ], $this->db->all(
                'SELECT id, receipt_number, amount_xaf, paid_at FROM platform_payments WHERE tenant_id = ? AND app_key = ? AND status = ? ORDER BY paid_at DESC LIMIT 3',
                [$tenantId, (string) $row['app_key'], SubscriptionPayments::SUCCEEDED],
            ));
            $own = $item['state'] === ApplicationAccess::PAST_DUE ? 'past_due' : (string) ($item['level'] ?? Regularisation::READ_ONLY);
            if ($item['bucket'] !== self::BUCKET_PROMISE && $rank[$own] > $rank[$mode]) {
                $mode = $own;
            }
            $items[] = $item;
        }

        return [
            'mode' => $mode,
            'locked' => $mode === Regularisation::LOCKED,
            'can_pay' => $canPay,
            'items' => $items,
            'contact' => ['name' => $contact['name'], 'email' => $contact['email'], 'phone' => $contact['phone']],
            'payment_numbers' => $contact['payments'],
            'partial_min_xaf' => $this->settings->value(PlatformSettings::PARTIAL_MIN),
            'tolerance_xaf' => $this->settings->value(PlatformSettings::AMOUNT_TOLERANCE),
            'expiry_days' => $this->settings->value(PlatformSettings::REQUEST_EXPIRY_DAYS),
        ];
    }

    /**
     * Préavis « échéance dans 7 jours » (une fois par échéance), appelé par la commande quotidienne.
     *
     * @return int préavis envoyés
     */
    public function notifyDueSoon(string $today): int
    {
        $sent = 0;
        foreach ($this->db->all('SELECT * FROM tenant_applications WHERE state IN (?, ?) ORDER BY tenant_id, app_key', [ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE]) as $row) {
            $dueOn = $this->dueOn($row);
            $left = Calendar::daysBetween($today, $dueOn);
            // Fenêtre J-7 à J-4 : une exécution manquée ne fait pas perdre le préavis, et il reste distinct de J-3.
            if ($left > self::DUE_SOON_STAGE || $left <= 3) {
                continue;
            }
            try {
                $this->db->insert('platform_reminders', [
                    'id' => (string) new Ulid(), 'tenant_id' => (string) $row['tenant_id'], 'tenant_application_id' => (string) $row['id'],
                    'due_on' => $dueOn, 'stage' => self::DUE_SOON_STAGE, 'outcome' => 'pending', 'created_at' => $this->clock->iso(),
                ]);
            } catch (PDOException) {
                continue;
            }
            $status = $this->notifier->toClient((string) $row['tenant_id'], 'platform_subscription_due_soon', [
                'label' => (string) ($this->catalog->find((string) $row['app_key'])['label'] ?? $row['app_key']),
                'establishment_name' => $this->entitlements->tenantName((string) $row['tenant_id']),
                'due_on' => Calendar::display($dueOn),
                'offset' => $left,
                'amount' => $this->entitlements->totalOf($row),
            ]);
            $this->db->update('platform_reminders', ['outcome' => $status], ['tenant_id' => (string) $row['tenant_id'], 'tenant_application_id' => (string) $row['id'], 'due_on' => $dueOn, 'stage' => self::DUE_SOON_STAGE]);
            ++$sent;
        }

        return $sent;
    }

    /**
     * Situation de paiement d'une application (montant dû, solde, échéance) ; null si elle n'a ni retard, ni blocage, ni promesse.
     *
     * @param array<string, mixed> $row ligne de tenant_applications
     * @return array<string, mixed>|null
     */
    public function itemOf(array $row): ?array
    {
        return $this->item($row, $this->clock->today(), $this->settings->value(PlatformSettings::REGULARISATION_LEVEL));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null null : ni retard, ni blocage pour impayé, ni promesse
     */
    private function item(array $row, string $today, int $platformDefault): ?array
    {
        $grace = $this->entitlements->graceDaysOf($row);
        $effective = Regularisation::effective($row, $today, $grace, $platformDefault);
        $promise = $row['promise_until'] === null ? null : (string) $row['promise_until'];
        $promiseActive = $promise !== null && strcmp($today, $promise) <= 0;
        $state = $effective['state'];
        $blocked = $state === ApplicationAccess::SUSPENDED && ($effective['level'] !== null || ($row['state_reason'] ?? null) === 'unpaid');
        if (!$blocked && $state !== ApplicationAccess::PAST_DUE && !$promiseActive) {
            return null;
        }
        $dueOn = $this->dueOn($row);
        $months = $row['billing_period_months'] === null ? 1 : (int) $row['billing_period_months'];
        $options = array_map(static fn (array $o): array => ['option_key' => $o['option_key'], 'label_fr' => $o['label_fr'], 'label_en' => $o['label_en'], 'price_xaf' => $o['price_xaf']], $this->entitlements->present($row)['options']);
        $monthly = $this->entitlements->totalOf($row);
        $credit = (int) ($row['regularisation_credit_xaf'] ?? 0);
        // Dernier jour avant le blocage (fin du délai de grâce, ou de la promesse de paiement si elle va plus loin) pour un impayé non encore bloqué.
        $blocksOn = $blocked ? null : ($promiseActive ? Calendar::max(Calendar::addDays($dueOn, $grace), (string) $promise) : Calendar::addDays($dueOn, $grace));
        $since = $row['regularisation_since'] ?? $row['past_due_since'] ?? Calendar::addDays($dueOn, 1);

        return [
            'tenant_id' => (string) $row['tenant_id'],
            'app_key' => (string) $row['app_key'],
            'label' => (string) ($this->catalog->find((string) $row['app_key'])['label'] ?? $row['app_key']),
            'state' => $state,
            'level' => $blocked ? ($effective['level'] ?? Regularisation::READ_ONLY) : null,
            'bucket' => $promiseActive ? self::BUCKET_PROMISE : ($blocked ? self::BUCKET_REGULARISATION : self::BUCKET_PAST_DUE),
            'origin' => $row['regularisation_origin'] ?? null,
            'due_on' => $dueOn,
            'period_from' => Calendar::addDays($dueOn, 1),
            'since' => (string) $since,
            'days_late' => max(0, Calendar::daysBetween($dueOn, $today)),
            'days_elapsed' => max(0, Calendar::daysBetween((string) $since, $today)),
            'months' => $months,
            'monthly_total_xaf' => $monthly,
            'base_price_xaf' => $this->entitlements->priceOf($row),
            'options' => $options,
            'amount_due_xaf' => $monthly * $months,
            'blocks_on' => $blocksOn,
            'days_left' => $blocksOn === null ? null : max(0, Calendar::daysBetween($today, $blocksOn)),
            'credit_xaf' => $credit,
            'balance_xaf' => max(0, $monthly * $months - $credit),
            'promise_until' => $promise,
            'promise_reason' => $row['promise_reason'] ?? null,
        ];
    }

    /**
     * Fin du blocage : l'application retrouve son état d'après l'échéance ; promesse posée si l'impayé la re-bloquerait.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function release(array $row, ?string $until, string $reason, string $action, string $actorId, ?string $ip): array
    {
        $tenantId = (string) $row['tenant_id'];
        $today = $this->clock->today();
        $reopened = $this->entitlements->reopenedState($row, $today);
        $promise = null;
        if ($reopened['state'] === ApplicationAccess::PAST_DUE) {
            $promise = $until ?? Calendar::addDays($today, Regularisation::DEFAULT_UNLOCK_DAYS);
        }
        $this->db->update('tenant_applications', [
            'promise_until' => $promise, 'promise_reason' => $promise === null ? null : $reason,
            'suspended_at' => null, 'state_reason' => null, 'updated_at' => $this->clock->iso(),
        ] + $reopened + EntitlementService::LOCK_CLEARED, ['tenant_id' => $tenantId, 'id' => (string) $row['id']]);
        $after = $this->entitlements->rowOrFail($tenantId, (string) $row['app_key']);
        $this->audit->record($tenantId, $actorId, $action, 'tenant_application', (string) $row['id'], [
            'app_key' => $row['app_key'], 'until' => $promise, 'reason' => $reason, 'before' => $this->snapshot($row), 'after' => $this->snapshot($after),
        ], $ip);
        $this->notifier->toClient($tenantId, 'platform_regularisation_lifted', [
            'label' => (string) ($this->catalog->find((string) $row['app_key'])['label'] ?? $row['app_key']),
            'establishment_name' => $this->entitlements->tenantName($tenantId),
            'due_on' => Calendar::display($promise ?? $this->dueOn($after)),
        ]);

        return $this->entitlements->present($after);
    }

    /**
     * Lignes visées : l'application ciblée, ou toutes les applications non fermées du client.
     *
     * @return list<array<string, mixed>>
     */
    private function targets(string $tenantId, ?string $appKey): array
    {
        if ($appKey !== null) {
            $row = $this->entitlements->rowOrFail($tenantId, $appKey);

            return $row['state'] === ApplicationAccess::CLOSED ? throw HttpException::conflict('Cette application est fermée.') : [$row];
        }
        $rows = $this->db->all('SELECT * FROM tenant_applications WHERE tenant_id = ? AND state <> ? ORDER BY app_key', [$tenantId, ApplicationAccess::CLOSED]);
        if ($rows === []) {
            throw HttpException::conflict('Ce client n\'a aucune application ouverte.');
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function isUnpaidSuspension(array $row): bool
    {
        return ($row['regularisation'] ?? null) !== null || ($row['state_reason'] ?? null) === 'unpaid';
    }

    /** @param array<string, mixed> $row */
    private function dueOn(array $row): string
    {
        return \Scholaris\Modules\Socle\Platform\Domain\Subscription::dueOn($row['paid_until'] === null ? null : (string) $row['paid_until'], (string) $row['trial_ends_on']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function snapshot(array $row): array
    {
        return [
            'state' => (string) $row['state'],
            'regularisation' => $row['regularisation'] ?? null,
            'promise_until' => $row['promise_until'] ?? null,
        ];
    }

    private function assertPromiseDate(?string $until, bool $required = false): void
    {
        if ($until === null) {
            if ($required) {
                throw HttpException::validation(['until' => ['Champ obligatoire.']]);
            }

            return;
        }
        $today = $this->clock->today();
        if (strcmp($until, $today) <= 0 || strcmp($until, Calendar::addDays($today, Regularisation::MAX_PROMISE_DAYS)) > 0) {
            throw HttpException::validation(['until' => [sprintf('Date attendue entre demain et %d jours.', Regularisation::MAX_PROMISE_DAYS)]]);
        }
    }
}
