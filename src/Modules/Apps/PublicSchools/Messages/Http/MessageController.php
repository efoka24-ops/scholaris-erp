<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Messages\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicSchools\Messages\Application\AnnouncementService;
use Scholaris\Modules\Apps\PublicSchools\Messages\Application\BroadcastService;
use Scholaris\Modules\Apps\PublicSchools\Messages\Infrastructure\PdoMessageBatches;
use Scholaris\Modules\Apps\PublicSchools\Support\ScopedByTerritory;

/**
 * COM : SMS par classe, niveau ou école (EX-COM-01), journal des envois et annonces territoriales (EX-COM-05).
 * Le journal ne contient ni destinataire ni donnée d'élève : le texte diffusé et les effectifs atteints.
 */
final class MessageController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ScopedByTerritory $scope,
        private readonly BroadcastService $broadcast,
        private readonly AnnouncementService $announcements,
        private readonly PdoMessageBatches $batches,
    ) {
    }

    public function send(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.messages.send', $establishmentId);
        if ($perimeter->isEmpty()) {
            $this->scope->assertWritable($principal, $perimeter, null, 'message_batch', $establishmentId);
        }

        $result = $this->broadcast->send($principal, $perimeter, $establishmentId, RequestInput::body($request));

        return $this->responder->json(['data' => $result['batch'] + ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.messages.send', $establishmentId);
        if ($perimeter->isEmpty()) {
            throw \Scholaris\Core\Http\HttpException::notFound();
        }
        $page = RequestInput::page(
            $request,
            fn (?string $after, int $limit): array => $this->batches->page($principal->tenantId, $establishmentId, $after, $limit),
            static fn (array $row): array => BroadcastService::resource($row),
        );

        return $this->responder->json($page);
    }

    public function announce(ServerRequestInterface $request): ResponseInterface
    {
        $principal = RequestInput::principal($request);
        $establishmentId = RequestInput::attribute($request, 'establishment_id');
        $perimeter = $this->scope->perimeter($principal, 'public_schools.messages.announce_territory', $establishmentId);
        if ($perimeter->isEmpty()) {
            $this->scope->assertWritable($principal, $perimeter, null, 'territory_announcement', $establishmentId);
        }

        return $this->responder->json(['data' => $this->announcements->announce($principal, $perimeter, RequestInput::body($request))], 201);
    }
}
