<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application\Processors;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\OpeningBalances;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Migration\Application\Cells;
use Scholaris\Modules\Apps\Higher\Migration\Application\KindProcessor;
use Scholaris\Modules\Apps\Higher\Migration\Application\RowVerdict;
use Scholaris\Modules\Apps\Higher\Migration\Application\RunContext;
use Scholaris\Modules\Apps\Higher\Migration\Application\StructureResolver;
use Scholaris\Modules\Apps\Higher\Migration\Domain\ImportKind;

/**
 * Soldes d'ouverture : ce qui reste dû à l'ouverture du logiciel, échelonné en 1 à 3 tranches (compte de scolarité sur facture libre).
 * Les paiements antérieurs ne sont pas repris. Un solde nul n'ouvre aucun compte. Un compte déjà ouvert pour l'année et la filière
 * est conservé (doublon). Ce jeu n'est pas annulable : une facture émise ne se supprime pas (correction par la comptabilité).
 */
final class BalancesProcessor implements KindProcessor
{
    public function __construct(
        private readonly StructureResolver $structures,
        private readonly StudentDirectory $students,
        private readonly OpeningBalances $balances,
    ) {
    }

    public function kind(): string
    {
        return ImportKind::BALANCES;
    }

    public function check(RunContext $context, int $line, array $row): RowVerdict
    {
        $matricule = strtoupper($row['matricule']);
        $verdict = new RowVerdict($line, 'B:' . $matricule . '|' . strtoupper($row['filiere']), $row);
        if ($matricule === '') {
            $verdict->error('Matricule manquant.');
        }
        $balance = Cells::int($row['solde_xaf']);
        if ($balance === null) {
            $verdict->error('Solde : montant entier en FCFA attendu (sans décimales).');
        }
        $terms = [];
        for ($i = 1; $i <= 3; ++$i) {
            $date = $row['tranche' . $i . '_date'];
            $amount = $row['tranche' . $i . '_xaf'];
            if ($date === '' && $amount === '') {
                continue;
            }
            $due = Cells::date($date);
            if ($due === null) {
                $verdict->error('Tranche ' . $i . ' : date illisible (AAAA-MM-JJ).');
                continue;
            }
            $value = $amount === '' ? ($i === 1 && $row['tranche2_date'] === '' ? $balance : null) : Cells::int($amount);
            if ($value === null || $value <= 0) {
                $verdict->error('Tranche ' . $i . ' : montant entier positif attendu.');
                continue;
            }
            $terms[] = ['due_on' => $due, 'amount_xaf' => $value];
        }
        if ($balance !== null && $balance > 0 && $terms === []) {
            $verdict->error('Au moins une tranche (date et montant) est exigée.');
        }
        if ($balance !== null && $terms !== [] && array_sum(array_column($terms, 'amount_xaf')) !== $balance) {
            $verdict->error(sprintf('Les tranches (%d FCFA) ne totalisent pas le solde (%d FCFA).', array_sum(array_column($terms, 'amount_xaf')), $balance));
        }
        $mode = $row['mode'] === '' ? null : Cells::mode($row['mode']);
        if ($row['mode'] !== '' && $mode === null) {
            $verdict->error('Mode « ' . $row['mode'] . ' » inconnu : jour, soir ou alternance.');
        }
        $resolved = $this->structures->resolve($context, $row['filiere'], $row['niveau']);
        foreach ($resolved['errors'] as $error) {
            $verdict->error($error);
        }
        $student = $matricule === '' ? null : $this->students->byMatricule($context->tenantId, $context->establishmentId, $matricule);
        $enrolmentId = null;
        if ($matricule !== '' && $student === null) {
            $verdict->error('Matricule inconnu : reprenez d\'abord les étudiants.');
        }
        if ($student !== null && $resolved['program'] !== null) {
            $verdict->personId = $student->personId;
            foreach ($this->students->enrolmentsOf($context->tenantId, $context->establishmentId, $student->personId) as $enrolment) {
                if ($enrolment->academicYearId === $context->yearId && $enrolment->programId === $resolved['program']->id) {
                    $enrolmentId = $enrolment->id;
                }
            }
            if ($enrolmentId === null) {
                $verdict->error('Aucune inscription de l\'année dans cette filière : reprenez d\'abord l\'étudiant.');
            } elseif ($this->balances->exists($context->tenantId, $context->establishmentId, $student->personId, $context->yearId, $resolved['program']->id)) {
                $verdict->duplicate = true;
                $verdict->warn('Compte de scolarité déjà ouvert pour cette année et cette filière : ligne ignorée.');
            }
        }
        if ($balance === 0) {
            $verdict->warn('Solde nul : aucun compte ne sera ouvert.');
        }
        if ($context->firstSeen($verdict->key, $line) !== null) {
            $verdict->error('Même étudiant et même filière que la ligne ' . $context->seen[$verdict->key] . ' (doublon dans le fichier).');
        } elseif ($context->alreadyImported($verdict->key)) {
            $verdict->duplicate = true;
            $verdict->warn('Déjà repris par un lot précédent : ligne ignorée.');
        }
        $verdict->plan = [
            'person_id' => $student?->personId, 'program_id' => $resolved['program']?->id, 'school_id' => $resolved['program']?->schoolId,
            'level_id' => $resolved['level']?->id, 'mode' => $mode, 'enrolment_id' => $enrolmentId, 'terms' => $terms, 'balance' => $balance,
        ];

        return $verdict;
    }

    public function apply(RunContext $context, array $verdicts): array
    {
        $refs = [];
        foreach ($verdicts as $verdict) {
            $plan = $verdict->plan;
            if (($plan['balance'] ?? 0) === 0) {
                $refs[$verdict->line] = ['person_id' => $plan['person_id'], 'account_id' => null];
                continue;
            }
            try {
                /** @var list<array{due_on: string, amount_xaf: int}> $terms */
                $terms = $plan['terms'];
                $result = $this->balances->open(
                    $context->tenantId,
                    $context->establishmentId,
                    $context->actorId,
                    (string) $plan['person_id'],
                    $context->yearId,
                    (string) $plan['school_id'],
                    (string) $plan['program_id'],
                    is_string($plan['level_id']) ? $plan['level_id'] : null,
                    is_string($plan['mode']) ? $plan['mode'] : null,
                    is_string($plan['enrolment_id']) ? $plan['enrolment_id'] : null,
                    $terms,
                );
            } catch (HttpException $e) {
                throw AtLine::wrap($verdict->line, $e);
            }
            $refs[$verdict->line] = ['person_id' => $plan['person_id'], 'account_id' => $result['account_id'], 'invoice_id' => $result['invoice_id'], 'net_xaf' => $result['net_xaf']];
        }

        return $refs;
    }

    public function revert(RunContext $context, array $refs, string $reason): void
    {
        throw HttpException::conflict($this->revertRefusal());
    }

    public function revertRefusal(): string
    {
        return 'Les soldes d\'ouverture ne s\'annulent pas : une facture émise ne se supprime pas. Faites corriger le compte par le comptable (remise ou note de crédit).';
    }
}
