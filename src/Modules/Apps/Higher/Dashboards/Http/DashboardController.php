<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Dashboards\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Academic\Application\Access;
use Scholaris\Modules\Apps\Higher\Dashboards\Application\DashboardAccess;
use Scholaris\Modules\Apps\Higher\Dashboards\Application\DashboardClock;
use Scholaris\Modules\Apps\Higher\Dashboards\Application\DashboardService;
use Scholaris\Modules\Apps\Higher\Dashboards\Domain\Board;
use Scholaris\Modules\Apps\Higher\Dashboards\Domain\Columns;
use Scholaris\Modules\Apps\Higher\Dashboards\Domain\SmallCells;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;

/**
 * Tableaux de bord de l'IPES (PIL) : GET /higher/dashboards/{kind} (cartes et tableaux d'agrégats) et .../export (CSV ou XLSX d'un
 * tableau). Filtres en paramètres de requête : school_id (sélecteur, RG-ORG-3), program_id, academic_year_id, from, to (AAAA-MM-JJ).
 * Lecture seule : aucune écriture, donc aucune porte WriteGate.
 */
final class DashboardController
{
    use ExportsList;

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly DashboardAccess $access,
        private readonly DashboardService $service,
        private readonly Access $audit,
        private readonly DashboardClock $clock,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $kind, $filter, $school] = $this->context($request);
        $perimeter = $this->access->perimeter($principal, $establishmentId, $kind, $school);
        $built = $this->service->build($kind, $principal->tenantId, $establishmentId, $perimeter, $filter);
        $schools = $this->access->selectableSchools($principal, $establishmentId);

        return $this->responder->json(['data' => [
            'board' => $kind,
            'generated_at' => $this->clock->now(),
            'filters' => ['school_id' => $school ?? 'all', 'program_id' => $filter->programId, 'academic_year_id' => $filter->academicYearId, 'from' => $filter->from, 'to' => $filter->to],
            'schools' => $schools,
            'school_selector' => $schools !== [],
            'suppressed_below' => SmallCells::MINIMUM,
        ] + $built]);
    }

    /** GET /higher/dashboards/{kind}/export?table=<clé>&format=csv|xlsx : un tableau, mêmes filtres et mêmes masquages que l'écran. */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $kind, $filter, $school] = $this->context($request);
        $format = self::exportFormat($request);
        $perimeter = $this->access->perimeter($principal, $establishmentId, $kind, $school);
        $built = $this->service->build($kind, $principal->tenantId, $establishmentId, $perimeter, $filter);
        $wanted = $request->getQueryParams()['table'] ?? null;
        $table = null;
        foreach ($built['tables'] as $candidate) {
            if ($wanted === null || $wanted === '' || $candidate['key'] === $wanted) {
                $table = $candidate;
                break;
            }
        }
        if ($table === null) {
            throw HttpException::validation(['table' => ['Tableau inconnu pour ce tableau de bord.']]);
        }
        /** @var list<string> $columns */
        $columns = $table['columns'];
        $rows = [];
        /** @var list<array<string, mixed>> $tableRows */
        $tableRows = $table['rows'];
        foreach ($tableRows as $row) {
            $rows[] = array_map(static fn (string $column): mixed => $row[$column] ?? '', $columns);
        }
        $this->audit->audit($principal, 'higher.dashboard.exported', 'higher_dashboard', $kind, null, ['table' => $table['key'], 'format' => $format, 'rows' => count($rows)]);

        return $this->csvResponse(
            $this->responses,
            $this->streams,
            'scholaris-' . $kind . '-' . $table['key'] . '.csv',
            array_map(Columns::label(...), $columns),
            $rows,
            $request,
        );
    }

    /** @return array{0: \Scholaris\Core\Security\Principal, 1: string, 2: string, 3: StatisticsFilter, 4: ?string} */
    private function context(ServerRequestInterface $request): array
    {
        $kind = $request->getAttribute('kind');
        if (!is_string($kind) || !in_array($kind, Board::keys(), true)) {
            throw HttpException::notFound('Tableau de bord inconnu.');
        }
        $query = $request->getQueryParams();
        $errors = [];
        $text = static function (string $name, string $pattern) use ($query, &$errors): ?string {
            $value = $query[$name] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                $errors[$name] = ['Valeur invalide.'];

                return null;
            }

            return $value;
        };
        $id = '/^[A-Za-z0-9_-]{1,40}$/';
        $date = '/^\d{4}-\d{2}-\d{2}$/';
        $filter = new StatisticsFilter($text('program_id', $id), $text('academic_year_id', $id), $text('from', $date), $text('to', $date));
        $school = $text('school_id', $id);
        if ($filter->from !== null && $filter->to !== null && $filter->from > $filter->to) {
            $errors['to'] = ['La fin de période précède le début.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [Access::principal($request), Access::establishmentId($request), $kind, $filter, $school];
    }
}
