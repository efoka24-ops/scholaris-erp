<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application;

use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoReportCards;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;

/**
 * Section « bulletins » du portail famille (EX-COM-04, R13 : lecture des bulletins de ses enfants) : bulletins PUBLIÉS seulement
 * (jamais un brouillon), mentions par discipline et numéro du document officiel. Aucun rang ni donnée APEE.
 */
final class ReportCardsSection implements PortalSection
{
    public function __construct(private readonly PdoReportCards $cards, private readonly ApplicationAccess $access)
    {
    }

    public function key(): string
    {
        return 'public_primary_report_cards';
    }

    public function portals(): array
    {
        return [self::FAMILY];
    }

    public function build(PortalSectionContext $context): ?array
    {
        if (!$context->full || !$this->access->allowsReads($context->tenantId, 'public_primary')) {
            return null;
        }
        $cards = $this->cards->publishedOf($context->tenantId, $context->subjectPersonId);
        if ($cards === []) {
            return null;
        }

        return ['report_cards' => array_map(static function (array $row): array {
            $content = (array) json_decode((string) $row['content'], true);

            return [
                'id' => (string) $row['id'], 'kind' => (string) $row['card_kind'], 'year_label' => (string) $row['year_label'],
                'period_label_fr' => $content['period_label_fr'] ?? null, 'period_label_en' => $content['period_label_en'] ?? null,
                'state' => (string) $row['card_state'], 'document_id' => $row['document_id'], 'published_at' => $row['published_at'],
                'disciplines' => array_map(static fn (array $line): array => [
                    'label_fr' => $line['label_fr'] ?? '', 'label_en' => $line['label_en'] ?? '', 'mention' => $line['mention'] ?? '',
                ], array_values(array_filter((array) ($content['disciplines'] ?? []), 'is_array'))),
                'appraisal' => $row['appraisal'],
            ];
        }, $cards)];
    }
}
