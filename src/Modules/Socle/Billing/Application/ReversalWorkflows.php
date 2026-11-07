<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Modules\Socle\Workflows\Contracts\Actor;
use Scholaris\Modules\Socle\Workflows\Contracts\Circuit;
use Scholaris\Modules\Socle\Workflows\Contracts\Lock;
use Scholaris\Modules\Socle\Workflows\Contracts\Step;
use Scholaris\Modules\Socle\Workflows\Contracts\WorkflowDefinitions;

/**
 * Circuit « annulation d'un paiement » du socle de facturation. Il vit dans le socle (et non dans une application) parce que
 * l'annulation est la même pour toute application qui encaisse : une seule déclaration, réglable par établissement.
 *
 * Le défaut reproduit EXACTEMENT le comportement historique : le caissier demande ; valide quiconque détient le droit
 * `billing.reversal.approve` (comptable, directeur, direction de groupe), jamais le demandeur. L'administrateur peut seulement
 * confier la validation à la direction (droit dédié) ou exiger la confirmation récente ; les verrous de sécurité ne bougent pas.
 */
final class ReversalWorkflows implements WorkflowDefinitions
{
    public const APPLICATION = 'billing';
    public const REVERSAL = 'billing_reversal';

    public const ACTOR_ACCOUNTANT = 'comptable';
    public const ACTOR_DIRECTION = 'direction';
    public const REQUEST_PERMISSION = 'billing.reversal.request';
    public const APPROVE_PERMISSION = 'billing.reversal.approve';
    public const APPROVE_DIRECTION_PERMISSION = 'billing.reversal.approve.direction';

    /** @return list<Circuit> */
    public function circuits(): array
    {
        return [new Circuit(self::APPLICATION, self::REVERSAL, 'Annulation d\'un paiement', 'Payment reversal', [
            new Step('request', 'Demande motivée', 'Reasoned request', [
                new Actor('caissier', 'Caissier ou agent de caisse', 'Cashier', self::REQUEST_PERMISSION),
            ], 'caissier', locks: [
                new Lock(Lock::ACTOR, 'cashier_request', 'La demande est déposée par la caisse, avec un motif obligatoire.', 'The request is filed by the cash desk, with a mandatory reason.'),
            ]),
            new Step('approval', 'Validation', 'Approval', [
                new Actor(self::ACTOR_ACCOUNTANT, 'Comptable (ou direction)', 'Accountant (or management)', self::APPROVE_PERMISSION),
                new Actor(self::ACTOR_DIRECTION, 'Direction uniquement', 'Management only', self::APPROVE_DIRECTION_PERMISSION),
            ], self::ACTOR_ACCOUNTANT),
        ], [
            new Lock(Lock::CIRCUIT, 'two_people', 'Deux personnes distinctes : celui qui valide n\'est jamais celui qui a demandé l\'annulation.', 'Two distinct people: the approver is never the one who requested the reversal.'),
            new Lock(Lock::CIRCUIT, 'reason', 'Toute demande d\'annulation est motivée.', 'Every reversal request is reasoned.'),
            new Lock(Lock::CIRCUIT, 'receipt_kept', 'Le reçu n\'est jamais supprimé : il est marqué annulé, et une écriture inverse est passée.', 'The receipt is never deleted: it is marked cancelled and a reversing entry is posted.'),
            new Lock(Lock::CIRCUIT, 'audit', 'Chaque demande et chaque décision est enregistrée au journal d\'audit.', 'Every request and decision is recorded in the audit log.'),
        ])];
    }
}
