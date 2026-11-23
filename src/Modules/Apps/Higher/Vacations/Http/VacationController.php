<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Apps\Higher\Teaching\Contracts\TaughtSessions;
use Scholaris\Modules\Apps\Higher\Vacations\Application\StatementService;
use Scholaris\Modules\Apps\Higher\Vacations\Application\VacationAccess;
use Scholaris\Modules\Apps\Higher\Vacations\Application\VacationPermissions;
use Scholaris\Modules\Apps\Higher\Vacations\Infrastructure\VacationStore;

/** États mensuels des vacations : listes du périmètre, « Mes vacations », état détaillé, établissement de l'état (EX-ENS-06). */
final class VacationController
{
    private const FILTERS = ['period_month', 'status', 'person_id', 'department_id', 'school_id'];
    private const MONTH = '/^\d{4}-(0[1-9]|1[0-2])$/';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly VacationAccess $access,
        private readonly VacationStore $store,
        private readonly StatementService $statements,
        private readonly TaughtSessions $sessions,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = VacationAccess::principal($request);
        $establishmentId = VacationAccess::establishmentId($request);
        $scope = $this->access->sqlFilter($this->access->require($principal, $establishmentId, VacationPermissions::READ, VacationAccess::selectedSchool($request)));

        return $this->responder->json($this->page($request, $principal->tenantId, $establishmentId, $scope));
    }

    public function mine(ServerRequestInterface $request): ResponseInterface
    {
        $principal = VacationAccess::principal($request);
        $establishmentId = VacationAccess::establishmentId($request);
        $this->access->require($principal, $establishmentId, VacationPermissions::READ_OWN);
        $me = $this->access->personOf($principal) ?? throw HttpException::forbidden('Aucune personne liée à ce compte.');

        return $this->responder->json($this->page($request, $principal->tenantId, $establishmentId, ['person_id = ?', [$me]]));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(VacationResources::statement($this->statements->readable(VacationAccess::principal($request), VacationAccess::establishmentId($request), self::arg($request))));
    }

    public function toPrepare(ServerRequestInterface $request): ResponseInterface
    {
        $principal = VacationAccess::principal($request);
        $establishmentId = VacationAccess::establishmentId($request);
        $perimeter = $this->access->require($principal, $establishmentId, VacationPermissions::PREPARE);
        $month = (string) ($request->getQueryParams()['period_month'] ?? '');
        if (preg_match(self::MONTH, $month) !== 1) {
            throw HttpException::validation(['period_month' => ['Mois AAAA-MM attendu.']]);
        }
        $rows = [];
        foreach ($this->sessions->teachersWithValidatedHours($principal->tenantId, $establishmentId, $month) as $row) {
            if (!$this->access->covers($perimeter, $row)) {
                continue;
            }
            $existing = $this->store->select(StatementService::TABLE, $principal->tenantId, $establishmentId, ['person_id' => $row['person_id'], 'department_id' => $row['department_id'], 'period_month' => $month], 'id', 1)[0] ?? null;
            $rows[] = $row + ['period_month' => $month, 'statement_id' => $existing['id'] ?? null, 'statement_status' => $existing['status'] ?? null];
        }

        return $this->responder->json(['data' => $rows]);
    }

    public function prepare(ServerRequestInterface $request): ResponseInterface
    {
        $principal = VacationAccess::principal($request);
        $establishmentId = VacationAccess::establishmentId($request);
        $this->access->require($principal, $establishmentId, VacationPermissions::PREPARE);
        $body = VacationBody::of($request);
        $person = $body->string('person_id', 40);
        $month = $body->string('period_month', 7, true, self::MONTH);
        $department = $body->string('department_id', 40, false);
        $body->done(['person_id', 'period_month', 'department_id']);

        $rows = $this->statements->prepare($principal, $establishmentId, (string) $person, (string) $month, $department);

        return $this->responder->json(['data' => array_map(VacationResources::statement(...), $rows)], 201);
    }

    /**
     * @param array{0: string, 1: list<string>} $scope
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    private function page(ServerRequestInterface $request, string $tenantId, string $establishmentId, array $scope): array
    {
        $query = ListQuery::fromRequest($request, self::FILTERS);
        $decoded = ListQuery::decodeCursor($query->cursor);
        $offset = $decoded !== null && ctype_digit($decoded) ? (int) $decoded : 0;
        $rows = $this->store->select(StatementService::TABLE, $tenantId, $establishmentId, $query->filters, 'period_month DESC, person_id, id', $query->perPage + 1, $scope[0], $scope[1], $offset);
        $next = null;
        if (count($rows) > $query->perPage) {
            array_pop($rows);
            $next = ListQuery::encodeCursor((string) ($offset + $query->perPage));
        }

        return ['data' => array_map(VacationResources::statement(...), $rows), 'meta' => ['next_cursor' => $next, 'per_page' => $query->perPage]];
    }

    public static function arg(ServerRequestInterface $request, string $name = 'id'): string
    {
        $value = $request->getAttribute($name);
        if (!is_string($value) || $value === '') {
            throw HttpException::notFound();
        }

        return $value;
    }
}
