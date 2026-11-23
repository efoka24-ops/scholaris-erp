<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Apee\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicSchools\Apee\Infrastructure\PdoApee;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;

/**
 * Paquet minimum de l'État et dons en nature (EX-APE-05, T655) : bordereau, quantités, répartition par classe, reçus par le directeur ;
 * bordereau de réception DOC-27 (signé du directeur et du livreur, sans QR).
 */
final class SupplyService
{
    public const SOURCES = ['minimum_package', 'donation'];
    public const SLIP_TYPE = 'pub_doc27_supply_slip';

    public function __construct(
        private readonly PdoApee $repository,
        private readonly ClassRoster $classes,
        private readonly DocumentIssuer $documents,
        private readonly UserPersonLink $users,
        private readonly ApeeClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input source, slip_ref?, received_on, deliverer_name?, lines [{item_label, quantity, structure_id?}]
     * @return array<string, mixed>
     */
    public function receive(Principal $principal, string $establishmentId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $errors = [];
        $source = $input['source'] ?? null;
        if (!in_array($source, self::SOURCES, true)) {
            $errors['source'][] = 'Origine attendue : ' . implode(', ', self::SOURCES) . '.';
        }
        $slip = ApeeService::text($input['slip_ref'] ?? null, 60, 'slip_ref', $errors);
        $receivedOn = $input['received_on'] ?? null;
        if (!ApeeService::isDate($receivedOn) || $receivedOn > $this->clock->today()) {
            $errors['received_on'][] = 'Date de réception AAAA-MM-JJ, passée ou du jour.';
        }
        $deliverer = ApeeService::text($input['deliverer_name'] ?? null, 120, 'deliverer_name', $errors);
        $lines = $input['lines'] ?? null;
        $valid = [];
        if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > 200) {
            $errors['lines'][] = 'Liste des articles reçus (1 à 200) attendue.';
        } else {
            foreach ($lines as $index => $line) {
                $label = is_array($line) ? ApeeService::text($line['item_label'] ?? null, 120, 'lines.' . $index . '.item_label', $errors) : null;
                $quantity = is_array($line) ? ($line['quantity'] ?? null) : null;
                $structure = is_array($line) ? ($line['structure_id'] ?? null) : null;
                if ($label === null || !is_int($quantity) || $quantity < 1) {
                    $errors['lines.' . $index][] = 'Article et quantité entière positive requis.';
                    continue;
                }
                if ($structure !== null && (!is_string($structure) || $this->classes->find($tenantId, $establishmentId, $structure) === null)) {
                    $errors['lines.' . $index . '.structure_id'][] = 'Classe de l\'école attendue.';
                    continue;
                }
                $valid[] = ['item_label' => $label, 'quantity' => $quantity, 'structure_id' => $structure];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = PdoApee::newId();
        $pdo = $this->repository->pdo();
        $pdo->beginTransaction();
        try {
            $this->repository->insert('pub_supply_receipts', [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'source' => (string) $source, 'slip_ref' => $slip,
                'received_on' => (string) $receivedOn, 'deliverer_name' => $deliverer, 'recorded_by' => $principal->userId, 'created_at' => $this->clock->now(),
            ]);
            foreach ($valid as $line) {
                $this->repository->insert('pub_supply_lines', ['id' => PdoApee::newId(), 'tenant_id' => $tenantId, 'receipt_id' => $id] + $line);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        $this->audit->append($tenantId, $principal->userId, 'public_schools.supplies.received', 'pub_supply_receipt', $id, null, ['source' => $source, 'lines' => count($valid)]);

        return $this->show($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function show(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->repository->supply($tenantId, $establishmentId, $id) ?? throw HttpException::notFound();

        return self::resource($row) + ['lines' => array_map(static fn (array $l): array => ['item_label' => (string) $l['item_label'], 'quantity' => (int) $l['quantity'], 'structure_id' => $l['structure_id']], $this->repository->supplyLines($tenantId, $id))];
    }

    /**
     * DOC-27 : bordereau de réception.
     *
     * @return array<string, mixed>
     */
    public function issueSlip(Principal $principal, string $establishmentId, string $id, string $locale): array
    {
        $tenantId = $principal->tenantId;
        $receipt = $this->show($tenantId, $establishmentId, $id);
        $en = $locale === 'en';
        $labels = [];
        foreach ($receipt['lines'] as $line) {
            $class = $line['structure_id'] === null ? null : $this->classes->find($tenantId, $establishmentId, (string) $line['structure_id']);
            $labels[] = sprintf('%-40s %8d  %s', $line['item_label'], $line['quantity'], $class === null ? '' : $class->label);
        }
        $subject = $this->users->personIdForUser($tenantId, $principal->userId) ?? throw HttpException::validation(['user' => ['Compte sans personne liée : émission impossible.']]);
        $document = $this->documents->issue($tenantId, $establishmentId, self::SLIP_TYPE, $subject, [
            'source_label' => $receipt['source'] === 'minimum_package' ? ($en ? 'State minimum package' : 'Paquet minimum') : ($en ? 'Donation in kind' : 'Don en nature'),
            'slip_ref' => (string) ($receipt['slip_ref'] ?? ''), 'received_on' => (string) $receipt['received_on'], 'deliverer_name' => (string) ($receipt['deliverer_name'] ?? ''),
            'lines_block' => implode("\n", $labels),
        ], $principal->userId, $en ? 'en' : 'fr');
        $this->repository->setSupplyDocument($tenantId, $id, $document->id);

        return ['document' => ['id' => $document->id, 'number' => $document->number]];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'source' => (string) $row['source'], 'slip_ref' => $row['slip_ref'], 'received_on' => (string) $row['received_on'],
            'deliverer_name' => $row['deliverer_name'], 'document_id' => $row['document_id'],
        ];
    }
}
