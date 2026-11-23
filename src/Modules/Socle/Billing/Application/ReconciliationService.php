<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Domain\PaymentChannel;
use Scholaris\Modules\Socle\Billing\Infrastructure\Db;
use Scholaris\Modules\Socle\Billing\Infrastructure\PaymentRepository;
use Scholaris\Modules\Socle\Billing\Infrastructure\ReconciliationRepository;

/**
 * Rapprochement des encaissements mobile money avec le relevé de l'opérateur (CSV : date, reference, amount, phone,
 * family_reference). Une ligne est « matched » (paiement retrouvé et conforme), « created » (paiement manquant créé
 * grâce à la référence famille), « mismatch » (montant différent) ou « unreferenced » (Sans référence, à traiter à la main).
 */
final class ReconciliationService
{
    private const REQUIRED = ['date', 'reference', 'amount'];
    private const MAX_LINES = 5000;

    public function __construct(
        private readonly Db $db,
        private readonly ReconciliationRepository $repository,
        private readonly PaymentRepository $payments,
        private readonly PaymentService $paymentService,
        private readonly AllocationService $allocation,
        private readonly BillingClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function import(string $tenantId, string $establishmentId, string $actorId, string $channel, string $source, string $csv): array
    {
        $channelEnum = PaymentChannel::tryFrom($channel);
        if ($channelEnum === null || !$channelEnum->isMobile()) {
            throw HttpException::validation(['channel' => ['Le rapprochement concerne orange_money ou mtn_momo.']]);
        }
        $lines = $this->parse($csv);

        $reconciliationId = (string) new Ulid();
        $this->db->transaction(function () use ($tenantId, $establishmentId, $actorId, $channelEnum, $source, $lines, $reconciliationId): void {
            $this->repository->insert('reconciliations', [
                'id' => $reconciliationId, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'channel' => $channelEnum->value,
                'source' => $source, 'imported_by' => $actorId, 'imported_at' => $this->clock->now(), 'lines_total' => count($lines), 'matched' => 0, 'unmatched' => 0,
            ]);
            $matched = 0;
            foreach ($lines as $line) {
                [$status, $paymentId] = $this->match($tenantId, $establishmentId, $actorId, $channelEnum, $line);
                $matched += in_array($status, ['matched', 'created'], true) ? 1 : 0;
                $this->repository->insert('reconciliation_lines', [
                    'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'reconciliation_id' => $reconciliationId,
                    'line_date' => $line['date'], 'operator_reference' => $line['reference'], 'amount_xaf' => $line['amount'], 'phone' => $line['phone'],
                    'family_reference' => $line['family_reference'], 'status' => $status, 'payment_id' => $paymentId,
                ]);
            }
            $this->repository->updateCounts($tenantId, $reconciliationId, $matched, count($lines) - $matched);
            $this->audit->append($tenantId, $actorId, 'reconciliation.imported', 'reconciliation', $reconciliationId, null, [
                'channel' => $channelEnum->value, 'lines' => count($lines), 'matched' => $matched,
            ]);
        });

        return $this->detail($tenantId, $establishmentId, $reconciliationId);
    }

    /** @return array<string, mixed> */
    public function detail(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->repository->find($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Rapprochement introuvable.');

        return $row + ['lines' => $this->repository->lines($tenantId, $id)];
    }

    /**
     * @param array{date: string, reference: string, amount: int, phone: string|null, family_reference: string|null} $line
     * @return array{0: string, 1: string|null}
     */
    private function match(string $tenantId, string $establishmentId, string $actorId, PaymentChannel $channel, array $line): array
    {
        $payment = $this->payments->paymentByExternal($tenantId, $line['reference']);
        if ($payment !== null && $payment['establishment_id'] === $establishmentId && $payment['channel'] === $channel->value && $payment['status'] === 'confirmed') {
            if ((int) $payment['amount_xaf'] !== $line['amount']) {
                return ['mismatch', (string) $payment['id']];
            }
            $set = ['reconciled_at' => $payment['reconciled_at'] ?? $this->clock->now()];
            $reference = $line['family_reference'] === null ? null : $this->payments->referenceForTenant($tenantId, $line['family_reference']);
            if ($payment['payer_reference_id'] === null && $reference !== null) {
                $set['payer_reference_id'] = (string) $reference['id'];
            }
            $this->payments->updatePayment($tenantId, (string) $payment['id'], $set);
            if (isset($set['payer_reference_id'])) {
                $this->allocation->applyCreditForReference($tenantId, $set['payer_reference_id']);
            }

            return ['matched', (string) $payment['id']];
        }
        if ($payment !== null) {
            return ['mismatch', (string) $payment['id']];
        }

        if ($line['family_reference'] !== null && $this->payments->referenceForTenant($tenantId, $line['family_reference']) !== null) {
            $result = $this->paymentService->record(new RecordPayment(
                $tenantId,
                $establishmentId,
                $actorId,
                $channel,
                $line['amount'],
                'recon:' . $channel->value . ':' . $line['reference'],
                $line['family_reference'],
                externalReference: $line['reference'],
                payerPhone: $line['phone'],
            ));

            return ['created', (string) $result->payment['id']];
        }

        return ['unreferenced', null];
    }

    /** @return list<array{date: string, reference: string, amount: int, phone: string|null, family_reference: string|null}> */
    private function parse(string $csv): array
    {
        $rows = array_values(array_filter(array_map('trim', preg_split('/\R/', $csv) ?: []), static fn (string $l): bool => $l !== ''));
        if ($rows === []) {
            throw HttpException::validation(['csv' => ['Le relevé est vide.']]);
        }
        if (count($rows) > self::MAX_LINES + 1) {
            throw HttpException::validation(['csv' => ['Le relevé dépasse ' . self::MAX_LINES . ' lignes : découpez-le.']]);
        }
        $header = array_map(static fn (?string $h): string => strtolower(trim((string) $h)), str_getcsv(array_shift($rows), ',', '"', ''));
        $missing = array_diff(self::REQUIRED, $header);
        if ($missing !== []) {
            throw HttpException::validation(['csv' => ['Colonnes obligatoires absentes : ' . implode(', ', $missing) . '.']]);
        }

        $out = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $cells = str_getcsv($row, ',', '"', '');
            $data = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
            $number = $index + 2;
            $amount = (string) ($data['amount'] ?? '');
            $reference = trim((string) ($data['reference'] ?? ''));
            if (preg_match('/^\d{1,12}$/', $amount) !== 1 || (int) $amount <= 0) {
                $errors['csv'][] = sprintf('Ligne %d : montant entier positif en XAF attendu.', $number);
            } elseif ($reference === '' || strlen($reference) > 80 || trim((string) ($data['date'] ?? '')) === '') {
                $errors['csv'][] = sprintf('Ligne %d : date et référence obligatoires.', $number);
            } elseif (isset($seen[$reference])) {
                $errors['csv'][] = sprintf('Ligne %d : référence %s en double dans le relevé.', $number, $reference);
            } else {
                $seen[$reference] = true;
                $family = trim((string) ($data['family_reference'] ?? ''));
                $phone = trim((string) ($data['phone'] ?? ''));
                $out[] = [
                    'date' => substr(trim((string) $data['date']), 0, 32), 'reference' => $reference, 'amount' => (int) $amount,
                    'phone' => $phone === '' ? null : substr($phone, 0, 30), 'family_reference' => $family === '' ? null : substr($family, 0, 30),
                ];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $out;
    }
}
