<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Fees\Domain\CatalogRule;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Catalogue des frais autorisés d'un établissement public [fees_public_catalog] et demandes d'ajout (RG-FIN-2, EX-FIN-03, TST-06).
 * Le catalogue est vide à l'ouverture (Q4) : il est tenu par le client ; une ligne `establishment_id = '*'` vaut pour tous ses
 * établissements. Une demande est décidée par une autre personne que son auteur ; l'acceptation crée la ligne du catalogue.
 */
final class FeeCatalog
{
    public const CLIENT_WIDE = '*';
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REFUSED = 'refused';

    public function __construct(private readonly Store $store, private readonly SecondaryClock $clock)
    {
    }

    /** @return list<array<string, mixed>> lignes de l'établissement puis du client, en vigueur ou non */
    public function entries(string $tenantId, string $establishmentId): array
    {
        $rows = [
            ...$this->store->select('se_fee_catalog', $tenantId, $establishmentId, [], 'fee_kind, label'),
            ...$this->store->select('se_fee_catalog', $tenantId, self::CLIENT_WIDE, [], 'fee_kind, label'),
        ];

        return array_map(static fn (array $row): array => self::entry($row), $rows);
    }

    /** @return array<string, mixed>|null ligne en vigueur qui autorise ce frais */
    public function authorizing(string $tenantId, string $establishmentId, string $kind, ?string $code, int $amount): ?array
    {
        return CatalogRule::match($this->entries($tenantId, $establishmentId), $kind, $code, $amount, $this->clock->today());
    }

    /**
     * @param array<string, mixed> $values fee_kind, fee_code, label, max_amount, cycle_code, legal_basis, valid_from, valid_to, client_wide
     * @return array<string, mixed>
     */
    public function add(string $tenantId, string $establishmentId, array $values, string $actorId): array
    {
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $scope = ($values['client_wide'] ?? false) === true ? self::CLIENT_WIDE : $establishmentId;
        $this->store->insert('se_fee_catalog', $tenantId, $scope, [
            'id' => $id,
            'fee_kind' => (string) $values['fee_kind'],
            'fee_code' => self::nullableString($values['fee_code'] ?? null),
            'label' => (string) $values['label'],
            'max_amount' => isset($values['max_amount']) ? (int) $values['max_amount'] : null,
            'cycle_code' => self::nullableString($values['cycle_code'] ?? null),
            'legal_basis' => self::nullableString($values['legal_basis'] ?? null),
            'valid_from' => (string) ($values['valid_from'] ?? $this->clock->today()),
            'valid_to' => self::nullableString($values['valid_to'] ?? null),
            'created_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::entry($this->store->find('se_fee_catalog', $tenantId, $scope, $id) ?? []);
    }

    /**
     * Retire une ligne à une date (rien n'est supprimé : la ligne reste lisible, hors vigueur après cette date).
     *
     * @return array<string, mixed>
     */
    public function retire(string $tenantId, string $establishmentId, string $id, string $validTo): array
    {
        foreach ([$establishmentId, self::CLIENT_WIDE] as $scope) {
            $row = $this->store->find('se_fee_catalog', $tenantId, $scope, $id);
            if ($row === null) {
                continue;
            }
            if ($validTo < (string) $row['valid_from']) {
                throw HttpException::validation(['valid_to' => ['La fin de validité ne peut précéder le début.']]);
            }
            $this->store->update('se_fee_catalog', $tenantId, $scope, ['valid_to' => $validTo, 'updated_at' => $this->clock->now()], ['id' => $id]);

            return self::entry($this->store->find('se_fee_catalog', $tenantId, $scope, $id) ?? []);
        }

        throw HttpException::notFound('Ligne du catalogue introuvable.');
    }

    /** @return list<array<string, mixed>> */
    public function requests(string $tenantId, string $establishmentId, ?string $status): array
    {
        $where = $status === null ? [] : ['status' => $status];

        return array_map(
            static fn (array $row): array => self::requestView($row),
            $this->store->select('se_fee_requests', $tenantId, $establishmentId, $where, 'created_at DESC', 200),
        );
    }

    /**
     * Demande d'ajout. Une demande en attente identique (nature, code, libellé) n'est pas dupliquée : elle est rendue telle quelle.
     *
     * @return array{0: array<string, mixed>, 1: bool} demande, vrai si elle vient d'être créée
     */
    public function request(string $tenantId, string $establishmentId, string $kind, ?string $code, string $label, int $amount, ?string $justification, string $origin, string $actorId): array
    {
        foreach ($this->store->select('se_fee_requests', $tenantId, $establishmentId, ['fee_kind' => $kind, 'status' => self::PENDING]) as $row) {
            if (mb_strtolower((string) $row['label']) === mb_strtolower($label) && ($row['fee_code'] ?? null) === $code) {
                return [self::requestView($row), false];
            }
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert('se_fee_requests', $tenantId, $establishmentId, [
            'id' => $id, 'fee_kind' => $kind, 'fee_code' => $code, 'label' => mb_substr($label, 0, 160), 'amount' => $amount,
            'justification' => $justification === null ? null : mb_substr($justification, 0, 500), 'origin' => $origin,
            'requested_by' => $actorId, 'status' => self::PENDING, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [self::requestView($this->store->find('se_fee_requests', $tenantId, $establishmentId, $id) ?? []), true];
    }

    /** @return array<string, mixed> demande décidée (et ligne du catalogue créée si elle est acceptée) */
    public function decide(string $tenantId, string $establishmentId, string $id, bool $accept, string $reason, ?int $maxAmount, ?string $legalBasis, string $actorId): array
    {
        $row = $this->store->findOrFail('se_fee_requests', $tenantId, $establishmentId, $id, 'Demande introuvable.');
        if ($row['status'] !== self::PENDING) {
            throw HttpException::conflict('Cette demande est déjà décidée.');
        }
        if ($row['requested_by'] === $actorId) {
            throw HttpException::forbidden('L\'auteur d\'une demande ne peut pas la décider (séparation des tâches).');
        }

        return $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $row, $accept, $reason, $maxAmount, $legalBasis, $actorId): array {
            $catalogId = null;
            if ($accept) {
                $catalogId = (string) $this->add($tenantId, $establishmentId, [
                    'fee_kind' => $row['fee_kind'], 'fee_code' => $row['fee_code'], 'label' => $row['label'],
                    'max_amount' => $maxAmount ?? (int) $row['amount'], 'legal_basis' => $legalBasis,
                ], $actorId)['id'];
            }
            $now = $this->clock->now();
            $this->store->update('se_fee_requests', $tenantId, $establishmentId, [
                'status' => $accept ? self::ACCEPTED : self::REFUSED, 'decided_by' => $actorId, 'decided_at' => $now,
                'decision_reason' => $reason, 'catalog_id' => $catalogId, 'updated_at' => $now,
            ], ['id' => $id]);

            return self::requestView($this->store->find('se_fee_requests', $tenantId, $establishmentId, $id) ?? []);
        });
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function entry(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'client_wide' => ($row['establishment_id'] ?? '') === self::CLIENT_WIDE,
            'fee_kind' => (string) ($row['fee_kind'] ?? ''),
            'fee_code' => $row['fee_code'] ?? null,
            'label' => (string) ($row['label'] ?? ''),
            'max_amount' => isset($row['max_amount']) ? (int) $row['max_amount'] : null,
            'cycle_code' => $row['cycle_code'] ?? null,
            'legal_basis' => $row['legal_basis'] ?? null,
            'valid_from' => (string) ($row['valid_from'] ?? ''),
            'valid_to' => $row['valid_to'] ?? null,
            'created_by' => (string) ($row['created_by'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function requestView(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'fee_kind' => (string) ($row['fee_kind'] ?? ''),
            'fee_code' => $row['fee_code'] ?? null,
            'label' => (string) ($row['label'] ?? ''),
            'amount' => (int) ($row['amount'] ?? 0),
            'justification' => $row['justification'] ?? null,
            'origin' => (string) ($row['origin'] ?? ''),
            'requested_by' => (string) ($row['requested_by'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'decided_by' => $row['decided_by'] ?? null,
            'decided_at' => $row['decided_at'] ?? null,
            'decision_reason' => $row['decision_reason'] ?? null,
            'catalog_id' => $row['catalog_id'] ?? null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
