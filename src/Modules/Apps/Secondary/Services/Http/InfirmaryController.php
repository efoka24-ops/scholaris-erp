<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Services\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Apps\Secondary\Services\Application\Infirmary;
use Scholaris\Modules\Apps\Secondary\Services\Domain\ServicesDefaults;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Listing;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Infirmerie [infirmary] (T973) : passages et dossier de santé réservés à l'infirmier (403 pour tout autre rôle), chaque lecture
 * journalisée ; journal des consultations lisible par l'infirmier et la direction ; dispenses d'EPS du jour, sans motif.
 */
final class InfirmaryController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly Infirmary $infirmary,
        private readonly Listing $listing,
        private readonly SecondaryClock $clock,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function visits(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->infirmary->requireAny($principal, $establishmentId, Infirmary::NURSE);
        $filters = ListQuery::fromRequest($request, ['person_id', 'class_id', 'reason_code'], ['visited_at'])->filters;
        $this->infirmary->beforeList($principal, $establishmentId, isset($filters['person_id']) ? (string) $filters['person_id'] : null);

        return $this->page($this->listing->page(
            $request,
            Infirmary::VISITS,
            $principal->tenantId,
            $establishmentId,
            ['person_id', 'class_id', 'reason_code'],
            ['visited_at'],
            'visited_at DESC',
            fn (array $row): array => $this->infirmary->visitView($row),
        ));
    }

    public function storeVisit(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->infirmary->requireAny($principal, $establishmentId, Infirmary::NURSE);
        $input = Input::of($request);
        $input->reference('person_id');
        $input->custom('visited_at', static function (mixed $v): string {
            if (!is_string($v) || \DateTimeImmutable::createFromFormat(DATE_ATOM, $v) === false) {
                throw new \InvalidArgumentException('Date et heure ISO 8601 attendues (AAAA-MM-JJTHH:MM:SS+01:00).');
            }

            return $v;
        }, false);
        $input->enum('reason_code', ServicesDefaults::REASONS);
        $input->enum('outcome', ServicesDefaults::OUTCOMES);
        $input->bool('parent_called');
        $input->string('notes', ServicesDefaults::MAX_NOTES, false, true);
        $input->date('pe_exempt_until', false, true);
        $data = $input->done();

        return $this->json($this->infirmary->recordVisit($principal, $establishmentId, $data), 201);
    }

    public function visit(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->infirmary->visit($principal, $establishmentId, self::id($request)));
    }

    public function record(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->infirmary->record($principal, $establishmentId, self::id($request, 'personId')));
    }

    public function saveRecord(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->infirmary->requireAny($principal, $establishmentId, Infirmary::NURSE);
        $input = Input::of($request);
        foreach (['allergies', 'conditions', 'treatments', 'emergency_instructions'] as $field) {
            $input->string($field, ServicesDefaults::MAX_RECORD_FIELD, false, true);
        }
        /** @var array<string, string|null> $fields */
        $fields = $input->done();

        return $this->json($this->infirmary->saveRecord($principal, $establishmentId, self::id($request, 'personId'), $fields));
    }

    public function accessLog(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->infirmary->requireAny($principal, $establishmentId, Infirmary::LOG_READERS);
        $names = [];

        return $this->page($this->listing->page(
            $request,
            Infirmary::LOG,
            $principal->tenantId,
            $establishmentId,
            ['person_id', 'user_id', 'access_kind'],
            ['created_at'],
            'created_at DESC',
            function (array $row) use (&$names): array {
                return $this->infirmary->logView($row, $names);
            },
        ));
    }

    public function exemptions(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->infirmary->requireAny($principal, $establishmentId, Infirmary::EXEMPTION_READERS);
        $params = $request->getQueryParams();
        $date = is_string($params['date'] ?? null) ? $params['date'] : $this->clock->today();
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw HttpException::validation(['date' => ['Date au format AAAA-MM-JJ attendue.']]);
        }
        $persons = [];
        foreach ($this->store->select(Infirmary::VISITS, $principal->tenantId, $establishmentId, [], 'person_id', 10000, 'pe_exempt_from IS NOT NULL AND pe_exempt_from <= ? AND pe_exempt_until >= ?', [$date, $date]) as $row) {
            $persons[(string) $row['person_id']] = ['class_id' => $row['class_id']];
        }
        $until = $this->infirmary->exemptOn($principal->tenantId, $establishmentId, array_keys($persons), $date);
        $out = [];
        foreach ($until as $personId => $last) {
            $out[] = ['person_id' => $personId, 'class_id' => $persons[$personId]['class_id'], 'exempt_until' => $last];
        }

        return $this->json(['date' => $date, 'exemptions' => $out]);
    }
}
