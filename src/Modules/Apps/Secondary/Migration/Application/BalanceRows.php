<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Migration\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Apps\Secondary\Migration\Domain\MigrationDefaults;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeLine;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;

/**
 * Jeu « soldes de frais d'ouverture » : reste dû repris de l'ancien logiciel, une ligne par élève, frais et échéance. L'élève est
 * retrouvé par son matricule ou par l'identifiant de l'ancien logiciel d'une reprise d'élèves déjà appliquée, et doit être inscrit
 * dans l'année. Chaque ligne devient une facture libre du socle (InvoiceIssuer::forCharges) sur un frais EXISTANT du catalogue de
 * l'établissement (code stable) : au public, la garde du catalogue autorisé s'applique donc déjà au frais. La référence de la
 * facture est dérivée de la clé de reprise : rejouer ne facture jamais deux fois.
 *
 * Jeu atomique : tout le lot ou rien ; la simulation rejoue la facturation dans une transaction annulée (frais inconnu, montant).
 *
 * @phpstan-import-type Line from KindRows
 */
final class BalanceRows implements KindRows
{
    public const PERMISSIONS = ['secondary.billing.manage'];
    private const DEFAULT_LABEL = 'Solde d\'ouverture (reprise)';

    public function __construct(
        private readonly Store $store,
        private readonly NationalIds $nationalIds,
        private readonly InvoiceIssuer $invoices,
    ) {
    }

    public function permissions(): array
    {
        return self::PERMISSIONS;
    }

    public function atomic(): bool
    {
        return true;
    }

    public function plan(Principal $principal, string $establishmentId, string $academicYearId, ?string $entryDate, array $rows): array
    {
        $tenantId = $principal->tenantId;
        $lines = [];
        foreach ($rows as $number => $row) {
            $messages = [];
            if (Cells::isExample($row['id_ancien'], $row['code_frais'])) {
                $messages[] = 'Ligne d\'exemple du modèle : supprimez-la.';
            }
            $personId = null;
            if ($row['matricule'] !== '') {
                $nationalId = $this->nationalIds->normalize($tenantId, $establishmentId, $row['matricule']);
                $personId = $nationalId === null ? null : $this->nationalIds->personIdByNationalId($tenantId, $establishmentId, $nationalId);
                if ($personId === null) {
                    $messages[] = 'Aucun élève de l\'établissement n\'a le matricule « ' . $row['matricule'] . ' ».';
                }
            } elseif ($row['id_ancien'] !== '') {
                $item = $this->store->select('se_migration_items', $tenantId, $establishmentId, [
                    'import_kind' => MigrationDefaults::STUDENTS, 'key_ref' => 'A:' . Cells::fold($row['id_ancien']), 'live' => 1,
                ], 'line_no', 1)[0] ?? null;
                $personId = $item === null ? null : (string) $item['object_ref'];
                if ($personId === null) {
                    $messages[] = 'Identifiant « ' . $row['id_ancien'] . ' » inconnu : reprenez d\'abord les élèves (colonne id_ancien).';
                }
            } else {
                $messages[] = 'Matricule ou identifiant de l\'ancien logiciel (id_ancien) obligatoire.';
            }
            $structureId = null;
            if ($personId !== null) {
                $record = $this->store->select('se_enrolment_records', $tenantId, $establishmentId, ['person_id' => $personId, 'academic_year_id' => $academicYearId], 'created_at DESC', 1)[0] ?? null;
                $structureId = $record === null ? null : (string) $record['class_structure_id'];
                if ($structureId === null) {
                    $messages[] = 'Élève non inscrit pour cette année : inscrivez-le avant de reprendre son solde.';
                }
            }
            if ($row['code_frais'] === '') {
                $messages[] = 'Code du frais obligatoire (frais du catalogue de l\'établissement).';
            }
            $amount = Cells::amount($row['montant']);
            if ($amount === null || $amount < 1 || $amount > MigrationDefaults::MAX_AMOUNT_XAF) {
                $messages[] = 'Montant : entier positif en FCFA attendu.';
            }
            $due = Cells::date($row['echeance']);
            if ($due === null) {
                $messages[] = 'Échéance illisible (AAAA-MM-JJ ou JJ/MM/AAAA).';
            }
            $label = $row['libelle'] === '' ? self::DEFAULT_LABEL : $row['libelle'];
            if (mb_strlen($label) > 160) {
                $messages[] = 'Libellé : 160 caractères au plus.';
            }
            $key = 'B:' . ($personId ?? Cells::fold($row['matricule'] . $row['id_ancien'])) . '|' . Cells::fold($row['code_frais']) . '|' . ($due ?? '');
            $reference = self::reference($tenantId, $establishmentId, $academicYearId, $key);
            $status = $messages === [] ? 'valid' : 'error';
            if ($status === 'valid' && $this->invoices->findByReference($tenantId, $establishmentId, $reference) !== null) {
                $status = 'duplicate';
                $messages[] = 'Solde déjà repris (même élève, frais et échéance) : ligne ignorée.';
            }
            $lines[] = [
                'line' => (int) $number, 'status' => $status, 'key' => $key, 'label' => trim(($row['matricule'] !== '' ? $row['matricule'] : $row['id_ancien']) . ' · ' . $row['code_frais'] . ' · ' . $row['montant']),
                'messages' => $messages,
                'plan' => $status === 'valid' ? ['person_id' => $personId, 'structure_id' => $structureId, 'fee_code' => mb_strtolower($row['code_frais']), 'amount_xaf' => $amount, 'due_on' => $due, 'label' => $label, 'reference' => $reference] : null,
            ];
        }

        return $lines;
    }

    public function apply(Principal $principal, string $establishmentId, string $academicYearId, array $plan): string
    {
        return $this->invoices->forCharges(new ChargeInvoice(
            $principal->tenantId,
            $establishmentId,
            $principal->userId,
            (string) $plan['person_id'],
            $academicYearId,
            (string) $plan['reference'],
            [new ChargeLine((string) $plan['fee_code'], (string) $plan['due_on'], (int) $plan['amount_xaf'], (string) $plan['label'])],
            null,
            (string) $plan['structure_id'],
        ))->id;
    }

    /** Référence stable de la facture (36 caractères au plus) : client, établissement, année et clé de reprise. */
    private static function reference(string $tenantId, string $establishmentId, string $academicYearId, string $key): string
    {
        return 'se-mig-' . substr(hash('sha256', $tenantId . '|' . $establishmentId . '|' . $academicYearId . '|' . $key), 0, 28);
    }
}
