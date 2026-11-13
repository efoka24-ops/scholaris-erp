<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Fees\Application\FinancialSituation;
use Scholaris\Modules\Apps\Secondary\Fees\Application\YearEndExport;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Restes du lot E1 (E4-B) : situation financière par classe, niveau et établissement avec prévisions (EX-FIN-07, droits des frais)
 * et fichier des conseils de fin d'année pour cartescolaire.cm (DOC-33, API-04 : scolarité ou chef d'établissement, journalisé).
 */
final class FeesReportsController extends Controller
{
    public const EXPORT = ['secondary.enrol.manage', 'secondary.settings.manage'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly FinancialSituation $situation,
        private readonly YearEndExport $export,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function situation(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, FeesController::READ);
        $this->access->assertWhole($principal, $perimeter, 'fees_situation');

        return $this->json($this->situation->build($principal->tenantId, $establishmentId, self::year($request, false)));
    }

    public function yearEndExport(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::EXPORT);
        $this->access->assertWhole($principal, $perimeter, 'year_end_export');
        $year = (string) self::year($request, true);
        $file = $this->export->build($principal->tenantId, $establishmentId, $year);
        $this->access->audit($principal, 'secondary.carte_scolaire.year_end_exported', 'academic_year', $year, null, ['rows' => $file['rows'], 'missing_national_ids' => $file['missing_national_ids']]);

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="conseils-fin-annee-' . preg_replace('/[^A-Za-z0-9_-]/', '', $year) . '.csv"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Rows', (string) $file['rows'])
            ->withHeader('X-Missing-National-Ids', (string) $file['missing_national_ids'])
            ->withHeader('X-Missing-Results', (string) $file['missing_results'])
            ->withBody($this->streams->createStream($file['csv']));
    }

    private static function year(ServerRequestInterface $request, bool $required): ?string
    {
        $value = $request->getQueryParams()['academic_year_id'] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw HttpException::validation(['academic_year_id' => ['Année scolaire obligatoire.']]);
            }

            return null;
        }
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $value) !== 1) {
            throw HttpException::validation(['academic_year_id' => ['Identifiant invalide.']]);
        }

        return $value;
    }
}
