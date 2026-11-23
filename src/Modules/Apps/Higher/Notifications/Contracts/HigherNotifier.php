<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Notifications\Contracts;

/**
 * Envoi d'une notification de l'IPES (NOT-01 à NOT-11) par le Messenger du socle, avec le modèle bilingue `higher.notNN.*`.
 * Destinataires selon le catalogue : l'étudiant (ou le candidat), ses garants si la notification les vise (consentement
 * RG-INS-7 exigé pour les absences, NOT-06 et NOT-07), l'enseignant pour NOT-11. Une clé de dédoublonnage empêche un second envoi
 * du même événement (relances quotidiennes). Un échec d'envoi n'interrompt jamais l'opération métier.
 */
interface HigherNotifier
{
    /**
     * @param string $code NOT-01 à NOT-11
     * @param array<string, scalar> $variables variables permises par le Messenger (amount, due_on, label, reference, receipt_number...)
     * @return list<array{recipient_id: string, status: string, reason: ?string}> une entrée par destinataire (sent, queued, blocked, duplicate)
     */
    public function notify(string $tenantId, string $establishmentId, string $code, string $personId, array $variables = [], ?string $dedupeKey = null): array;
}
