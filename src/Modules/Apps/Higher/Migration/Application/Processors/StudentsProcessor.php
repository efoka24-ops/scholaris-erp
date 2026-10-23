<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application\Processors;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentImporter;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Migration\Application\Cells;
use Scholaris\Modules\Apps\Higher\Migration\Application\KindProcessor;
use Scholaris\Modules\Apps\Higher\Migration\Application\RowVerdict;
use Scholaris\Modules\Apps\Higher\Migration\Application\RunContext;
use Scholaris\Modules\Apps\Higher\Migration\Application\StructureResolver;
use Scholaris\Modules\Apps\Higher\Migration\Domain\ImportKind;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Étudiants en cours : fiche personne (LearnerWriter) puis inscription « inscrit » dans la filière, le niveau et le groupe de l'année
 * (EnrolmentImporter). Clé de reprise : matricule, sinon nom + prénom + date de naissance. Un matricule déjà connu avec une inscription
 * vivante de l'année dans la filière est un doublon (ignoré). Un étudiant mineur n'est pas repris ici (garant exigé : écran Inscriptions).
 */
final class StudentsProcessor implements KindProcessor
{
    private const GENDERS = ['m' => 'male', 'h' => 'male', 'masculin' => 'male', 'homme' => 'male', 'f' => 'female', 'feminin' => 'female', 'femme' => 'female'];

    public function __construct(
        private readonly StructureResolver $structures,
        private readonly StudentDirectory $students,
        private readonly EnrolmentImporter $enrolments,
        private readonly LearnerWriter $learners,
        private readonly PersonDirectory $people,
    ) {
    }

    public function kind(): string
    {
        return ImportKind::STUDENTS;
    }

    public function check(RunContext $context, int $line, array $row): RowVerdict
    {
        $matricule = strtoupper(trim($row['matricule']));
        $birth = Cells::date($row['date_naissance']);
        $key = $matricule !== '' ? 'M:' . $matricule : 'N:' . Cells::fold($row['nom']) . '|' . Cells::fold($row['prenom']) . '|' . ($birth ?? $row['date_naissance']);
        $verdict = new RowVerdict($line, $key, $row);
        foreach (['nom' => 'Nom', 'prenom' => 'Prénom', 'filiere' => 'Filière', 'niveau' => 'Niveau'] as $column => $label) {
            if ($row[$column] === '') {
                $verdict->error($label . ' manquant.');
            }
        }
        if ($birth === null) {
            $verdict->error('Date de naissance illisible (AAAA-MM-JJ ou JJ/MM/AAAA).');
        } elseif ($birth > date('Y-m-d')) {
            $verdict->error('Date de naissance dans le futur.');
        } elseif ((new \DateTimeImmutable($birth))->diff(new \DateTimeImmutable('today'))->y < 18) {
            $verdict->error('Étudiant mineur : un garant est exigé, inscrivez-le depuis l\'écran Inscriptions.');
        }
        $gender = null;
        if ($row['sexe'] !== '') {
            $gender = self::GENDERS[Cells::fold($row['sexe'])] ?? null;
            if ($gender === null) {
                $verdict->warn('Sexe « ' . $row['sexe'] . ' » non reconnu (M ou F) : laissé vide.');
            }
        }
        $mode = $row['mode'] === '' ? null : Cells::mode($row['mode']);
        if ($row['mode'] !== '' && $mode === null) {
            $verdict->error('Mode « ' . $row['mode'] . ' » inconnu : jour, soir ou alternance.');
        }
        if ($row['email'] !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
            $verdict->error('Adresse e-mail invalide.');
        }
        if ($row['telephone'] !== '' && preg_match('/^\+?[0-9 ]{8,16}$/', $row['telephone']) !== 1) {
            $verdict->error('Numéro de téléphone invalide (chiffres, « + » au début).');
        }
        if ($row['telephone'] === '' && $row['email'] === '') {
            $verdict->warn('Ni téléphone ni e-mail : l\'étudiant ne recevra aucun message.');
        }
        $resolved = $this->structures->resolve($context, $row['filiere'], $row['niveau'], $row['groupe']);
        foreach ($resolved['errors'] as $error) {
            $verdict->error($error);
        }
        $program = $resolved['program'];
        $level = $resolved['level'];
        $group = $resolved['group'];
        if ($program !== null && $level !== null && $resolved['errors'] === []) {
            $refusalKey = 'refusal|' . $level->id . '|' . ($group->id ?? '');
            if (!array_key_exists($refusalKey, $context->cache)) {
                $context->cache[$refusalKey] = $this->enrolments->refusal($context->tenantId, $context->establishmentId, $level->id, $group?->id, $context->yearId);
            }
            if (is_string($context->cache[$refusalKey])) {
                $verdict->error('Inscription impossible : ' . $context->cache[$refusalKey]);
            }
        }
        if ($row['redoublant'] !== '' && !in_array(Cells::fold($row['redoublant']), ['oui', 'non', 'o', 'n', 'yes', 'no', '1', '0'], true)) {
            $verdict->error('Redoublant : répondez oui ou non.');
        }
        $existingPerson = null;
        if ($row['person_id'] !== '') {
            $existingPerson = $this->people->find($context->tenantId, $row['person_id']) !== null ? $row['person_id'] : null;
            if ($existingPerson === null) {
                $verdict->error('person_id introuvable dans ce client.');
            }
        }
        if ($matricule !== '' && $program !== null) {
            $known = $this->students->byMatricule($context->tenantId, $context->establishmentId, $matricule);
            if ($known !== null) {
                $existingPerson ??= $known->personId;
                if ($existingPerson !== $known->personId) {
                    $verdict->error('Ce matricule appartient à une autre personne.');
                }
                foreach ($this->students->enrolmentsOf($context->tenantId, $context->establishmentId, $known->personId) as $enrolment) {
                    if ($enrolment->academicYearId === $context->yearId && $enrolment->programId === $program->id && in_array($enrolment->status, ['pending', 'enrolled', 'financial_hold', 'suspended'], true)) {
                        $verdict->duplicate = true;
                        $verdict->warn('Déjà inscrit dans cette filière cette année : ligne ignorée.');
                    }
                }
                if (!$verdict->duplicate) {
                    $verdict->warn('Étudiant déjà connu (matricule) : l\'inscription de l\'année est ajoutée à sa fiche.');
                }
            }
        }
        $first = $context->firstSeen($key, $line);
        if ($first !== null) {
            $verdict->error('Même étudiant que la ligne ' . $first . ' (doublon dans le fichier).');
        } elseif ($context->alreadyImported($key)) {
            $verdict->duplicate = true;
            $verdict->warn('Déjà repris par un lot précédent : ligne ignorée.');
        }
        $verdict->personId = $existingPerson;
        $verdict->plan = [
            'person_id' => $existingPerson, 'level_id' => $level?->id, 'group_id' => $group?->id, 'program_id' => $program?->id, 'mode' => $mode,
            'matricule' => $matricule === '' ? null : $matricule, 'attempt' => Cells::yes($row['redoublant']) ? 2 : 1, 'birth' => $birth, 'gender' => $gender,
            'confirm' => Cells::yes($row['confirmer_different']),
        ];

        return $verdict;
    }

    public function apply(RunContext $context, array $verdicts): array
    {
        $refs = [];
        foreach ($verdicts as $verdict) {
            $plan = $verdict->plan;
            $row = $verdict->row;
            $personId = is_string($plan['person_id']) ? $plan['person_id'] : null;
            $created = false;
            try {
                if ($personId === null) {
                    $draft = array_filter([
                        'first_name' => $row['prenom'], 'last_name' => $row['nom'], 'birth_date' => $plan['birth'], 'birth_place' => $row['lieu_naissance'],
                        'gender' => $plan['gender'], 'phone' => $row['telephone'], 'email' => $row['email'], 'locale' => 'fr',
                    ], static fn (mixed $v): bool => $v !== null && $v !== '');
                    $personId = $this->learners->createLearner($context->tenantId, $draft, [], $context->actorId, $plan['confirm'] === true)['person_id'];
                    $created = true;
                }
                $result = $this->enrolments->importEnrolled(
                    $context->tenantId,
                    $context->establishmentId,
                    $context->actorId,
                    $personId,
                    (string) $plan['level_id'],
                    is_string($plan['group_id']) ? $plan['group_id'] : null,
                    $context->yearId,
                    is_string($plan['mode']) ? $plan['mode'] : null,
                    is_string($plan['matricule']) ? $plan['matricule'] : null,
                    (int) $plan['attempt'],
                );
            } catch (HttpException $e) {
                throw AtLine::wrap($verdict->line, $e);
            }
            $refs[$verdict->line] = [
                'person_id' => $personId, 'person_created' => $created, 'enrolment_id' => $result['enrolment_id'], 'enrolment_created' => $result['created'],
                'matricule' => $result['matricule'], 'roll_call' => $result['roll_call'],
            ];
        }

        return $refs;
    }

    public function revert(RunContext $context, array $refs, string $reason): void
    {
        if (($refs['enrolment_created'] ?? false) === true && is_string($refs['enrolment_id'] ?? null)) {
            $this->enrolments->revertImported($context->tenantId, $context->establishmentId, $context->actorId, $refs['enrolment_id'], $reason);
        }
    }

    public function revertRefusal(): ?string
    {
        return null;
    }
}
