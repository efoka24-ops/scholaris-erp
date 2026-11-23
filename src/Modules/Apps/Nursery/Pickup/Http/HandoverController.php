<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\GateJournal;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\HandoverService;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\PickupCodeService;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;

/**
 * Sortie de l'enfant : remise (identifiant d'autorisation OU code du jour, jamais un nom), refus « personne non listée », maintien,
 * création d'un code. Une remise refusée répond 422 au format RFC 9457 AVEC l'événement du journal et le compte-rendu des alertes.
 */
final class HandoverController extends Controller
{
    private const HANDOVER = ['nursery.pickup.handover'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        private readonly HandoverService $handovers,
        private readonly PickupCodeService $codes,
        private readonly ChildDirectory $children,
        private readonly NurseryClock $clock,
    ) {
        parent::__construct($responder, $access);
    }

    public function handover(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::HANDOVER);
        $input = Input::of($request);
        $input->reference('learner_id');
        $input->reference('pickup_id', false);
        $input->string('code', 20, false);
        $input->string('declared_name', 160, false);
        $input->reference('pickup_person_id', false);
        $this->offlineFields($input);

        return $this->outcome($this->handovers->handover($principal, $perimeter, $input->done()));
    }

    public function refusal(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::HANDOVER);
        $input = Input::of($request);
        $input->reference('learner_id');
        $input->string('declared_name', 160, false);
        $this->offlineFields($input);
        $result = $this->handovers->refusal($principal, $perimeter, $input->done());

        return $this->json($result, $result['duplicate'] ? 200 : 201);
    }

    public function hold(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::HANDOVER);
        $input = Input::of($request);
        $input->reference('learner_id');
        $input->string('remark', 500);
        $this->offlineFields($input);

        return $this->json($this->handovers->hold($principal, $perimeter, $input->done()), 201);
    }

    public function code(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.pickup.manage']);
        $input = Input::of($request);
        $input->reference('learner_id');
        $input->reference('pickup_id');
        $input->date('valid_on', false);
        $data = $input->done();
        $learnerId = (string) $data['learner_id'];
        $section = $this->children->sectionOf($principal->tenantId, $establishmentId, $learnerId) ?? throw \Scholaris\Core\Http\HttpException::notFound('Enfant introuvable.');
        $this->access->assertWritable($principal, $perimeter, $section, 'child', $learnerId);
        $issued = $this->codes->issue($principal, $establishmentId, $learnerId, (string) $data['pickup_id'], (string) ($data['valid_on'] ?? $this->clock->today()));

        // Le code n'est rendu qu'ici, une seule fois ; il n'est jamais relisible ensuite.
        return $this->json($issued, 201)->withHeader('Cache-Control', 'no-store');
    }

    private function offlineFields(Input $input): void
    {
        $input->date('school_day', false);
        $input->enum('origin', GateJournal::ORIGINS, false);
        $input->string('client_uuid', 40, false);
        $input->string('occurred_at', 32, false);
    }

    /** @param array{status: string, reason: string, stale_authorization: bool, duplicate: bool, event: array<string, mixed>, alerts: array{sent: int, queued: int, blocked: int, failed: int}|null} $result */
    private function outcome(array $result): ResponseInterface
    {
        if ($result['status'] === GateJournal::REFUSED) {
            $payload = [
                'type' => 'https://api.scholaris.cm/errors/' . str_replace('_', '-', $result['reason']), 'title' => 'Remise refusée', 'status' => 422,
                'detail' => 'L’enfant n’est pas remis : ' . $result['reason'] . '.', 'code' => $result['reason'], 'data' => $result,
            ];

            return $this->responder->json($payload, 422)->withHeader('Content-Type', 'application/problem+json; charset=utf-8');
        }

        return $this->json($result, $result['duplicate'] ? 200 : 201);
    }
}
