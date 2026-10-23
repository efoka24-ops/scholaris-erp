<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\ReportCards\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Councils\Application\CouncilService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\ClassRoster;
use Scholaris\Modules\Apps\Primary\Support\Guardianship;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentReissuer;
use Scholaris\Modules\Socle\Documents\Contracts\ExtendedDocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssueOptions;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Bulletins du primaire (EX-PRI-26, 27, 28 ; RG-PRI-5, 6, 20) :
 *  - émission EN LOT après validation du conseil : un bulletin trimestriel par élève, plus le récapitulatif annuel au dernier
 *    trimestre (`primary.report_card.annual`) ; document du socle (numéro continu, QR, contenu figé) et copie figée `snapshot` ;
 *    idempotente (un bulletin émis n'est jamais réémis ; un échec est repris) ; blocage pour impayé seulement si
 *    `primary.report_card.block_on_debt` (défaut faux, Q17) ;
 *  - publication aux familles : visible au portail parent et SMS « bulletin disponible » (NOT-PRI-02, modèle pri.not02) ;
 *  - rectificatif : DocumentReissuer du socle (même numéro, révision + 1, ancienne version « remplacée ») et nouvelle ligne ;
 *  - aperçu avant émission (contenu calculé, non enregistré) ; portail parent : bulletins publiés de ses enfants seulement.
 */
final class ReportCardService
{
    public const TABLE = 'pri_report_cards';
    public const TERM = 'term';
    public const ANNUAL = 'annual';
    public const TYPES = [self::TERM => 'pri_report_card', self::ANNUAL => 'pri_annual_report'];
    public const READ = ['primary.report_card.read', 'primary.report_card.sign', 'primary.council.preside', 'primary.council.read'];
    public const SIGN = ['primary.report_card.sign'];
    public const TEMPLATE = 'pri.not02.report_card_ready';
    public const LINK = '/portail/famille';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ClassRoster $classes,
        private readonly SnapshotBuilder $snapshots,
        private readonly PrimarySettings $settings,
        private readonly ExtendedDocumentIssuer $issuer,
        private readonly DocumentReissuer $reissuer,
        private readonly PersonDirectory $people,
        private readonly Guardianship $family,
        private readonly Messenger $messenger,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(Principal $principal, string $establishmentId, string $councilId): array
    {
        $council = $this->council($principal, $establishmentId, $councilId, self::READ);
        $rows = $this->store->select(self::TABLE, $principal->tenantId, $establishmentId, ['council_id' => (string) $council['id']], 'person_id, card_kind, revision', 5000);
        $names = $this->classes->names($principal->tenantId, array_values(array_unique(array_map(static fn (array $r): string => (string) $r['person_id'], $rows))));
        $out = array_map(static fn (array $r): array => self::resource($r, false) + ['name' => $names[(string) $r['person_id']] ?? (string) $r['person_id']], $rows);
        usort($out, static fn (array $a, array $b): int => [(string) $a['name'], (string) $a['kind'], (int) $a['revision']] <=> [(string) $b['name'], (string) $b['kind'], (int) $b['revision']]);

        return $out;
    }

    /** @return array<string, mixed> contenu calculé du bulletin, sans émission */
    public function preview(Principal $principal, string $establishmentId, string $councilId, string $personId, string $kind): array
    {
        $council = $this->council($principal, $establishmentId, $councilId, self::READ);
        $line = $this->store->select(CouncilService::LINES, $principal->tenantId, $establishmentId, ['council_id' => $councilId, 'person_id' => $personId], 'id', 1)[0]
            ?? throw HttpException::notFound('Élève absent de ce conseil.');
        if ($kind === self::ANNUAL && (int) $council['is_final_term'] !== 1) {
            throw HttpException::validation(['kind' => ['Le récapitulatif annuel se prépare au conseil du dernier trimestre.']]);
        }
        $snapshot = $this->snapshots->build($principal->tenantId, $establishmentId, $council, $line, $kind);

        return ['snapshot' => $snapshot, 'variables' => SnapshotBuilder::variables($snapshot), 'council_status' => (string) $council['status']];
    }

    /** @return array{issued: int, failed: int, skipped: int, items: list<array<string, mixed>>} */
    public function issue(Principal $principal, string $establishmentId, string $councilId): array
    {
        $t = $principal->tenantId;
        $council = $this->council($principal, $establishmentId, $councilId, self::SIGN, true);
        if ($council['status'] !== 'validated') {
            throw HttpException::conflict('Les bulletins s\'émettent après la validation du conseil.');
        }
        $kinds = [self::TERM];
        if ((int) $council['is_final_term'] === 1 && $this->settings->bool($t, $establishmentId, 'primary.report_card.annual')) {
            $kinds[] = self::ANNUAL;
        }
        $block = $this->settings->bool($t, $establishmentId, 'primary.report_card.block_on_debt');
        $counts = ['issued' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($this->store->select(CouncilService::LINES, $t, $establishmentId, ['council_id' => $councilId], 'id', 5000) as $line) {
            foreach ($kinds as $kind) {
                $existing = $this->store->select(self::TABLE, $t, $establishmentId, ['council_id' => $councilId, 'person_id' => (string) $line['person_id'], 'card_kind' => $kind], 'revision DESC', 1)[0] ?? null;
                if ($existing !== null && $existing['status'] !== 'failed') {
                    ++$counts['skipped'];
                    continue;
                }
                $counts[$this->issueOne($principal, $establishmentId, $council, $line, $kind, $existing, $block)]++;
            }
        }
        $this->access->audit($principal, 'primary.report_cards.issued', CouncilService::COUNCILS, $councilId, null, $counts);

        return $counts + ['items' => $this->list($principal, $establishmentId, $councilId)];
    }

    /** @return array{published: int, notified: int, items: list<array<string, mixed>>} */
    public function publish(Principal $principal, string $establishmentId, string $councilId): array
    {
        $t = $principal->tenantId;
        $council = $this->council($principal, $establishmentId, $councilId, self::SIGN, true);
        $rows = $this->store->select(self::TABLE, $t, $establishmentId, ['council_id' => $councilId, 'status' => 'issued'], 'person_id', 5000);
        if ($rows === []) {
            throw HttpException::conflict('Aucun bulletin émis à publier pour ce conseil.');
        }
        $now = $this->clock->now();
        $notified = 0;
        $byPerson = [];
        foreach ($rows as $row) {
            $this->store->update(self::TABLE, $t, $establishmentId, ['status' => 'published', 'published_by' => $principal->userId, 'published_at' => $now, 'updated_at' => $now], ['id' => (string) $row['id']]);
            $byPerson[(string) $row['person_id']][] = (string) $row['id'];
        }
        foreach ($byPerson as $personId => $ids) {
            $count = $this->notify($t, $establishmentId, $personId, (string) $council['period_label']);
            $notified += $count;
            $this->store->update(self::TABLE, $t, $establishmentId, ['notified_count' => $count], ['id' => $ids[0]]);
        }
        $this->access->audit($principal, 'primary.report_cards.published', CouncilService::COUNCILS, $councilId, null, ['published' => count($rows), 'notified' => $notified]);

        return ['published' => count($rows), 'notified' => $notified, 'items' => $this->list($principal, $establishmentId, $councilId)];
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId, string $cardId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $cardId, 'Bulletin introuvable.');
        $this->access->assertReadableClass($perimeter, (string) $row['class_structure_id']);

        return self::resource($row, true);
    }

    /**
     * Rectificatif d'un bulletin émis ou publié : la ligne du conseil est corrigée (appréciation, conduite, décision), le document est
     * réédité par le socle et l'ancienne version passe à « remplacée ».
     *
     * @param array<string, mixed> $changes appraisal, conduct_code, decision, remediation_note
     * @return array<string, mixed>
     */
    public function rectify(Principal $principal, string $establishmentId, string $cardId, string $reason, array $changes): array
    {
        $t = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, self::SIGN);
        $old = $this->store->findOrFail(self::TABLE, $t, $establishmentId, $cardId, 'Bulletin introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $old['class_structure_id'], self::TABLE, $cardId);
        if (!in_array($old['status'], ['issued', 'published'], true) || $old['document_id'] === null) {
            throw HttpException::conflict('Seul un bulletin émis ou publié peut être rectifié.');
        }
        $council = $this->store->findOrFail(CouncilService::COUNCILS, $t, $establishmentId, (string) $old['council_id']);
        if ($changes !== []) {
            $set = $changes + ['updated_by' => $principal->userId, 'updated_at' => $this->clock->now()];
            $this->store->update(CouncilService::LINES, $t, $establishmentId, $set, ['id' => (string) $old['line_id']]);
        }
        $line = $this->store->findOrFail(CouncilService::LINES, $t, $establishmentId, (string) $old['line_id']);
        $snapshot = $this->snapshots->build($t, $establishmentId, $council, $line, (string) $old['card_kind']);
        $document = $this->reissuer->reissue($t, $establishmentId, (string) $old['document_id'], SnapshotBuilder::variables($snapshot), $reason, $principal->userId);
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->transaction(function () use ($t, $establishmentId, $old, $id, $document, $snapshot, $principal, $now, $cardId): void {
            $this->store->insert(self::TABLE, $t, $establishmentId, [
                'id' => $id, 'council_id' => (string) $old['council_id'], 'line_id' => (string) $old['line_id'], 'person_id' => (string) $old['person_id'],
                'class_structure_id' => (string) $old['class_structure_id'], 'academic_year_id' => (string) $old['academic_year_id'], 'period_id' => (string) $old['period_id'],
                'card_kind' => (string) $old['card_kind'], 'document_type' => (string) $old['document_type'], 'locale' => (string) $old['locale'],
                'revision' => (int) $old['revision'] + 1, 'status' => (string) $old['status'], 'document_id' => $document->id, 'document_number' => $document->number,
                'verification_token' => $document->verificationToken, 'snapshot' => json_encode($snapshot + ['number' => $document->number, 'verification_token' => $document->verificationToken, 'rectified' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'error_message' => null, 'issued_by' => $principal->userId, 'issued_at' => $now, 'published_by' => $old['published_by'], 'published_at' => $old['published_at'],
                'notified_count' => 0, 'superseded_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->store->update(self::TABLE, $t, $establishmentId, ['status' => 'superseded', 'superseded_by' => $id, 'updated_at' => $now], ['id' => $cardId]);
        });
        $this->access->audit($principal, 'primary.report_card.rectified', self::TABLE, $id, ['id' => $cardId], ['revision' => (int) $old['revision'] + 1, 'reason' => $reason]);

        return self::resource($this->store->findOrFail(self::TABLE, $t, $establishmentId, $id), true);
    }

    /** @return list<array<string, mixed>> bulletins publiés (version en vigueur) d'un enfant du parent connecté */
    public function forFamily(Principal $principal, string $establishmentId, string $childId): array
    {
        $this->family->assertChild($principal, $establishmentId, $childId);

        return array_map(
            static fn (array $r): array => self::resource($r, true),
            $this->store->select(self::TABLE, $principal->tenantId, $establishmentId, ['person_id' => $childId, 'status' => 'published'], 'published_at DESC, id', 100),
        );
    }

    /**
     * @param array<string, mixed> $council
     * @param array<string, mixed> $line
     * @param array<string, mixed>|null $existing tentative en échec à reprendre
     * @return 'issued'|'failed'
     */
    private function issueOne(Principal $principal, string $establishmentId, array $council, array $line, string $kind, ?array $existing, bool $block): string
    {
        $t = $principal->tenantId;
        $now = $this->clock->now();
        $snapshot = $this->snapshots->build($t, $establishmentId, $council, $line, $kind);
        $id = $existing === null ? (string) new Ulid() : (string) $existing['id'];
        $base = [
            'council_id' => (string) $council['id'], 'line_id' => (string) $line['id'], 'person_id' => (string) $line['person_id'],
            'class_structure_id' => (string) $council['class_structure_id'], 'academic_year_id' => (string) $council['academic_year_id'],
            'period_id' => (string) $council['period_id'], 'card_kind' => $kind, 'document_type' => self::TYPES[$kind], 'locale' => (string) $snapshot['locale'],
        ];
        try {
            $document = $this->issuer->issueWithOptions($t, $establishmentId, self::TYPES[$kind], (string) $line['person_id'], SnapshotBuilder::variables($snapshot), $principal->userId, (string) $snapshot['locale'], new IssueOptions(enforceFinancialBlock: $block));
            $values = $base + [
                'status' => 'issued', 'document_id' => $document->id, 'document_number' => $document->number, 'verification_token' => $document->verificationToken,
                'snapshot' => json_encode($snapshot + ['number' => $document->number, 'verification_token' => $document->verificationToken], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'error_message' => null, 'issued_by' => $principal->userId, 'issued_at' => $now, 'updated_at' => $now,
            ];
            $outcome = 'issued';
        } catch (HttpException $e) {
            $details = [];
            foreach ($e->errors as $messages) {
                array_push($details, ...$messages);
            }
            $values = $base + ['status' => 'failed', 'error_message' => mb_substr(trim($e->getMessage() . ' ' . implode(' ', $details)), 0, 500), 'updated_at' => $now];
            $outcome = 'failed';
        }
        if ($existing === null) {
            $this->store->insert(self::TABLE, $t, $establishmentId, $values + ['id' => $id, 'revision' => 1, 'notified_count' => 0, 'created_at' => $now]);
        } else {
            $this->store->update(self::TABLE, $t, $establishmentId, $values, ['id' => $id]);
        }

        return $outcome;
    }

    private function notify(string $tenantId, string $establishmentId, string $personId, string $periodLabel): int
    {
        $child = $this->people->find($tenantId, $personId);
        $count = 0;
        foreach ($this->people->guarantorsOf($tenantId, $personId) as $guardian) {
            $result = $this->messenger->send($tenantId, $establishmentId, $guardian->id, self::TEMPLATE, [
                'child_name' => $child === null ? '' : $child->fullName(), 'period_label' => $periodLabel, 'link' => self::LINK,
            ]);
            $count += $result->status === DeliveryResult::BLOCKED ? 0 : 1;
        }

        return $count;
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    private function council(Principal $principal, string $establishmentId, string $councilId, array $permissions, bool $write = false): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $council = $this->store->findOrFail(CouncilService::COUNCILS, $principal->tenantId, $establishmentId, $councilId, 'Conseil introuvable.');
        $classId = (string) $council['class_structure_id'];
        if ($write) {
            if (!$perimeter->coversClass($classId)) {
                $this->access->assertReadableClass($this->access->perimeter($principal, $establishmentId, self::READ), $classId);
            }
            $this->access->assertWritableClass($principal, $perimeter, $classId, CouncilService::COUNCILS, $councilId);
        } else {
            $this->access->assertReadableClass($perimeter, $classId);
        }

        return $council;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row, bool $withSnapshot): array
    {
        $out = [
            'id' => (string) $row['id'], 'council_id' => (string) $row['council_id'], 'person_id' => (string) $row['person_id'],
            'class_structure_id' => (string) $row['class_structure_id'], 'period_id' => (string) $row['period_id'], 'kind' => (string) $row['card_kind'],
            'locale' => (string) $row['locale'], 'revision' => (int) $row['revision'], 'status' => (string) $row['status'],
            'document_id' => $row['document_id'] === null ? null : (string) $row['document_id'], 'number' => $row['document_number'] === null ? null : (string) $row['document_number'],
            'verification_token' => $row['verification_token'] === null ? null : (string) $row['verification_token'],
            'error_message' => $row['error_message'] === null ? null : (string) $row['error_message'],
            'issued_at' => $row['issued_at'] === null ? null : (string) $row['issued_at'], 'published_at' => $row['published_at'] === null ? null : (string) $row['published_at'],
            'superseded_by' => $row['superseded_by'] === null ? null : (string) $row['superseded_by'],
        ];
        if ($withSnapshot) {
            $snapshot = json_decode((string) ($row['snapshot'] ?? 'null'), true);
            $out['snapshot'] = is_array($snapshot) ? $snapshot : null;
        }

        return $out;
    }
}
