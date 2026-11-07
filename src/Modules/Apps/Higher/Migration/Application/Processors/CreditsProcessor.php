<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application\Processors;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\UnitCreditRecorder;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentImporter;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Migration\Application\Cells;
use Scholaris\Modules\Apps\Higher\Migration\Application\KindProcessor;
use Scholaris\Modules\Apps\Higher\Migration\Application\RowVerdict;
use Scholaris\Modules\Apps\Higher\Migration\Application\RunContext;
use Scholaris\Modules\Apps\Higher\Migration\Application\StructureResolver;
use Scholaris\Modules\Apps\Higher\Migration\Domain\ImportKind;

/**
 * UE capitalisées (hi_unit_credits, via UnitCreditRecorder) et dettes (lignes « dette » du contrat pédagogique de l'inscription en
 * cours, via EnrolmentImporter). Seules les UE sont reprises des années antérieures, jamais leurs notes. L'étudiant doit déjà exister
 * (reprise « Étudiants » faite avant). Clé de reprise : matricule + filière + année d'origine + code UE.
 */
final class CreditsProcessor implements KindProcessor
{
    public function __construct(
        private readonly StructureResolver $structures,
        private readonly StudentDirectory $students,
        private readonly EnrolmentImporter $enrolments,
        private readonly UnitCreditRecorder $credits,
    ) {
    }

    public function kind(): string
    {
        return ImportKind::CREDITS;
    }

    public function check(RunContext $context, int $line, array $row): RowVerdict
    {
        $matricule = strtoupper($row['matricule']);
        $unit = strtoupper($row['ue_code']);
        $status = Cells::fold($row['statut']);
        $verdict = new RowVerdict($line, 'C:' . $matricule . '|' . strtoupper($row['filiere']) . '|' . $row['annee'] . '|' . $unit . '|' . $status, $row);
        foreach (['matricule' => 'Matricule', 'annee' => 'Année d\'origine', 'ue_code' => 'Code UE'] as $column => $label) {
            if ($row[$column] === '') {
                $verdict->error($label . ' manquant.');
            }
        }
        if (mb_strlen($row['annee']) > 40 || mb_strlen($row['ue_code']) > 40) {
            $verdict->error('Année ou code UE trop long (40 caractères au plus).');
        }
        if ($row['annee'] === $context->yearId) {
            $verdict->error('L\'année d\'origine ne peut pas être l\'année en cours : les UE de l\'année en cours viennent des notes et des délibérations.');
        }
        $credits = Cells::int($row['credits']);
        if ($credits === null || $credits < 1 || $credits > 60) {
            $verdict->error('Crédits : entier de 1 à 60 attendu.');
        }
        $average = null;
        if ($row['moyenne'] !== '') {
            $average = Cells::decimal($row['moyenne']);
            if ($average === null || $average > 20) {
                $verdict->error('Moyenne : nombre de 0 à 20 attendu.');
            }
        }
        $origin = match ($status) {
            'capitalisee', 'capitalise', 'capitalized' => 'capitalized',
            'dette', 'debt' => 'debt',
            default => null,
        };
        if ($origin === null) {
            $verdict->error('Statut « ' . $row['statut'] . ' » inconnu : capitalisee ou dette.');
        }
        $resolved = $this->structures->resolve($context, $row['filiere'], $row['niveau']);
        foreach ($resolved['errors'] as $error) {
            $verdict->error($error);
        }
        $student = $matricule === '' ? null : $this->students->byMatricule($context->tenantId, $context->establishmentId, $matricule);
        if ($matricule !== '' && $student === null) {
            $verdict->error('Matricule inconnu : reprenez d\'abord les étudiants.');
        }
        if ($student !== null && $resolved['program'] !== null) {
            $verdict->personId = $student->personId;
            $hasEnrolment = false;
            foreach ($this->students->enrolmentsOf($context->tenantId, $context->establishmentId, $student->personId) as $enrolment) {
                $hasEnrolment = $hasEnrolment || ($enrolment->academicYearId === $context->yearId && $enrolment->programId === $resolved['program']->id);
            }
            if (!$hasEnrolment) {
                $verdict->warn('Pas d\'inscription de l\'année dans cette filière : ' . ($origin === 'debt' ? 'la dette ne sera pas reportée au contrat.' : 'seuls les crédits cumulés sont enregistrés.'));
            }
        }
        if ($context->firstSeen($verdict->key, $line) !== null) {
            $verdict->error('Même UE que la ligne ' . $context->seen[$verdict->key] . ' (doublon dans le fichier).');
        } elseif ($context->alreadyImported($verdict->key)) {
            $verdict->duplicate = true;
            $verdict->warn('Déjà repris par un lot précédent : ligne ignorée.');
        }
        $verdict->plan = [
            'program_id' => $resolved['program']?->id, 'level_id' => $resolved['level']?->id, 'origin' => $origin, 'credits' => $credits, 'average' => $average,
            'person_id' => $student?->personId,
        ];

        return $verdict;
    }

    public function apply(RunContext $context, array $verdicts): array
    {
        $refs = [];
        foreach ($verdicts as $verdict) {
            $plan = $verdict->plan;
            $row = $verdict->row;
            $person = (string) $plan['person_id'];
            $title = $row['ue_intitule'] === '' ? null : $row['ue_intitule'];
            try {
                $created = false;
                if ($plan['origin'] === 'capitalized') {
                    $created = $this->credits->record($context->tenantId, $context->establishmentId, $context->batchId, $person, (string) $plan['program_id'], (string) $plan['level_id'], $row['annee'], strtoupper($row['ue_code']), $title, (int) $plan['credits'], is_float($plan['average']) ? $plan['average'] : null);
                }
                $item = $this->enrolments->addCarryOver($context->tenantId, $context->establishmentId, $context->actorId, $person, $context->yearId, (string) $plan['program_id'], (string) $plan['origin'], strtoupper($row['ue_code']), $title ?? strtoupper($row['ue_code']), (int) $plan['credits'], (string) $plan['level_id']);
            } catch (HttpException $e) {
                throw AtLine::wrap($verdict->line, $e);
            }
            $refs[$verdict->line] = ['person_id' => $person, 'credit_created' => $created, 'item_id' => $item, 'origin' => $plan['origin']];
        }

        return $refs;
    }

    public function revert(RunContext $context, array $refs, string $reason): void
    {
        $this->credits->revoke($context->tenantId, $context->establishmentId, $context->batchId);
        if (is_string($refs['item_id'] ?? null) && ($refs['origin'] ?? '') === 'debt') {
            $this->enrolments->removeCarryOver($context->tenantId, $context->establishmentId, $refs['item_id']);
        }
    }

    public function revertRefusal(): ?string
    {
        return null;
    }
}
