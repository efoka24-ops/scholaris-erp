<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Staff\Application\ContractService;
use Scholaris\Modules\Socle\Staff\Application\RateService;
use Scholaris\Modules\Socle\Staff\Application\StaffAccess;
use Scholaris\Modules\Socle\Staff\Http\RequestContext;
use Scholaris\Modules\Socle\Staff\Http\Requests\Input;
use Scholaris\Modules\Socle\Staff\Http\Resources\Resource;
use Scholaris\Modules\Socle\Staff\Http\Resources\StaffResources;

/** Contrats de vacation et taux par catégorie. L'activation d'un contrat passe par une validation à deux personnes. */
final class ContractController extends BaseController
{
    private readonly Resource $resource;
    private readonly Resource $rateResource;

    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        StaffAccess $access,
        private readonly ContractService $contracts,
        private readonly RateService $rates,
    ) {
        parent::__construct($responder, $context, $access);
        $this->resource = StaffResources::contract();
        $this->rateResource = StaffResources::rate();
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$rows, $next] = $this->contracts->search(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $this->query($request, ['staff_id', 'status']),
            $this->vacationsScope($request),
        );

        return $this->page($request, $this->resource->collection($rows), $next);
    }

    /** Export des contrats de vacation (mêmes filtres et même visibilité que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->vacationsScope($request);
        $rows = $this->collectAll(
            $request,
            ['staff_id', 'status'],
            fn (ListQuery $query): array => $this->contracts->search($this->context->tenantId(), $this->context->establishmentId(), $query, $scope),
        );
        $resource = $this->resource;

        return $this->csv(
            'contrats-vacation.csv',
            ['Identifiant', 'Personnel', 'Référence', 'Début', 'Fin', 'Taux horaire', 'Minutes maximum', 'Statut', 'Créé le', 'Modifié le'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['staff_id'], $r['reference'], $r['starts_on'], $r['ends_on'], $r['hourly_rate'], $r['max_minutes'], $r['status'], $r['created_at'], $r['updated_at']];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->contracts->get($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));
        $this->requireVacationsView($request, (string) $row['staff_id'], 'Contrat de vacation introuvable.');

        return $this->ok(['data' => $this->resource->toArray($row)]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->access->requireVacationsManage($principal, $this->context->establishmentId());
        $data = Input::validate(Input::body($request), [
            'staff_id' => Input::ref(), 'starts_on' => Input::date(), 'ends_on' => Input::date(),
            'hourly_rate' => Input::int(1, 10000000), 'max_minutes' => Input::int(1, 1000000),
        ], ['staff_id', 'starts_on', 'ends_on'], ['hourly_rate', 'max_minutes']);
        $row = $this->resource->toArray($this->contracts->create($this->context->tenantId(), $this->context->establishmentId(), $data, $principal->userId));
        $this->context->audit($request, 'vacation_contract.created', 'vacation_contract', (string) $row['id'], null, $row);

        return $this->ok(['data' => $row], 201);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->requireVacationsManage($this->principal($request), $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $changes = Input::validate(Input::body($request), [
            'starts_on' => Input::date(), 'ends_on' => Input::date(),
            'hourly_rate' => Input::int(1, 10000000), 'max_minutes' => Input::int(1, 1000000),
        ], [], ['hourly_rate', 'max_minutes'], true);
        $before = $this->resource->toArray($this->contracts->get($this->context->tenantId(), $this->context->establishmentId(), $id));
        $row = $this->resource->toArray($this->contracts->update($this->context->tenantId(), $this->context->establishmentId(), $id, $changes));
        $this->context->audit($request, 'vacation_contract.updated', 'vacation_contract', $id, $before, $row);

        return $this->ok(['data' => $row]);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->access->requireVacationsManage($principal, $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $row = $this->resource->toArray($this->contracts->submit($this->context->tenantId(), $this->context->establishmentId(), $id, $principal->userId));
        $this->context->audit($request, 'vacation_contract.submitted', 'vacation_contract', $id, null, $row);

        return $this->ok(['data' => $row]);
    }

    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->access->requireValidate($principal, $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $row = $this->resource->toArray($this->contracts->approve($this->context->tenantId(), $this->context->establishmentId(), $id, $principal->userId));
        $this->context->audit($request, 'vacation_contract.approved', 'vacation_contract', $id, null, $row);

        return $this->ok(['data' => $row]);
    }

    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->access->requireValidate($principal, $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $row = $this->resource->toArray($this->contracts->reject($this->context->tenantId(), $this->context->establishmentId(), $id, $principal->userId));
        $this->context->audit($request, 'vacation_contract.rejected', 'vacation_contract', $id, null, $row);

        return $this->ok(['data' => $row]);
    }

    public function terminate(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->requireVacationsManage($this->principal($request), $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $row = $this->resource->toArray($this->contracts->terminate($this->context->tenantId(), $this->context->establishmentId(), $id));
        $this->context->audit($request, 'vacation_contract.terminated', 'vacation_contract', $id, null, $row);

        return $this->ok(['data' => $row]);
    }

    public function rates(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        if (!$this->access->hasVacationsRead($principal, $this->context->establishmentId())) {
            $this->access->requireVacationsManage($principal, $this->context->establishmentId());
        }

        return $this->ok(['data' => $this->rateResource->collection($this->rates->all($this->context->tenantId(), $this->context->establishmentId()))]);
    }

    public function putRate(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->requireVacationsManage($this->principal($request), $this->context->establishmentId());
        $data = Input::validate(Input::body($request), [
            'category' => Input::text(40), 'level' => Input::text(40), 'hourly_rate' => Input::int(1, 10000000), 'monthly_cap_minutes' => Input::int(1, 100000),
        ], ['category', 'hourly_rate'], ['monthly_cap_minutes', 'level']);
        $before = $this->rates->forCategory($this->context->tenantId(), $this->context->establishmentId(), (string) $data['category'], isset($data['level']) ? (string) $data['level'] : null);
        $row = $this->rateResource->toArray($this->rates->put($this->context->tenantId(), $this->context->establishmentId(), $data));
        $this->context->audit($request, 'vacation_rate.set', 'vacation_rate', (string) $row['id'], $before === null ? null : $this->rateResource->toArray($before), $row);

        return $this->ok(['data' => $row]);
    }
}
