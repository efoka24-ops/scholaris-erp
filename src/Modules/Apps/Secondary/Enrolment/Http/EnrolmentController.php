<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Apps\Secondary\Enrolment\Application\EnrolmentService;
use Scholaris\Modules\Apps\Secondary\Enrolment\Application\PreregistrationService;
use Scholaris\Modules\Apps\Secondary\Enrolment\Application\ReenrolmentService;
use Scholaris\Modules\Apps\Secondary\Enrolment\Application\StudentImportService;
use Scholaris\Modules\Apps\Secondary\Enrolment\Application\TransferService;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Inscriptions du secondaire (T824 à T827, API-01, API-02) : inscription, dossier, matricule, liste à régulariser, préinscriptions,
 * fichier du primaire, réinscription, transferts, import des élèves (Excel ou CSV). Listes paginées par curseur.
 */
final class EnrolmentController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly EnrolmentService $enrolment,
        private readonly PreregistrationService $preregistrations,
        private readonly ReenrolmentService $reenrolment,
        private readonly TransferService $transfers,
        private readonly StudentImportService $imports,
        private readonly Listing $listing,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function enrol(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->enrolment->enrol($principal, $establishmentId, Input::body($request)), 201);
    }

    public function students(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $rows = $this->enrolment->students($principal, $establishmentId, self::query($request, 'academic_year_id'), self::query($request, 'class_structure_id'), self::query($request, 'pending') === '1');

        return $this->page(self::slice($request, $rows));
    }

    public function student(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->enrolment->student($principal, $establishmentId, self::id($request)));
    }

    public function nationalId(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->enrolment->setNationalId($principal, $establishmentId, self::id($request), Input::body($request)));
    }

    public function preregistrations(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, EnrolmentService::READ);
        $map = static fn (array $row): array => PreregistrationService::resource($row);

        return $this->page($this->listing->page($request, 'se_preregistrations', $principal->tenantId, $establishmentId, ['status', 'channel', 'target_level_code'], ['created_at', 'last_name'], 'created_at', $map));
    }

    public function createPreregistration(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->preregistrations->create($principal, $establishmentId, Input::body($request)), 201);
    }

    public function updatePreregistration(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->preregistrations->update($principal, $establishmentId, self::id($request), Input::body($request)));
    }

    public function convertPreregistration(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->preregistrations->convert($principal, $establishmentId, self::id($request), Input::body($request)), 201);
    }

    public function preregistrationSlip(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->preregistrations->slip($principal, $establishmentId, self::id($request), Input::body($request)), 201);
    }

    public function primaryFile(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $result = $this->preregistrations->importPrimaryFile($principal, $establishmentId, Input::body($request));

        return $this->json($result, $result['duplicate'] ? 200 : 201);
    }

    public function reenrolmentCandidates(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $from = self::query($request, 'from_academic_year_id') ?? throw HttpException::validation(['from_academic_year_id' => ['Année source obligatoire.']]);
        $rows = $this->reenrolment->candidates($principal, $establishmentId, $from, self::query($request, 'academic_year_id'));

        return $this->page(self::slice($request, $rows));
    }

    public function reenrol(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->reenrolment->reenrol($principal, $establishmentId, Input::body($request)), 201);
    }

    public function transfers(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, EnrolmentService::READ);
        $map = static fn (array $row): array => TransferService::resource($row);

        return $this->page($this->listing->page($request, 'se_transfers', $principal->tenantId, $establishmentId, ['direction', 'status', 'person_id'], ['created_at'], 'created_at', $map));
    }

    public function transferOut(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->transfers->transferOut($principal, $establishmentId, Input::body($request)), 201);
    }

    public function transferIn(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->transfers->transferIn($principal, $establishmentId, Input::body($request)), 201);
    }

    public function importTemplate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->imports->template($principal, $establishmentId));
    }

    public function import(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $report = $this->imports->run($principal, $establishmentId, Input::body($request));

        return $this->json($report, $report['dry_run'] === true ? 200 : 201);
    }

    /**
     * Pagination par curseur d'une liste calculée (positions), même forme que Listing.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    private static function slice(ServerRequestInterface $request, array $rows): array
    {
        $query = ListQuery::fromRequest($request);
        $decoded = ListQuery::decodeCursor($query->cursor);
        $offset = $decoded !== null && ctype_digit($decoded) ? (int) $decoded : 0;
        $page = array_slice($rows, $offset, $query->perPage);
        $next = count($rows) > $offset + $query->perPage ? ListQuery::encodeCursor((string) ($offset + $query->perPage)) : null;

        return ['data' => $page, 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]];
    }

    private static function query(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_string($value) && $value !== '' && strlen($value) <= 40 ? $value : null;
    }
}
