<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\People\Application\AccessGuard;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\People\Application\RelationService;
use Scholaris\Modules\Socle\People\Domain\RelationKind;
use Scholaris\Modules\Socle\People\Http\Requests\Input;
use Scholaris\Modules\Socle\People\Http\Resources\SimpleResources;

final class RelationController extends BaseController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AccessGuard $guard,
        PersonService $people,
        private readonly RelationService $relations,
    ) {
        parent::__construct($responder, $context, $guard, $people);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->visiblePerson($request, $id);

        return $this->listing(SimpleResources::relation()->collection($this->relations->forPerson($this->context->tenantId(), $id)));
    }

    /** Corps : related_person_id est <kind> de la personne de l'URL. */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->managedPerson($request, $id);
        $data = Input::validate(
            Input::body($request),
            ['related_person_id' => Input::ref(), 'kind' => Input::enum(RelationKind::values())],
            ['related_person_id', 'kind'],
        );
        $row = $this->relations->add(
            $this->context->tenantId(),
            $id,
            (string) $data['related_person_id'],
            RelationKind::from((string) $data['kind']),
            $this->principal($request)->userId,
        );
        $out = ['id' => $row['id'], 'person_id' => $row['person_id'], 'related_person_id' => $row['related_person_id'], 'kind' => $row['kind']];
        $this->context->audit($request, 'person.relation_added', 'person', $id, null, $out);

        return $this->ok(['data' => $out], 201);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->managedPerson($request, $id);
        $row = $this->relations->remove($this->context->tenantId(), $id, $this->param($request, 'relationId'));
        $this->context->audit($request, 'person.relation_removed', 'person', $id, [
            'id' => $row['id'], 'person_id' => $row['person_id'], 'related_person_id' => $row['related_person_id'], 'kind' => $row['kind'],
        ], null);

        return $this->noContent();
    }
}
