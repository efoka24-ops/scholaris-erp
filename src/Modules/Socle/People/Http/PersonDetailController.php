<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\People\Application\AccessGuard;
use Scholaris\Modules\Socle\People\Application\EnrollmentService;
use Scholaris\Modules\Socle\People\Application\HealthService;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\People\Http\Requests\Input;
use Scholaris\Modules\Socle\People\Http\Resources\SimpleResources;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/** Contacts, identifiants, historique de scolarité, inscriptions et dossier de santé d'une personne. */
final class PersonDetailController extends BaseController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AccessGuard $guard,
        PersonService $people,
        private readonly PdoStore $store,
        private readonly EnrollmentService $enrollments,
        private readonly HealthService $health,
    ) {
        parent::__construct($responder, $context, $guard, $people);
    }

    public function contacts(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->visiblePerson($request, $id);

        return $this->listing(SimpleResources::contact()->collection($this->store->where('person_contacts', $this->context->tenantId(), ['person_id' => $id])));
    }

    public function addContact(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->managedPerson($request, $id);
        $data = Input::validate(
            Input::body($request),
            ['kind' => Input::enum(['phone', 'email', 'address']), 'value' => Input::text(255), 'label' => Input::text(60)],
            ['kind', 'value'],
            ['label'],
        );
        $row = $this->people->addContact($this->context->tenantId(), $id, (string) $data['kind'], (string) $data['value'], isset($data['label']) ? (string) $data['label'] : null);
        $out = SimpleResources::contact()->toArray($row);
        $this->context->audit($request, 'person.contact_added', 'person', $id, null, $out);

        return $this->ok(['data' => $out], 201);
    }

    public function deleteContact(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->managedPerson($request, $id);
        $row = $this->store->find('person_contacts', $this->context->tenantId(), $this->param($request, 'contactId'));
        if ($row === null || $row['person_id'] !== $id) {
            throw HttpException::notFound('Contact introuvable.');
        }
        $this->store->delete('person_contacts', $this->context->tenantId(), ['id' => $row['id']]);
        $this->context->audit($request, 'person.contact_removed', 'person', $id, SimpleResources::contact()->toArray($row), null);

        return $this->noContent();
    }

    public function identifiers(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->visiblePerson($request, $id);

        return $this->listing(SimpleResources::identifier()->collection($this->store->where('person_identifiers', $this->context->tenantId(), ['person_id' => $id])));
    }

    public function addIdentifier(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->managedPerson($request, $id);
        $data = Input::validate(
            Input::body($request),
            ['kind' => Input::enum(['internal', 'national']), 'value' => Input::text(40), 'establishment_id' => Input::ref()],
            ['kind', 'value'],
        );
        if ($data['kind'] === 'internal' && !isset($data['establishment_id'])) {
            throw HttpException::validation(['establishment_id' => ['L\'établissement est obligatoire pour un identifiant interne.']]);
        }
        if ($data['kind'] === 'national' && !preg_match('/^[A-Za-z0-9-]{6,30}$/', (string) $data['value'])) {
            throw HttpException::validation(['value' => ['Matricule national invalide (6 à 30 lettres, chiffres ou tirets).']]);
        }
        $row = $this->people->addIdentifier(
            $this->context->tenantId(),
            $id,
            (string) $data['kind'],
            isset($data['establishment_id']) ? (string) $data['establishment_id'] : null,
            (string) $data['value'],
        );
        $out = SimpleResources::identifier()->toArray($row);
        $this->context->audit($request, 'person.identifier_added', 'person', $id, null, $out);

        return $this->ok(['data' => $out], 201);
    }

    public function deleteIdentifier(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->managedPerson($request, $id);
        $row = $this->store->find('person_identifiers', $this->context->tenantId(), $this->param($request, 'identifierId'));
        if ($row === null || $row['person_id'] !== $id) {
            throw HttpException::notFound('Identifiant introuvable.');
        }
        if ($row['kind'] === 'national') {
            $this->people->update($this->context->tenantId(), $id, ['national_id' => null]);
        } else {
            $this->store->delete('person_identifiers', $this->context->tenantId(), ['id' => $row['id']]);
        }
        $this->context->audit($request, 'person.identifier_removed', 'person', $id, SimpleResources::identifier()->toArray($row), null);

        return $this->noContent();
    }

    public function history(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->visiblePerson($request, $id);

        return $this->listing(SimpleResources::history()->collection($this->enrollments->historyOf($this->context->tenantId(), $id)));
    }

    public function addHistory(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->managedPerson($request, $id);
        $data = Input::validate(
            Input::body($request),
            ['school_name' => Input::text(160), 'level_label' => Input::text(80), 'year_label' => Input::text(40), 'outcome' => Input::text(60)],
            ['school_name'],
            ['level_label', 'year_label', 'outcome'],
        );
        $row = $this->people->addHistory(
            $this->context->tenantId(),
            $id,
            (string) $data['school_name'],
            isset($data['level_label']) ? (string) $data['level_label'] : null,
            isset($data['year_label']) ? (string) $data['year_label'] : null,
            isset($data['outcome']) ? (string) $data['outcome'] : null,
        );
        $out = SimpleResources::history()->toArray($row);
        $this->context->audit($request, 'person.history_added', 'person', $id, null, $out);

        return $this->ok(['data' => $out], 201);
    }

    /** Toutes les inscriptions de la personne dans le client (un parent voit les établissements de ses enfants). */
    public function enrollments(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->visiblePerson($request, $id);

        return $this->listing(SimpleResources::enrollment()->collection($this->enrollments->forPerson($this->context->tenantId(), $id)));
    }

    public function health(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->guard->requireHealth($this->principal($request), $this->context->establishmentId());
        $this->visiblePerson($request, $id);
        $this->context->audit($request, 'person.health_read', 'person', $id, null, null);

        return $this->ok(['data' => $this->health->read($this->context->tenantId(), $id)]);
    }

    public function updateHealth(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->guard->requireHealth($this->principal($request), $this->context->establishmentId());
        $this->managedPerson($request, $id);
        $rules = array_fill_keys(HealthService::FIELDS, Input::text(1000));
        $data = Input::validate(Input::body($request), $rules, [], HealthService::FIELDS, true);
        /** @var array<string, string|null> $data */
        $out = $this->health->write($this->context->tenantId(), $id, $data, $this->principal($request)->userId);
        $this->context->audit($request, 'person.health_updated', 'person', $id, null, ['fields' => array_keys($data)]);

        return $this->ok(['data' => $out]);
    }
}
