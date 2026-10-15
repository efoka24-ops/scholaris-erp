<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\People\Application\AccessGuard;
use Scholaris\Modules\Socle\People\Application\PeopleClock;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\People\Domain\DuplicateMatcher;
use Scholaris\Modules\Socle\People\Domain\PersonAttributes;
use Scholaris\Modules\Socle\People\Http\Requests\Input;
use Scholaris\Modules\Socle\People\Http\Resources\PersonResource;

final class PeopleController extends BaseController
{
    use ExportsList;

    private const EXPORT_LIMIT = 5000;

    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AccessGuard $guard,
        PersonService $people,
        private readonly PeopleClock $clock,
        private readonly PersonResource $resource,
    ) {
        parent::__construct($responder, $context, $guard, $people);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $this->query($request, ['q', 'phone', 'email', 'national_id', 'last_name', 'birth_date']);
        [$rows, $next] = $this->people->search($this->context->tenantId(), $query, $this->visibleScope($request));

        return $this->page($request, $this->resource->collection($rows), $next);
    }

    /** Export CSV de la liste des personnes (mêmes filtres et mêmes personnes visibles que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->visibleScope($request);
        $rows = [];
        $cursor = null;
        do {
            $query = $this->query($this->exportPage($request, $cursor), ['q', 'phone', 'email', 'national_id', 'last_name', 'birth_date']);
            [$page, $cursor] = $this->people->search($this->context->tenantId(), $query, $scope);
            foreach ($page as $row) {
                $rows[] = $this->resource->toArray($row);
            }
        } while ($cursor !== null && count($rows) < self::EXPORT_LIMIT);

        return $this->csvResponse(
            new Psr17Factory(),
            new Psr17Factory(),
            'personnes.csv',
            ['Identifiant', 'Prénom', 'Nom', 'Date de naissance', 'Lieu de naissance', 'Genre', 'Nationalité', 'Téléphone', 'E-mail', 'Langue', 'Identifiant national', 'Créée le', 'Modifiée le'],
            array_map(static fn (array $r): array => [
                $r['id'], $r['first_name'], $r['last_name'], $r['birth_date'], $r['birth_place'], $r['gender'], $r['nationality'],
                $r['phone'], $r['email'], $r['locale'], $r['national_id'], $r['created_at'], $r['updated_at'],
            ], array_slice($rows, 0, self::EXPORT_LIMIT)),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->ok(['data' => $this->resource->toArray($this->visiblePerson($request, $this->param($request, 'id')))]);
    }

    public function duplicates(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->requireManage($this->principal($request), $this->context->establishmentId());
        $params = array_filter($request->getQueryParams(), 'is_string');
        [$values, $errors] = PersonAttributes::validate(array_intersect_key($params, array_flip(['first_name', 'last_name', 'birth_date', 'phone'])), $this->clock->today());
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $this->ok(['data' => $this->candidates($this->people->duplicates($this->context->tenantId(), $values))]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->requireManage($this->principal($request), $this->context->establishmentId());
        $data = Input::body($request);
        $confirm = false;
        if (array_key_exists('confirm_different', $data)) {
            if (!is_bool($data['confirm_different'])) {
                throw HttpException::validation(['confirm_different' => ['Booléen attendu (true ou false).']]);
            }
            $confirm = $data['confirm_different'];
            unset($data['confirm_different']);
        }
        $values = $this->validated($data, false);
        $tenantId = $this->context->tenantId();

        $duplicates = $this->people->duplicates($tenantId, $values);
        if ($duplicates !== [] && !$confirm) {
            return $this->problem(
                $request,
                409,
                'Personne probablement déjà enregistrée',
                'Une personne existe peut-être déjà dans le groupe. Reprenez sa fiche, ou confirmez qu\'il s\'agit d\'une personne différente (confirm_different).',
                'duplicate-person',
                ['duplicates' => $this->candidates($duplicates)],
            );
        }

        $person = $this->people->create($tenantId, $values);
        $after = $this->resource->toArray($person);
        $this->context->audit($request, 'person.created', 'person', (string) $person['id'], null, $after);
        if ($duplicates !== []) {
            $this->context->audit($request, 'person.duplicate_overridden', 'person', (string) $person['id'], null, [
                'confirmed_different_from' => array_map(static fn (array $d): mixed => $d['person']['id'], $duplicates),
            ]);
        }

        return $this->ok(['data' => $after], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $before = $this->managedPerson($request, $id);
        $values = $this->validated(Input::body($request), true);
        $person = $this->people->update($this->context->tenantId(), $id, $values);
        $after = $this->resource->toArray($person);
        $this->context->audit($request, 'person.updated', 'person', $id, $this->resource->toArray($before), $after);

        return $this->ok(['data' => $after]);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $before = $this->managedPerson($request, $id);
        $this->people->delete($this->context->tenantId(), $id);
        $this->context->audit($request, 'person.deleted', 'person', $id, $this->resource->toArray($before), null);

        return $this->noContent();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validated(array $data, bool $partial): array
    {
        $unknown = array_diff(array_keys($data), PersonAttributes::FIELDS);
        [$values, $errors] = PersonAttributes::validate(array_diff_key($data, array_flip($unknown)), $this->clock->today(), $partial);
        foreach ($unknown as $field) {
            $errors[(string) $field] = ['Champ non autorisé.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $values;
    }

    /**
     * @param list<array{person: array<string, mixed>, criteria: list<string>, history: list<string>}> $duplicates
     * @return list<array<string, mixed>>
     */
    private function candidates(array $duplicates): array
    {
        return array_map(fn (array $d): array => [
            'person' => $this->resource->toArray($d['person']),
            'criteria' => $d['criteria'],
            'criteria_matched' => count($d['criteria']),
            'criteria_total' => DuplicateMatcher::TOTAL,
            'label' => sprintf('%d critères sur %d', count($d['criteria']), DuplicateMatcher::TOTAL),
            'history' => $d['history'],
        ], $duplicates);
    }
}
