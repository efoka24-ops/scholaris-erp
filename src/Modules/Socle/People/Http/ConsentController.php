<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\People\Application\AccessGuard;
use Scholaris\Modules\Socle\People\Application\ConsentService;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\People\Http\Requests\Input;
use Scholaris\Modules\Socle\People\Http\Resources\SimpleResources;

/** Consentements : donnés par la personne, son responsable (mineur) ou un gestionnaire ; révocables, jamais supprimés. */
final class ConsentController extends BaseController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AccessGuard $guard,
        PersonService $people,
        private readonly ConsentService $consents,
    ) {
        parent::__construct($responder, $context, $guard, $people);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $this->query($request, ['person_id', 'purpose']);
        $personId = $query->filters['person_id'] ?? null;
        if ($personId === null) {
            throw HttpException::validation(['filter[person_id]' => ['Indiquez la personne : filter[person_id]=...']]);
        }
        $this->visiblePerson($request, $personId);

        return $this->listing(SimpleResources::consent()->collection(
            $this->consents->forPerson($this->context->tenantId(), $personId, $query->filters['purpose'] ?? null),
        ));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $data = Input::validate(
            Input::body($request),
            ['person_id' => Input::ref(), 'purpose' => Input::enum(ConsentService::PURPOSES), 'evidence_ref' => Input::ref()],
            ['person_id', 'purpose'],
            ['evidence_ref'],
        );
        $personId = (string) $data['person_id'];
        $this->authorizeFor($request, $personId);
        $row = $this->consents->grant($this->context->tenantId(), $personId, (string) $data['purpose'], $this->principal($request)->userId, $data['evidence_ref'] ?? null);
        $out = SimpleResources::consent()->toArray($row);
        $this->context->audit($request, 'consent.granted', 'consent', (string) $row['id'], null, $out);

        return $this->ok(['data' => $out], 201);
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $consent = $this->consents->get($this->context->tenantId(), $this->param($request, 'id'));
        $this->authorizeFor($request, (string) $consent['person_id']);
        $row = $this->consents->revoke($this->context->tenantId(), (string) $consent['id'], $this->principal($request)->userId);
        $this->context->audit($request, 'consent.revoked', 'consent', (string) $row['id'], SimpleResources::consent()->toArray($consent), SimpleResources::consent()->toArray($row));

        return $this->ok(['data' => SimpleResources::consent()->toArray($row)]);
    }

    private function authorizeFor(ServerRequestInterface $request, string $personId): void
    {
        $principal = $this->principal($request);
        $this->people->get($this->context->tenantId(), $personId);
        if ($this->guard->can($principal, AccessGuard::MANAGE, $this->context->establishmentId()) || $this->guard->actsFor($principal, $personId)) {
            return;
        }
        throw HttpException::notFound('Personne introuvable.');
    }
}
