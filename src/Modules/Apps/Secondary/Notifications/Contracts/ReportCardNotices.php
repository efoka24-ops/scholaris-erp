<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Notifications\Contracts;

/**
 * NOT-05 « bulletin disponible » (S-NOT05) : SMS `sec.not05.report_card_ready` aux garants de l'élève, appelé par Councils à la
 * publication des bulletins d'un conseil. Décision du propriétaire du 12 novembre 2026 (D-BUL) : message de SERVICE essentiel par
 * défaut (EssentialMessenger du socle : sans consentement recueilli, désinscription et consentement révoqué respectés). Paramètre
 * `secondary.report_card.sms_essential` (plateforme, client ou établissement) ; à faux, le Messenger ordinaire exige le consentement.
 * Idempotent : un même bulletin n'est annoncé qu'une fois à un même garant (journal `se_notification_log`, code NOT-05) ; un envoi
 * bloqué ou en échec n'est pas journalisé et sera retenté à la publication suivante.
 */
interface ReportCardNotices
{
    public const SETTING = 'secondary.report_card.sms_essential';

    /**
     * @param array<string, scalar> $variables child_name, period_label, average, rank, decision, link
     * @return array{families: int, notified: int, already_notified: int, not_notified: list<string>}
     *               garants, garants prévenus par cet appel, garants déjà prévenus de ce bulletin, motifs des envois non faits
     */
    public function reportCardReady(string $tenantId, string $establishmentId, string $documentId, string $learnerId, array $variables): array;
}
