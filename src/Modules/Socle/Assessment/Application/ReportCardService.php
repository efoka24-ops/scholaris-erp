<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Psr\Container\ContainerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Assessment\Contracts\ReportCardVariables;
use Scholaris\Modules\Socle\Assessment\Infrastructure\ResultRepository;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * File de génération des bulletins : un conseil validé met une demande par élève en file ; la génération passe par
 * Documents\Contracts\DocumentIssuer. Type de document : assessment.report_card_types selon la nature de la période du
 * conseil (séquence, trimestre, année...), sinon assessment.report_card_type, sinon « report_card ». Les variables des
 * applications (assessment.report_card_variables.<application>) sont ajoutées si leur nom commence par
 * `<application>_` (ou par `primary_`, préfixe d'origine). Un échec (modèle absent, variable non déclarée) laisse la
 * demande en échec avec son motif ; elle sera reprise à la génération suivante, sans doublon pour les bulletins déjà émis.
 */
final class ReportCardService
{
    public const DOCUMENT_TYPE = 'report_card';
    public const LEGACY_PREFIX = 'primary_';

    public function __construct(
        private readonly ResultRepository $repo,
        private readonly MinutesIssuer $issuer,
        private readonly PersonDirectory $people,
        private readonly AssessmentClock $clock,
        private readonly AssessmentSettings $assessmentSettings,
        private readonly ContainerInterface $container,
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

        $period = $this->repo->period($tenantId, $establishmentId, (string) $council['period_id']);
        $type = $this->assessmentSettings->reportCardType($tenantId, $establishmentId, $period['kind'] ?? null, self::DOCUMENT_TYPE);
        $providers = AssessmentExtensions::collectByModule($this->container, AssessmentExtensions::REPORT_CARD_VARIABLES, ReportCardVariables::class);
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

            $variables = [
                'structure' => (string) $council['structure_id'], 'period' => (string) $council['period_label'],
                'average' => $result['average'] ?? null, 'rank' => $result['rank_in_class'] ?? null, 'class_size' => $result['class_size'] ?? null,
                'decision' => $line['decision'] ?? null, 'appraisal' => $line['appraisal'] ?? ($result['mention'] ?? null),
            ];
            foreach ($providers as $registered) {
                foreach ($registered['provider']->for($tenantId, $establishmentId, $councilId, $personId) as $name => $value) {
                    if (self::accepts((string) $name, $registered['module'])) {
                        $variables[$name] = $value;
                    }
                }
            }
            $issued = $this->issuer->issue($tenantId, $establishmentId, $type, $personId, $variables, $principal->userId, $person === null ? 'fr' : $person->locale);

            $this->repo->update('report_card_requests', $tenantId, (string) $request['id'], [
                'status' => $issued['status'] === 'issued' ? 'issued' : 'failed', 'document_id' => $issued['document_id'],
                'error_message' => $issued['message'], 'processed_at' => $this->clock->now(),
            ]);
        }

        return $this->repo->reportCards($tenantId, $councilId);
    }

    /**
     * Variables d'une application (SS5) : préfixe `<application>_` de sa clé de conteneur (secondary_, primary_...) ;
     * le préfixe historique `primary_` reste accepté de tout fournisseur (contrat d'origine du primaire).
     */
    public static function accepts(string $name, string $module): bool
    {
        return str_starts_with($name, self::LEGACY_PREFIX)
            || ($module !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $module) === 1 && str_starts_with($name, $module . '_') && strlen($name) > strlen($module) + 1);
    }

    /** @return array<string, mixed> */
    private function council(string $tenantId, string $establishmentId, string $councilId): array
    {
        return $this->repo->council($tenantId, $establishmentId, $councilId) ?? throw HttpException::notFound('Conseil de classe introuvable.');
    }
}
