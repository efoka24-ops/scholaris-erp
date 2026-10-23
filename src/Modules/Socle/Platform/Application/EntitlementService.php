<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\ModuleActivator;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;
use Scholaris\Modules\Socle\Platform\Domain\Calendar;
use Scholaris\Modules\Socle\Platform\Domain\Regularisation;
use Scholaris\Modules\Socle\Platform\Domain\Subscription;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Droits d'usage des applications par client : ouverture (libre-service, administrateur de plateforme ou demande
 * d'accès acceptée) avec essai gratuit, suspension, fermeture, réactivation, tarif propre au client.
 * Toute requête porte `tenant_id = ?` explicite.
 */
final class EntitlementService
{
    public const SOURCE_SELF_SERVICE = 'self_service';
    public const SOURCE_PLATFORM = 'platform_admin';
    public const SOURCE_ACCESS_REQUEST = 'access_request';
    /** Périodicités de facturation permises (mois). */
    public const BILLING_PERIODS = [1, 3, 12];
    /** Colonnes remises à zéro quand le blocage prend fin (paiement, réactivation, fermeture). */
    /** Crédit d'un paiement partiel, remis à zéro quand un paiement couvre le solde. */
    public const CREDIT_CLEARED = ['regularisation_credit_xaf' => null, 'regularisation_credit_anchor' => null];
    public const LOCK_CLEARED = ['regularisation' => null, 'regularisation_origin' => null, 'regularisation_since' => null, 'promise_until' => null, 'promise_reason' => null];

    public function __construct(
        private readonly PlatformDb $db,
        private readonly Catalog $catalog,
        private readonly ClientProvisioning $clients,
        private readonly EstablishmentDirectory $establishments,
        private readonly ModuleActivator $modules,
        private readonly PlatformAudit $audit,
        private readonly PlatformClock $clock,
        private readonly ServiceOptions $options,
        private readonly PlatformSettings $settings,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function row(string $tenantId, string $appKey): ?array
    {
        return $this->db->one('SELECT * FROM tenant_applications WHERE tenant_id = ? AND app_key = ?', [$tenantId, $appKey]);
    }

    /** @return array<string, mixed> */
    public function rowOrFail(string $tenantId, string $appKey): array
    {
        return $this->row($tenantId, $appKey) ?? throw HttpException::notFound('Application non ouverte pour ce client.');
    }

    /** @return list<array<string, mixed>> droits d'usage du client, présentés */
    public function forTenant(string $tenantId): array
    {
        return array_map(fn (array $row): array => $this->present($row), $this->db->all('SELECT * FROM tenant_applications WHERE tenant_id = ? ORDER BY app_key', [$tenantId]));
    }

    /**
     * Ouvre une application à un client, en essai gratuit ; active les modules du socle requis pour ses établissements
     * de la cible. Une application fermée peut être rouverte : l'essai déjà consommé n'est pas renouvelé.
     *
     * @return array<string, mixed>
     */
    public function open(string $tenantId, string $appKey, string $source, string $actorId, ?string $ip = null, ?string $forceReason = null): array
    {
        $app = $this->catalog->findOrFail($appKey);
        $status = (string) $app['status'];
        $forced = false;
        if ($source === self::SOURCE_SELF_SERVICE && $app['listed'] !== true) {
            throw HttpException::validation(['app_key' => ['Cette application n\'est pas proposée au catalogue.']], 'Application non disponible.');
        }
        if ($source === self::SOURCE_PLATFORM) {
            // Ouverture directe par l'administrateur de plateforme : application disponible ou à venir (règle inchangée).
            if (!in_array($status, [ApplicationManifest::AVAILABLE, ApplicationManifest::COMING_SOON], true)) {
                throw HttpException::conflict('Cette application n\'est pas ouverte aux nouveaux clients.');
            }
        } elseif ($status !== ApplicationManifest::AVAILABLE) {
            // F2 : le libre-service n'ouvre qu'une application disponible ; une demande d'accès n'ouvre pas en essai une
            // application à venir ou retirée, sauf forçage explicite et motivé par l'administrateur de plateforme.
            $forced = $source === self::SOURCE_ACCESS_REQUEST && $forceReason !== null && trim($forceReason) !== '';
            if (!$forced) {
                throw HttpException::validation(['app_key' => [$status === ApplicationManifest::COMING_SOON
                    ? 'Cette application n\'est pas encore disponible : elle ne peut pas être ouverte en essai.'
                    : 'Cette application est retirée du catalogue : elle ne peut plus être ouverte.']], 'Application non disponible.');
            }
        }
        if (!$this->clients->tenantExists($tenantId)) {
            throw HttpException::notFound('Client introuvable.');
        }

        $today = $this->clock->today();
        $now = $this->clock->iso();
        $existing = $this->row($tenantId, $appKey);
        if ($existing !== null && $existing['state'] !== ApplicationAccess::CLOSED) {
            throw HttpException::conflict('Cette application est déjà ouverte pour ce client.');
        }

        if ($existing === null) {
            $this->db->insert('tenant_applications', [
                'id' => (string) new Ulid(),
                'tenant_id' => $tenantId,
                'app_key' => $appKey,
                'state' => ApplicationAccess::TRIAL,
                'source' => $source,
                'started_on' => $today,
                'trial_ends_on' => Subscription::trialEnd($today, (int) $app['trial_days']),
                'paid_until' => null,
                'monthly_price_xaf' => null,
                'past_due_since' => null,
                'suspended_at' => null,
                'closed_at' => null,
                'state_reason' => null,
                'opened_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $this->db->update('tenant_applications', $this->reopenedState($existing, $today) + [
                'source' => $source, 'closed_at' => null, 'suspended_at' => null, 'state_reason' => null, 'opened_by' => $actorId, 'updated_at' => $now,
            ], ['tenant_id' => $tenantId, 'id' => (string) $existing['id']]);
        }

        $this->activateModules($tenantId, $app);
        $row = $this->rowOrFail($tenantId, $appKey);
        $this->audit->record($tenantId, $actorId, 'platform.application_opened', 'tenant_application', (string) $row['id'], ['app_key' => $appKey, 'source' => $source, 'state' => $row['state']] + ($forced ? ['forced' => true, 'catalog_status' => $status, 'force_reason' => (string) $forceReason] : []), $ip);

        return $this->present($row);
    }

    /**
     * Décision de l'administrateur de plateforme : suspend, close ou reactivate (réactivation : jusqu'à `until` si fourni).
     *
     * @return array<string, mixed>
     */
    public function decide(string $tenantId, string $appKey, string $action, ?string $reason, ?string $until, string $actorId, ?string $ip = null): array
    {
        $row = $this->rowOrFail($tenantId, $appKey);
        $state = (string) $row['state'];
        $now = $this->clock->iso();
        $today = $this->clock->today();

        $values = match ($action) {
            'suspend' => in_array($state, [ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE, ApplicationAccess::PAST_DUE], true)
                ? ['state' => ApplicationAccess::SUSPENDED, 'suspended_at' => $now, 'state_reason' => $reason]
                : throw HttpException::conflict('Seule une application en essai, active ou impayée peut être suspendue.'),
            'close' => $state !== ApplicationAccess::CLOSED
                ? ['state' => ApplicationAccess::CLOSED, 'closed_at' => $now, 'state_reason' => $reason] + self::LOCK_CLEARED
                : throw HttpException::conflict('Cette application est déjà fermée.'),
            'reactivate' => $state === ApplicationAccess::SUSPENDED
                ? $this->reactivatedState($row, $today, $until) + ['suspended_at' => null, 'state_reason' => $reason] + self::LOCK_CLEARED
                : throw HttpException::conflict('Seule une application suspendue peut être réactivée.'),
            default => throw HttpException::notFound(),
        };
        $this->db->update('tenant_applications', $values + ['updated_at' => $now], ['tenant_id' => $tenantId, 'id' => (string) $row['id']]);
        $this->audit->record($tenantId, $actorId, 'platform.application_' . $action, 'tenant_application', (string) $row['id'], ['app_key' => $appKey, 'reason' => $reason, 'until' => $until], $ip);

        return $this->present($this->rowOrFail($tenantId, $appKey));
    }

    /**
     * Tarif mensuel propre au client (null : tarif du catalogue).
     *
     * @return array<string, mixed>
     */
    public function setPrice(string $tenantId, string $appKey, ?int $priceXaf, string $actorId, ?string $ip = null): array
    {
        $row = $this->rowOrFail($tenantId, $appKey);
        $this->db->update('tenant_applications', ['monthly_price_xaf' => $priceXaf, 'updated_at' => $this->clock->iso()], ['tenant_id' => $tenantId, 'id' => (string) $row['id']]);
        $this->audit->record($tenantId, $actorId, 'platform.application_price_set', 'tenant_application', (string) $row['id'], ['app_key' => $appKey, 'monthly_price_xaf' => $priceXaf], $ip);

        return $this->present($this->rowOrFail($tenantId, $appKey));
    }

    /** Nom du client (clients de la plateforme, via Organisation). */
    public function tenantName(string $tenantId): string
    {
        foreach ($this->clients->tenants() as $tenant) {
            if ($tenant['id'] === $tenantId) {
                return $tenant['name'];
            }
        }

        return $tenantId;
    }

    /**
     * Tarif mensuel effectif (propre au client, sinon catalogue).
     *
     * @param array<string, mixed> $row ligne de tenant_applications
     */
    public function priceOf(array $row): int
    {
        if ($row['monthly_price_xaf'] !== null) {
            return (int) $row['monthly_price_xaf'];
        }

        return (int) ($this->catalog->find((string) $row['app_key'])['monthly_price_xaf'] ?? 0);
    }

    /**
     * Délai de grâce effectif : surcharge du client, sinon catalogue.
     *
     * @param array<string, mixed> $row
     */
    public function graceDaysOf(array $row): int
    {
        return $row['grace_days_override'] !== null ? (int) $row['grace_days_override'] : (int) ($this->catalog->find((string) $row['app_key'])['grace_days'] ?? 0);
    }

    /**
     * Durée de l'essai effective : surcharge du client, sinon catalogue.
     *
     * @param array<string, mixed> $row
     */
    public function trialDaysOf(array $row): int
    {
        return $row['trial_days_override'] !== null ? (int) $row['trial_days_override'] : (int) ($this->catalog->find((string) $row['app_key'])['trial_days'] ?? 0);
    }

    /**
     * Niveau de blocage effectif en cas d'impayé : choix pour le client, sinon réglage de la plateforme.
     *
     * @param array<string, mixed> $row
     */
    public function lockLevelOf(array $row): string
    {
        return Regularisation::level($row['lock_level'] === null ? null : (string) $row['lock_level'], $this->settings->value(PlatformSettings::REGULARISATION_LEVEL));
    }

    /**
     * Montant mensuel dû : tarif de base (ou surcharge du client) plus les options souscrites.
     *
     * @param array<string, mixed> $row ligne de tenant_applications
     */
    public function totalOf(array $row): int
    {
        return $this->priceOf($row) + $this->options->totalFor((string) $row['tenant_id'], (string) $row['app_key']);
    }

    /**
     * Délais et périodicité propres au client pour une application (null : valeur du catalogue). Motif obligatoire, journalisé
     * avec les valeurs avant et après. La durée de l'essai se règle tant qu'aucun paiement n'a été reçu : l'échéance d'essai est recalculée.
     *
     * @param array<string, int|string|null> $changes trial_days, grace_days, billing_period_months, regularisation_level
     * @return array<string, mixed>
     */
    public function setTerms(string $tenantId, string $appKey, array $changes, string $reason, string $actorId, ?string $ip): array
    {
        $row = $this->rowOrFail($tenantId, $appKey);
        $columns = ['trial_days' => 'trial_days_override', 'grace_days' => 'grace_days_override', 'billing_period_months' => 'billing_period_months', 'regularisation_level' => 'lock_level'];
        $values = [];
        foreach ($columns as $field => $column) {
            if (array_key_exists($field, $changes)) {
                $values[$column] = $changes[$field];
            }
        }
        if ($values === []) {
            throw HttpException::validation(['trial_days' => ['Aucune modification demandée.']]);
        }
        $before = $this->termsOf($row);
        if (array_key_exists('trial_days_override', $values)) {
            if ($row['paid_until'] !== null) {
                throw HttpException::conflict('L\'essai est terminé (un paiement a été reçu) : sa durée ne peut plus être modifiée.');
            }
            $days = $values['trial_days_override'] === null ? (int) ($this->catalog->find($appKey)['trial_days'] ?? 0) : (int) $values['trial_days_override'];
            $values['trial_ends_on'] = Subscription::trialEnd((string) $row['started_on'], $days);
        }
        $this->db->update('tenant_applications', $values + ['updated_at' => $this->clock->iso()], ['tenant_id' => $tenantId, 'id' => (string) $row['id']]);
        if (array_key_exists('lock_level', $values) && $row['regularisation'] !== null) {
            // Le choix du niveau s'applique aussitôt à une application déjà en régularisation.
            $level = $this->lockLevelOf($this->rowOrFail($tenantId, $appKey));
            $this->db->update('tenant_applications', ['regularisation' => $level], ['tenant_id' => $tenantId, 'id' => (string) $row['id']]);
        }
        $updated = $this->rowOrFail($tenantId, $appKey);
        $this->audit->record($tenantId, $actorId, 'platform.application_terms_set', 'tenant_application', (string) $row['id'], [
            'app_key' => $appKey, 'reason' => $reason, 'before' => $before, 'after' => $this->termsOf($updated),
        ], $ip);

        return $this->present($updated);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed> délais effectifs et indicateurs de surcharge
     */
    public function termsOf(array $row): array
    {
        return [
            'trial_days' => $this->trialDaysOf($row),
            'trial_days_overridden' => $row['trial_days_override'] !== null,
            'grace_days' => $this->graceDaysOf($row),
            'grace_days_overridden' => $row['grace_days_override'] !== null,
            'billing_period_months' => $row['billing_period_months'] === null ? 1 : (int) $row['billing_period_months'],
            'billing_period_overridden' => $row['billing_period_months'] !== null,
            'regularisation_level' => $this->lockLevelOf($row),
            'regularisation_level_overridden' => $row['lock_level'] !== null,
        ];
    }

    /**
     * @param array<string, mixed> $row ligne de tenant_applications
     * @return array<string, mixed> vue publique (liste blanche)
     */
    public function present(array $row): array
    {
        $app = $this->catalog->find((string) $row['app_key']);
        $today = $this->clock->today();
        $state = (string) $row['state'];
        $trialEnds = (string) $row['trial_ends_on'];
        $paidUntil = $row['paid_until'] === null ? null : (string) $row['paid_until'];
        $dueOn = Subscription::dueOn($paidUntil, $trialEnds);
        $options = $this->options->forApplication((string) $row['tenant_id'], (string) $row['app_key']);
        $optionsTotal = array_sum(array_column($options, 'price_xaf'));
        $locked = $state === ApplicationAccess::SUSPENDED && ($row['regularisation'] ?? null) === Regularisation::LOCKED;
        $inRegularisation = $state === ApplicationAccess::SUSPENDED && ($row['regularisation'] ?? null) !== null;

        return [
            'id' => (string) $row['id'],
            'tenant_id' => (string) $row['tenant_id'],
            'app_key' => (string) $row['app_key'],
            'label' => $app['label'] ?? (string) $row['app_key'],
            'target' => $app['target'] ?? null,
            'sector' => $app['sector'] ?? 'private',
            'state' => $state,
            'source' => (string) $row['source'],
            'started_on' => (string) $row['started_on'],
            'trial_ends_on' => $trialEnds,
            'trial_days_left' => $state === ApplicationAccess::TRIAL ? max(0, Calendar::daysBetween($today, $trialEnds) + 1) : 0,
            'paid_until' => $paidUntil,
            'due_on' => $dueOn,
            'monthly_price_xaf' => $this->priceOf($row),
            'price_overridden' => $row['monthly_price_xaf'] !== null,
            'past_due_since' => $row['past_due_since'],
            'suspended_at' => $row['suspended_at'],
            'closed_at' => $row['closed_at'],
            'state_reason' => $row['state_reason'],
            'writable' => in_array($state, [ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE, ApplicationAccess::PAST_DUE], true),
            'readable' => $state !== ApplicationAccess::CLOSED && !$locked,
            'terms' => $this->termsOf($row),
            'options' => $options,
            'options_total_xaf' => $optionsTotal,
            'monthly_total_xaf' => $this->priceOf($row) + $optionsTotal,
            'promise_until' => $row['promise_until'] ?? null,
            'regularisation' => $inRegularisation ? [
                'level' => (string) $row['regularisation'],
                'origin' => (string) ($row['regularisation_origin'] ?? Regularisation::ORIGIN_MANUAL),
                'since' => $row['regularisation_since'] ?? null,
                'days_elapsed' => isset($row['regularisation_since']) ? max(0, Calendar::daysBetween((string) $row['regularisation_since'], $today)) : 0,
            ] : null,
        ];
    }

    /** @param array<string, mixed> $app */
    private function activateModules(string $tenantId, array $app): void
    {
        foreach ($this->establishments->forTenant($tenantId) as $establishment) {
            if ($establishment->type === $app['target']) {
                $this->modules->enable($tenantId, $establishment->id, $app['required_modules']);
            }
        }
    }

    /**
     * État retrouvé à la réouverture ou au déblocage : essai ou actif si l'échéance n'est pas passée, sinon impayé.
     *
     * @param array<string, mixed> $row
     * @return array<string, scalar|null>
     */
    public function reopenedState(array $row, string $today): array
    {
        $dueOn = Subscription::dueOn($row['paid_until'] === null ? null : (string) $row['paid_until'], (string) $row['trial_ends_on']);
        if (strcmp($today, $dueOn) <= 0) {
            return ['state' => $row['paid_until'] === null ? ApplicationAccess::TRIAL : ApplicationAccess::ACTIVE, 'past_due_since' => null];
        }

        return ['state' => ApplicationAccess::PAST_DUE, 'past_due_since' => $today];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, scalar|null>
     */
    private function reactivatedState(array $row, string $today, ?string $until): array
    {
        if ($until !== null) {
            return ['state' => ApplicationAccess::ACTIVE, 'paid_until' => $until, 'past_due_since' => null];
        }

        return $this->reopenedState($row, $today);
    }
}
