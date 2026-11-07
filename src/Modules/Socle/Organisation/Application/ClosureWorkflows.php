<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use Scholaris\Modules\Socle\Workflows\Contracts\Actor;
use Scholaris\Modules\Socle\Workflows\Contracts\Circuit;
use Scholaris\Modules\Socle\Workflows\Contracts\Lock;
use Scholaris\Modules\Socle\Workflows\Contracts\Step;
use Scholaris\Modules\Socle\Workflows\Contracts\WorkflowDefinitions;

/**
 * Circuit de clôture d'une année scolaire (tous les établissements) : demande, puis approbation par une AUTRE personne avec
 * confirmation récente. Tout y est verrouillé : l'administrateur le consulte, il ne l'assouplit pas.
 */
final class ClosureWorkflows implements WorkflowDefinitions
{
    public const APPLICATION = 'organisation';
    public const CLOSURE = 'year_closure';

    /** @return list<Circuit> */
    public function circuits(): array
    {
        $manager = static fn (): array => [new Actor('administrateur', 'Administrateur de l\'établissement', 'Institution administrator', 'organisation.manage')];

        return [new Circuit(self::APPLICATION, self::CLOSURE, 'Clôture d\'une année scolaire', 'Closing of a school year', [
            new Step('request', 'Demande de clôture', 'Closure request', $manager(), 'administrateur', locks: [
                new Lock(Lock::ACTOR, 'closure_manager', 'La clôture est demandée par l\'administration de l\'établissement.', 'The closure is requested by the institution\'s administration.'),
            ]),
            new Step('approve', 'Approbation et exécution', 'Approval and execution', $manager(), 'administrateur', confirmation: true, confirmationMin: true, locks: [
                new Lock(Lock::ACTOR, 'closure_manager', 'L\'approbation revient à l\'administration de l\'établissement, jamais au demandeur.', 'The approval belongs to the institution\'s administration, never to the requester.'),
                new Lock(Lock::CONFIRMATION, 'closure_confirmation', 'La clôture est irréversible : la confirmation récente est toujours exigée.', 'Closing a year cannot be undone: recent confirmation is always required.'),
            ]),
        ], [
            new Lock(Lock::CIRCUIT, 'two_people', 'Deux personnes distinctes : celui qui approuve n\'est jamais celui qui a demandé.', 'Two distinct people: the approver is never the requester.'),
            new Lock(Lock::CIRCUIT, 'checks', 'Les contrôles bloquants (impayés, notes non verrouillées, délibérations ouvertes) empêchent la clôture.', 'Blocking checks (unpaid fees, unlocked marks, open deliberations) prevent the closure.'),
            new Lock(Lock::CIRCUIT, 'audit', 'Chaque étape de la clôture est enregistrée au journal d\'audit.', 'Every closing step is recorded in the audit log.'),
        ])];
    }
}
