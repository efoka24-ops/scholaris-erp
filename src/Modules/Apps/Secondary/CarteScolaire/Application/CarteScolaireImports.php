<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\CarteScolaire\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Domain\CarteScolaireDefaults;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Domain\IdentityKey;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Imports de fichiers extraits de cartescolaire.cm par l'établissement (RG-CSC-1 : fichiers seulement, aucun appel réseau) :
 *  - matricules (T828, EX-CSC-01, EX-CSC-05) : rapprochement par nom et prénoms (ordre indifférent, sans accents) ET date de
 *    naissance ; un seul élève : matricule enregistré ; écarts tracés (absent de l'application, identité, conflit, ambigu, absent
 *    du fichier). Les données du site font foi mais rien n'est écrasé : un matricule différent est un écart à corriger (RG-CSC-2) ;
 *  - relevé des paiements de l'économe (T829, API-03, EX-CSC-03) : rapprochement par matricule (`matched` ou `unknown`), doublon de
 *    référence ignoré (déjà importé ou répété dans le fichier).
 * Un même fichier (empreinte SHA-256) rejoué rend l'import existant sans rien réécrire (idempotence).
 */
final class CarteScolaireImports
{
    public const NATIONAL_IDS = 'national_ids';
    public const PAYMENTS = 'payments';

    public function __construct(
        private readonly Store $store,
        private readonly SecondaryClock $clock,
        private readonly CarteScolaireSettings $settings,
        private readonly NationalIdRegistry $registry,
        private readonly Roster $roster,
    ) {
    }

    /** @return array{0: array<string, mixed>, 1: bool} import, vrai s'il vient d'être créé */
    public function importNationalIds(string $tenantId, string $establishmentId, string $fileName, string $content, string $actorId): array
    {
        $hash = hash('sha256', $content);
        $existing = $this->existing($tenantId, $establishmentId, self::NATIONAL_IDS, $hash);
        if ($existing !== null) {
            return [$existing, false];
        }
        $rows = CsvTable::parse(
            $content,
            $this->settings->delimiter($tenantId, $establishmentId),
            $this->settings->columns($tenantId, $establishmentId, self::NATIONAL_IDS, CarteScolaireDefaults::COLUMNS_NATIONAL_IDS),
            ['national_id', 'last_name', 'birth_date'],
        );

        return [$this->store->transaction(function () use ($tenantId, $establishmentId, $fileName, $hash, $rows, $actorId): array {
            $importId = (string) new Ulid();
            $students = $this->roster->students($tenantId, $establishmentId);
            $byKey = [];
            $byName = [];
            foreach ($students as $student) {
                $byKey[$student['key_name'] . '|' . ($student['birth_date'] ?? '')][] = $student['person_id'];
                $byName[$student['key_name']][] = $student;
            }
            $registered = $this->registry->all($tenantId, $establishmentId);
            $seenIds = [];
            $matchedPeople = [];
            $rejected = [];
            $ok = 0;
            foreach ($rows as $row) {
                $values = $row['values'];
                $nationalId = $this->registry->normalize($tenantId, $establishmentId, $values['national_id']);
                $birth = CsvTable::date($values['birth_date']);
                $label = trim($values['last_name'] . ' ' . ($values['first_names'] ?? ''));
                if ($nationalId === null || $birth === null || IdentityKey::name($label) === '') {
                    $rejected[] = ['line' => $row['line'], 'reason' => $nationalId === null ? 'Matricule absent ou au format non reconnu.' : 'Nom ou date de naissance absent ou illisible.'];
                    continue;
                }
                if (isset($seenIds[$nationalId])) {
                    $rejected[] = ['line' => $row['line'], 'reason' => 'Matricule répété dans le fichier.'];
                    continue;
                }
                $seenIds[$nationalId] = true;
                $class = trim($values['class_label'] ?? '');
                $remote = $label . ' (' . $birth . ($class !== '' ? ', ' . $class : '') . ')';
                $candidates = $byKey[IdentityKey::name($label) . '|' . $birth] ?? [];
                if (count($candidates) > 1) {
                    $this->discrepancy($tenantId, $establishmentId, $importId, null, $nationalId, 'ambiguous', 'Plusieurs élèves ont ce nom et cette date de naissance.', $remote);
                    continue;
                }
                if ($candidates === []) {
                    $sameName = $byName[IdentityKey::name($label)] ?? [];
                    if (count($sameName) === 1) {
                        $person = $sameName[0];
                        $matchedPeople[$person['person_id']] = true;
                        $this->registry->markMismatch($tenantId, $establishmentId, $person['person_id']);
                        $this->discrepancy($tenantId, $establishmentId, $importId, $person['person_id'], $nationalId, 'identity', sprintf('Date de naissance différente : fichier %s, application %s.', $birth, $person['birth_date'] ?? 'non renseignée'), $remote);
                    } else {
                        $this->discrepancy($tenantId, $establishmentId, $importId, null, $nationalId, 'missing_local', 'Élève présent sur cartescolaire.cm, introuvable dans l\'application.', $remote);
                    }
                    continue;
                }
                $personId = $candidates[0];
                $matchedPeople[$personId] = true;
                $current = $registered[$personId]['national_id'] ?? null;
                $owner = $this->registry->personIdByNationalId($tenantId, $establishmentId, $nationalId);
                if ($owner !== null && $owner !== $personId) {
                    $this->discrepancy($tenantId, $establishmentId, $importId, $personId, $nationalId, 'conflict', 'Ce matricule est déjà attribué à un autre élève de l\'établissement.', $remote);
                    continue;
                }
                if (is_string($current) && $current !== '' && $current !== $nationalId) {
                    $this->registry->markMismatch($tenantId, $establishmentId, $personId);
                    $this->discrepancy($tenantId, $establishmentId, $importId, $personId, $nationalId, 'conflict', sprintf('Matricule différent de celui enregistré (%s) : vérifier les pièces (RG-CSC-2).', $current), $remote);
                    continue;
                }
                if ($current !== $nationalId) {
                    $this->registry->record($tenantId, $establishmentId, $personId, $nationalId, 'import', $actorId);
                }
                ++$ok;
            }
            foreach ($students as $personId => $student) {
                if (!isset($matchedPeople[$personId]) && !$this->hasOpen($tenantId, $establishmentId, $personId, 'missing_remote')) {
                    $this->discrepancy($tenantId, $establishmentId, $importId, (string) $personId, $registered[$personId]['national_id'] ?? null, 'missing_remote', 'Élève inscrit dans l\'application, absent du fichier de cartescolaire.cm.', null);
                }
            }

            return $this->save($tenantId, $establishmentId, $importId, self::NATIONAL_IDS, $fileName, $hash, count($rows), $ok, $rejected, 0, 0, $actorId);
        }), true];
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function importPayments(string $tenantId, string $establishmentId, string $fileName, string $content, string $actorId): array
    {
        $hash = hash('sha256', $content);
        $existing = $this->existing($tenantId, $establishmentId, self::PAYMENTS, $hash);
        if ($existing !== null) {
            return [$existing, false];
        }
        $rows = CsvTable::parse(
            $content,
            $this->settings->delimiter($tenantId, $establishmentId),
            $this->settings->columns($tenantId, $establishmentId, self::PAYMENTS, CarteScolaireDefaults::COLUMNS_PAYMENTS),
            ['external_ref', 'national_id', 'payment_kind', 'amount', 'paid_on'],
        );
        $kinds = $this->settings->paymentKinds($tenantId, $establishmentId);

        return [$this->store->transaction(function () use ($tenantId, $establishmentId, $fileName, $hash, $rows, $kinds, $actorId): array {
            $importId = (string) new Ulid();
            $now = $this->clock->now();
            $refs = [];
            $rejected = [];
            $ok = 0;
            $duplicates = 0;
            $unknown = 0;
            foreach ($rows as $row) {
                $values = $row['values'];
                $ref = trim($values['external_ref']);
                $nationalId = NationalIdRegistry::clean($values['national_id']);
                $kind = $kinds[IdentityKey::text($values['payment_kind'])] ?? null;
                $amount = CsvTable::amount($values['amount']);
                $paidOn = CsvTable::date($values['paid_on']);
                if ($ref === '' || mb_strlen($ref) > 80 || $nationalId === '' || $kind === null || $amount === null || $paidOn === null) {
                    $rejected[] = ['line' => $row['line'], 'reason' => $kind === null ? 'Nature de paiement inconnue (paramètre secondary.carte_scolaire.payment_kinds).' : 'Référence, matricule, montant ou date absent ou illisible.'];
                    continue;
                }
                if (isset($refs[$ref]) || $this->store->count('se_cs_payments', $tenantId, $establishmentId, ['external_ref' => $ref]) > 0) {
                    ++$duplicates;
                    continue;
                }
                $refs[$ref] = true;
                $personId = $this->registry->personIdByNationalId($tenantId, $establishmentId, $nationalId);
                $personId === null ? ++$unknown : ++$ok;
                $exam = trim($values['exam_code'] ?? '');
                $this->store->insert('se_cs_payments', $tenantId, $establishmentId, [
                    'id' => (string) new Ulid(), 'import_id' => $importId, 'national_id' => mb_substr($nationalId, 0, 40), 'payment_kind' => $kind,
                    'exam_code' => $exam === '' ? null : mb_substr(strtoupper($exam), 0, 20), 'amount' => $amount, 'paid_on' => $paidOn,
                    'external_ref' => $ref, 'matched_person_id' => $personId, 'match_status' => $personId === null ? 'unknown' : 'matched', 'created_at' => $now,
                ]);
            }

            return $this->save($tenantId, $establishmentId, $importId, self::PAYMENTS, $fileName, $hash, count($rows), $ok, $rejected, $duplicates, $unknown, $actorId);
        }), true];
    }

    /** @return array<string, mixed> */
    public function show(string $tenantId, string $establishmentId, string $id): array
    {
        return self::view($this->store->findOrFail('se_cs_imports', $tenantId, $establishmentId, $id, 'Import introuvable.'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function view(array $row): array
    {
        $rejected = json_decode((string) ($row['rejected_rows'] ?? '[]'), true);

        return [
            'id' => (string) $row['id'],
            'import_kind' => (string) $row['import_kind'],
            'file_name' => (string) $row['file_name'],
            'file_hash' => (string) $row['file_hash'],
            'rows_total' => (int) $row['rows_total'],
            'rows_ok' => (int) $row['rows_ok'],
            'rows_rejected' => (int) $row['rows_rejected'],
            'rows_duplicate' => (int) $row['rows_duplicate'],
            'rows_unknown' => (int) $row['rows_unknown'],
            'rejected_rows' => is_array($rejected) ? $rejected : [],
            'imported_by' => (string) $row['imported_by'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function existing(string $tenantId, string $establishmentId, string $kind, string $hash): ?array
    {
        $row = $this->store->select('se_cs_imports', $tenantId, $establishmentId, ['import_kind' => $kind, 'file_hash' => $hash], 'id', 1)[0] ?? null;

        return $row === null ? null : self::view($row);
    }

    /**
     * @param list<array{line: int, reason: string}> $rejected
     * @return array<string, mixed>
     */
    private function save(string $tenantId, string $establishmentId, string $id, string $kind, string $fileName, string $hash, int $total, int $ok, array $rejected, int $duplicates, int $unknown, string $actorId): array
    {
        $this->store->insert('se_cs_imports', $tenantId, $establishmentId, [
            'id' => $id, 'import_kind' => $kind, 'file_name' => mb_substr($fileName, 0, 160), 'file_hash' => $hash,
            'rows_total' => $total, 'rows_ok' => $ok, 'rows_rejected' => count($rejected), 'rows_duplicate' => $duplicates, 'rows_unknown' => $unknown,
            'rejected_rows' => json_encode(array_slice($rejected, 0, CarteScolaireDefaults::MAX_REPORTED_ROWS), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'imported_by' => $actorId, 'created_at' => $this->clock->now(),
        ]);

        return $this->show($tenantId, $establishmentId, $id);
    }

    private function hasOpen(string $tenantId, string $establishmentId, string $personId, string $kind): bool
    {
        return $this->store->count('se_cs_discrepancies', $tenantId, $establishmentId, ['person_id' => $personId, 'discrepancy_kind' => $kind, 'resolved_at' => null]) > 0;
    }

    private function discrepancy(string $tenantId, string $establishmentId, ?string $importId, ?string $personId, ?string $nationalId, string $kind, string $detail, ?string $remote): void
    {
        if ($personId !== null && $kind !== 'missing_remote' && $this->hasOpen($tenantId, $establishmentId, $personId, $kind)) {
            return;
        }
        $this->store->insert('se_cs_discrepancies', $tenantId, $establishmentId, [
            'id' => (string) new Ulid(), 'import_id' => $importId, 'person_id' => $personId, 'national_id' => $nationalId, 'discrepancy_kind' => $kind,
            'detail' => mb_substr($detail, 0, 255), 'remote_label' => $remote === null ? null : mb_substr($remote, 0, 255), 'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * Résout un écart ; `apply` reporte le matricule du site sur l'élève (les données du site font foi, RG-CSC-2).
     *
     * @return array<string, mixed>
     */
    public function resolve(string $tenantId, string $establishmentId, string $id, string $note, bool $apply, ?string $personId, string $actorId): array
    {
        $row = $this->store->findOrFail('se_cs_discrepancies', $tenantId, $establishmentId, $id, 'Écart introuvable.');
        if ($row['resolved_at'] !== null) {
            throw HttpException::conflict('Cet écart est déjà résolu.');
        }
        $target = $personId ?? (is_string($row['person_id']) ? $row['person_id'] : null);
        if ($apply) {
            if ($target === null || !is_string($row['national_id']) || $row['national_id'] === '') {
                throw HttpException::validation(['apply_national_id' => ['Cet écart ne porte pas de matricule à reporter sur un élève : indiquez person_id.']]);
            }
            if (!array_key_exists($target, $this->roster->students($tenantId, $establishmentId))) {
                throw HttpException::validation(['person_id' => ['Élève non inscrit dans cet établissement.']]);
            }
        }

        return $this->store->transaction(function () use ($tenantId, $establishmentId, $id, $row, $note, $apply, $target, $actorId): array {
            if ($apply) {
                $this->registry->record($tenantId, $establishmentId, $target, (string) $row['national_id'], 'manual', $actorId);
            }
            $this->store->update('se_cs_discrepancies', $tenantId, $establishmentId, [
                'resolved_at' => $this->clock->now(), 'resolved_by' => $actorId, 'resolution_note' => mb_substr($note, 0, 255),
                'person_id' => $target,
            ], ['id' => $id]);

            return self::discrepancyView($this->store->find('se_cs_discrepancies', $tenantId, $establishmentId, $id) ?? []);
        });
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function discrepancyView(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'import_id' => $row['import_id'] ?? null,
            'person_id' => $row['person_id'] ?? null,
            'national_id' => $row['national_id'] ?? null,
            'discrepancy_kind' => (string) ($row['discrepancy_kind'] ?? ''),
            'detail' => (string) ($row['detail'] ?? ''),
            'remote_label' => $row['remote_label'] ?? null,
            'resolved' => ($row['resolved_at'] ?? null) !== null,
            'resolved_at' => $row['resolved_at'] ?? null,
            'resolved_by' => $row['resolved_by'] ?? null,
            'resolution_note' => $row['resolution_note'] ?? null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
