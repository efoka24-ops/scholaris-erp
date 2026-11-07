<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Staff\Application\StaffAccess;
use Scholaris\Modules\Socle\Staff\Application\StatementService;
use Scholaris\Modules\Socle\Staff\Http\RequestContext;
use Scholaris\Modules\Socle\Staff\Http\Requests\Input;
use Scholaris\Modules\Socle\Staff\Http\Resources\Resource;
use Scholaris\Modules\Socle\Staff\Http\Resources\StaffResources;

/** Relevés mensuels de vacations : calcul, validation à deux personnes, état de paiement. */
final class StatementController extends BaseController
{
    private readonly Resource $resource;
    private readonly Resource $hourResource;

    public function __construct(JsonResponder $responder, RequestContext $context, StaffAccess $access, private readonly StatementService $statements)
    {
        parent::__construct($responder, $context, $access);
        $this->resource = StaffResources::statement();
        $this->hourResource = StaffResources::hour();
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$rows, $next] = $this->statements->search(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            $this->query($request, ['staff_id', 'status', 'period']),
            $this->vacationsScope($request),
        );

        return $this->page($request, $this->resource->collection($rows), $next);
    }

    /** Export des relevés de vacations (mêmes filtres et même visibilité que la liste, plafonné). */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->vacationsScope($request);
        $rows = $this->collectAll(
            $request,
            ['staff_id', 'status', 'period'],
            fn (ListQuery $query): array => $this->statements->search($this->context->tenantId(), $this->context->establishmentId(), $query, $scope),
        );
        $resource = $this->resource;

        return $this->csv(
            'releves-vacations.csv',
            ['Identifiant', 'Personnel', 'Contrat', 'Période', 'Minutes', 'HETD (millièmes)', 'Taux horaire', 'Montant brut', 'Statut', 'Validé par', 'Validé le', 'Payé le', 'Référence de paiement'],
            array_map(static function (array $row) use ($resource): array {
                $r = $resource->toArray($row);

                return [$r['id'], $r['staff_id'], $r['contract_id'], $r['period'], $r['minutes_total'], $r['hetd_milli'], $r['hourly_rate'], $r['gross_amount'], $r['status'], $r['validated_by'], $r['validated_at'], $r['paid_on'], $r['payment_reference']];
            }, $rows),
            $request,
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->statements->get($this->context->tenantId(), $this->context->establishmentId(), $this->param($request, 'id'));
        $this->requireVacationsView($request, (string) $row['staff_id'], 'Relevé de vacations introuvable.');
        $out = $this->resource->toArray($row);
        $out['lines'] = $this->hourResource->collection($this->statements->lines($this->context->tenantId(), (string) $row['id']));

        return $this->ok(['data' => $out]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->access->requireVacationsManage($principal, $this->context->establishmentId());
        $data = Input::validate(Input::body($request), ['staff_id' => Input::ref(), 'period' => Input::month()], ['staff_id', 'period']);
        $row = $this->resource->toArray($this->statements->generate(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            (string) $data['staff_id'],
            (string) $data['period'],
            $principal->userId,
        ));
        $this->context->audit($request, 'vacation_statement.generated', 'vacation_statement', (string) $row['id'], null, $row);

        return $this->ok(['data' => $row], 201);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->access->requireVacationsManage($principal, $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $row = $this->resource->toArray($this->statements->submit($this->context->tenantId(), $this->context->establishmentId(), $id, $principal->userId));
        $this->context->audit($request, 'vacation_statement.submitted', 'vacation_statement', $id, null, $row);

        return $this->ok(['data' => $row]);
    }

    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->access->requireValidate($principal, $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $row = $this->resource->toArray($this->statements->approve($this->context->tenantId(), $this->context->establishmentId(), $id, $principal->userId));
        $this->context->audit($request, 'vacation_statement.validated', 'vacation_statement', $id, null, $row);

        return $this->ok(['data' => $row]);
    }

    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->access->requireValidate($principal, $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $row = $this->resource->toArray($this->statements->reject($this->context->tenantId(), $this->context->establishmentId(), $id, $principal->userId));
        $this->context->audit($request, 'vacation_statement.rejected', 'vacation_statement', $id, null, $row);

        return $this->ok(['data' => $row]);
    }

    public function pay(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->requireVacationsManage($this->principal($request), $this->context->establishmentId());
        $id = $this->param($request, 'id');
        $data = Input::validate(Input::body($request), ['paid_on' => Input::date(), 'payment_reference' => Input::text(80)], ['paid_on', 'payment_reference']);
        $before = $this->resource->toArray($this->statements->get($this->context->tenantId(), $this->context->establishmentId(), $id));
        $row = $this->resource->toArray($this->statements->pay($this->context->tenantId(), $this->context->establishmentId(), $id, (string) $data['paid_on'], (string) $data['payment_reference']));
        $this->context->audit($request, 'vacation_statement.paid', 'vacation_statement', $id, $before, $row);

        return $this->ok(['data' => $row]);
    }
}
