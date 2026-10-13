<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Assessment\Infrastructure\ResultRepository;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * File de génération des bulletins : un conseil validé met une demande par élève en file ; la génération passe par
 * Documents\Contracts\DocumentIssuer (type « report_card »). Un échec (modèle absent, variable non déclarée) laisse la
 * demande en échec avec son motif ; elle sera reprise à la génération suivante, sans doublon pour les bulletins déjà émis.
 */
final class ReportCardService
{
    public const DOCUMENT_TYPE = 'report_card';

    public function __construct(
        private readonly ResultRepository $repo,
        private readonly MinutesIssuer $issuer,
        private readonly PersonDirectory $people,
        private readonly AssessmentClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function queue(string $tenantId, string $establishmentId, string $councilId): array
    {
        $this->council($tenantId, $establishmentId, $councilId);

        return $this->repo->reportCards($tenantId, $councilId);
    }

    /**
     * Traite les demandes en attente ou en échec.
     *
     * @return list<array<string, mixed>>
     */
    public function generate(Principal $principal, string $establishmentId, string $councilId): array
    {
        $tenantId = $principal->tenantId;
        $council = $this->council($tenantId, $establishmentId, $councilId);
        if ($council['status'] !== 'validated') {
            throw HttpException::conflict('Les bulletins se génèrent après la validation du conseil de classe.');
        }

        $lines = [];
        foreach ($this->repo->councilLines($tenantId, $councilId) as $line) {
            $lines[(string) $line['person_id']] = $line;
        }
        foreach ($this->repo->reportCards($tenantId, $councilId) as $request) {
            if ($request['status'] === 'issued') {
                continue;
            }
            $personId = (string) $request['person_id'];
            $result = $this->repo->resultOf($tenantId, $establishmentId, $personId, (string) $council['period_id']);
            $line = $lines[$personId] ?? [];
            $person = $this->people->find($tenantId, $personId);

            $issued = $this->issuer->issue($tenantId, $establishmentId, self::DOCUMENT_TYPE, $personId, [
                'structure' => (string) $council['structure_id'], 'period' => (string) $council['period_label'],
                'average' => $result['average'] ?? null, 'rank' => $result['rank_in_class'] ?? null, 'class_size' => $result['class_size'] ?? null,
                'decision' => $line['decision'] ?? null, 'appraisal' => $line['appraisal'] ?? ($result['mention'] ?? null),
            ], $principal->userId, $person === null ? 'fr' : $person->locale);

            $this->repo->update('report_card_requests', $tenantId, (string) $request['id'], [
                'status' => $issued['status'] === 'issued' ? 'issued' : 'failed', 'document_id' => $issued['document_id'],
                'error_message' => $issued['message'], 'processed_at' => $this->clock->now(),
            ]);
        }

        return $this->repo->reportCards($tenantId, $councilId);
    }

    /** @return array<string, mixed> */
    private function council(string $tenantId, string $establishmentId, string $councilId): array
    {
        return $this->repo->council($tenantId, $establishmentId, $councilId) ?? throw HttpException::notFound('Conseil de classe introuvable.');
    }
}
