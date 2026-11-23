<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Organisation\Application\OrgClock;
use Scholaris\Modules\Socle\Organisation\Application\StructureWriterService;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGate;
use Scholaris\Modules\Socle\Organisation\Domain\EstablishmentType;
use Scholaris\Modules\Socle\Organisation\Domain\StructureKind;
use Scholaris\Modules\Socle\Organisation\Http\Policies\OrganisationPolicy;
use Scholaris\Modules\Socle\Organisation\Http\RequestContext;
use Scholaris\Modules\Socle\Organisation\Http\Requests\Field;
use Scholaris\Modules\Socle\Organisation\Http\Requests\StructureRequest;
use Scholaris\Modules\Socle\Organisation\Http\Requests\ValidatedRequest;
use Scholaris\Modules\Socle\Organisation\Http\Resources\Resource;
use Scholaris\Modules\Socle\Organisation\Http\Resources\StructureResource;
use Scholaris\Modules\Socle\Organisation\Infrastructure\Repositories;

/** Structures pédagogiques (niveau, classe, groupe, filière) et leur éligibilité aux inscriptions. */
final class StructureController extends ScopedCrudController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        Repositories $repositories,
        OrganisationPolicy $policy,
        OrgClock $clock,
        private readonly EnrolmentGate $gate,
        private readonly StructureWriterService $writer,
    ) {
        parent::__construct($responder, $context, $repositories, $policy, $clock);
    }

    protected function table(): string
    {
        return 'pedagogical_structures';
    }

    protected function objectType(): string
    {
        return 'structure';
    }

    protected function validator(): ValidatedRequest
    {
        return new StructureRequest();
    }

    protected function resource(): Resource
    {
        return new StructureResource();
    }

    protected function filters(): array
    {
        return ['kind', 'org_unit_id'];
    }

    protected function sorts(): array
    {
        return ['name', 'kind', 'capacity'];
    }

    protected function check(array $changes, array $merged, ?array $existing): array
    {
        $errors = $this->writer->structureErrors(
            $this->context->tenantId(),
            $this->context->establishmentId(),
            EstablishmentType::from((string) $this->context->activeEstablishment()['type']),
            StructureKind::from((string) $merged['kind']),
            isset($changes['org_unit_id']) ? (string) $changes['org_unit_id'] : null,
            isset($changes['parent_id']) ? (string) $changes['parent_id'] : null,
            $existing === null ? null : (string) $existing['id'],
        );
        if ($errors !== []) {
            $this->fail($errors);
        }

        return $changes;
    }

    /** GET /structures/{id}/enrolment?on=AAAA-MM-JJ : la structure peut-elle recevoir des inscriptions ce jour-là ? */
    public function enrolment(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request, false);
        $structure = $this->repository()->findOrFail($this->id($request));

        $on = $request->getQueryParams()['on'] ?? $this->clock->today();
        try {
            $day = Field::date()->parse($on);
        } catch (\InvalidArgumentException $e) {
            $this->fail(['on' => [$e->getMessage()]]);
        }
        $moment = new DateTimeImmutable((string) $day . ' 12:00:00', $this->clock->timezone());

        return $this->responder->json(['data' => [
            'structure_id' => $structure['id'],
            'on' => $day,
            'can_enrol' => $this->gate->canEnrol($this->context->tenantId(), $this->context->establishmentId(), (string) $structure['id'], $moment),
        ]]);
    }
}
