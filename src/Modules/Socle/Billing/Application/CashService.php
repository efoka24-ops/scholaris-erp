<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Infrastructure\CashRepository;

/** Caisse : ouverture avec fonds, encaissements (via PaymentService), arrêté avec écart nominatif, versements. */
final class CashService
{
    public function __construct(
        private readonly CashRepository $cash,
        private readonly BillingClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function open(string $tenantId, string $establishmentId, string $cashierId, int $floatXaf, ?int $registerNo): array
    {
        if ($this->cash->openFor($tenantId, $establishmentId, $cashierId) !== null) {
            throw HttpException::conflict('Vous avez déjà une caisse ouverte : arrêtez-la avant d\'en ouvrir une autre.');
        }
        $id = (string) new Ulid();
        $this->cash->insert('cash_sessions', [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId,
            'register_no' => $registerNo ?? $this->cash->nextRegisterNumber($tenantId, $establishmentId),
            'cashier_id' => $cashierId, 'opened_at' => $this->clock->now(), 'float_xaf' => $floatXaf, 'status' => 'open',
            'closed_at' => null, 'expected_xaf' => null, 'counted_xaf' => null, 'variance_xaf' => null, 'variance_user_id' => null, 'note' => null,
        ]);
        $this->audit->append($tenantId, $cashierId, 'cash.opened', 'cash_session', $id, null, ['float_xaf' => $floatXaf]);

        return $this->detail($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function detail(string $tenantId, string $establishmentId, string $id): array
    {
        $session = $this->cash->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Caisse introuvable.');
        $totals = $this->cash->totals($tenantId, $id);
        $deposited = $this->cash->deposited($tenantId, $id);

        return $session + [
            'cash_collected_xaf' => $totals['collected'],
            'receipts_count' => $totals['receipts'],
            'expected_now_xaf' => (int) $session['float_xaf'] + $totals['collected'],
            'deposited_xaf' => $deposited,
            'deposits' => $this->cash->deposits($tenantId, $id),
        ];
    }

    /**
     * Arrêté de caisse : écart = compté - attendu (fonds + espèces encaissées), attribué nominativement au caissier.
     *
     * @return array<string, mixed>
     */
    public function close(string $tenantId, string $establishmentId, string $actorId, bool $mayCloseOthers, string $id, int $counted, ?string $note): array
    {
        $session = $this->cash->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Caisse introuvable.');
        if ($session['status'] !== 'open') {
            throw HttpException::conflict('Cette caisse est déjà arrêtée.');
        }
        if ($session['cashier_id'] !== $actorId && !$mayCloseOthers) {
            throw HttpException::forbidden('Seul le caissier peut arrêter sa caisse.');
        }

        $expected = (int) $session['float_xaf'] + $this->cash->totals($tenantId, $id)['collected'];
        $variance = $counted - $expected;
        $this->cash->update($tenantId, $id, [
            'status' => 'closed', 'closed_at' => $this->clock->now(), 'expected_xaf' => $expected, 'counted_xaf' => $counted,
            'variance_xaf' => $variance, 'variance_user_id' => $session['cashier_id'], 'note' => $note,
        ]);
        $this->audit->append($tenantId, $actorId, 'cash.closed', 'cash_session', $id, ['status' => 'open'], [
            'expected_xaf' => $expected, 'counted_xaf' => $counted, 'variance_xaf' => $variance, 'cashier_id' => $session['cashier_id'],
        ]);

        return $this->detail($tenantId, $establishmentId, $id);
    }

    /**
     * Versement en banque ou au coffre des espèces encaissées.
     *
     * @return array<string, mixed>
     */
    public function deposit(string $tenantId, string $establishmentId, string $actorId, string $id, int $amount, string $reference): array
    {
        $session = $this->detail($tenantId, $establishmentId, $id);
        $available = $session['cash_collected_xaf'] - $session['deposited_xaf'];
        if ($amount > $available) {
            throw HttpException::validation(['amount_xaf' => [sprintf('Le versement dépasse les espèces encaissées non versées (%d XAF).', $available)]]);
        }
        $depositId = (string) new Ulid();
        $this->cash->insert('cash_deposits', [
            'id' => $depositId, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'session_id' => $id,
            'amount_xaf' => $amount, 'reference' => $reference, 'deposited_by' => $actorId, 'deposited_at' => $this->clock->now(),
        ]);
        $this->audit->append($tenantId, $actorId, 'cash.deposited', 'cash_session', $id, null, ['amount_xaf' => $amount, 'reference' => $reference]);

        return $this->detail($tenantId, $establishmentId, $id);
    }
}
