<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Notifications\Contracts\ReportCardNotices;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Socle\Assessment\Contracts\CouncilDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\IssuedReportCards;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Publication des bulletins d'un conseil aux familles (EX-BUL-06, NOT-05, S-NOT05) : les bulletins émis par le socle sont déjà
 * visibles au portail ; la publication les annonce par SMS « bulletin disponible » (moyenne, rang, décision) aux garants de chaque
 * élève, une seule fois par bulletin et par garant (republier ne renvoie rien). Message essentiel par défaut (D-BUL), paramètre
 * `secondary.report_card.sms_essential`. Le résumé dit combien de familles sont prévenues et pourquoi les autres ne le sont pas.
 */
final class ReportCardPublication
{
    public const PUBLISH = ['secondary.reportcard.validate', 'secondary.council.preside', 'secondary.council.prepare'];
    public const LINK = '/secondaire/famille';

    public function __construct(
        private readonly Access $access,
        private readonly CouncilDirectory $councils,
        private readonly IssuedReportCards $issued,
        private readonly PersonDirectory $people,
        private readonly ReportCardNotices $notices,
    ) {
    }

    /**
     * `published` : élèves dont le bulletin est émis ; `families` : garants de ces élèves ; `notified` : garants prévenus par cette
     * publication ; `already_notified` : garants déjà prévenus de ce bulletin ; `not_notified` : envois non faits, par motif du socle
     * (consent_missing, consent_revoked, opted_out, no_contact…).
     *
     * @return array{published: int, notified: int, families: int, already_notified: int, not_notified: array<string, int>}
     */
    public function publish(Principal $principal, string $establishmentId, string $councilId): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, self::PUBLISH);
        $council = $this->councils->find($tenantId, $establishmentId, $councilId) ?? throw HttpException::notFound('Conseil de classe introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, $council['structure_id'], 'class_council', $councilId);
        if ($council['status'] !== 'validated') {
            throw HttpException::conflict('Le conseil de classe doit être validé avant la publication des bulletins.');
        }
        $cards = $this->issued->issuedFor($tenantId, $establishmentId, $councilId);
        if ($cards === []) {
            throw HttpException::conflict('Aucun bulletin émis à publier pour ce conseil. Générez les bulletins d\'abord.');
        }
        $lines = array_column($council['lines'], null, 'person_id');
        $summary = ['published' => count($cards), 'notified' => 0, 'families' => 0, 'already_notified' => 0, 'not_notified' => []];
        foreach ($cards as $card) {
            $line = $lines[$card['person_id']] ?? null;
            $child = $this->people->find($tenantId, $card['person_id']);
            $done = $this->notices->reportCardReady($tenantId, $establishmentId, $card['document_id'], $card['person_id'], [
                'child_name' => $child === null ? '' : $child->fullName(),
                'period_label' => $council['period_label'],
                'average' => $line === null || $line['average'] === null ? '-' : number_format($line['average'], 2, ',', ' '),
                'rank' => $line === null || $line['rank'] === null ? '-' : (string) $line['rank'],
                'decision' => $line === null || $line['decision'] === null ? '-' : $line['decision'],
                'link' => self::LINK,
            ]);
            $summary['notified'] += $done['notified'];
            $summary['families'] += $done['families'];
            $summary['already_notified'] += $done['already_notified'];
            foreach ($done['not_notified'] as $reason) {
                $summary['not_notified'][$reason] = ($summary['not_notified'][$reason] ?? 0) + 1;
            }
        }
        $this->access->audit($principal, 'secondary.reportcards.published', 'class_council', $councilId, null, [
            'published' => $summary['published'], 'notified' => $summary['notified'], 'already_notified' => $summary['already_notified'],
        ]);

        return $summary;
    }
}
