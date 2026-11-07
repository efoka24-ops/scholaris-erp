<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\AuthorizedPersonsService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupInfo;

/** Personnes autorisées d'un enfant, tenues par la secrétaire et la directrice (le parent passe par le portail). */
final class AuthorizedPersonsController extends Controller
{
    private const READ = ['nursery.pickup.read', 'nursery.pickup.manage', 'nursery.pickup.handover'];
    private const MANAGE = ['nursery.pickup.manage'];

    public function __construct(JsonResponder $responder, Access $access, private readonly AuthorizedPersonsService $service, private readonly NurseryClock $clock)
    {
        parent::__construct($responder, $access);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $query = $request->getQueryParams();
        $learnerId = isset($query['learner_id']) && is_string($query['learner_id']) ? $query['learner_id'] : '';
        if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $learnerId) !== 1) {
            throw HttpException::validation(['learner_id' => ['Identifiant de l’enfant requis.']]);
        }
        $day = isset($query['date']) && is_string($query['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query['date']) === 1 ? $query['date'] : $this->clock->today();

        return $this->page($this->service->page($perimeter, Paging::fromRequest($request), $learnerId, $day));
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);
        $input = Input::of($request);
        $input->reference('learner_id');
        self::fields($input);
        $data = $input->done();

        return $this->json($this->service->grantByStaff($principal, $perimeter, (string) $data['learner_id'], $data), 201);
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);

        return $this->json($this->service->revoke($principal, $establishmentId, self::id($request), $perimeter));
    }

    public function requests(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);

        return $this->page($this->service->pendingRequests($perimeter, Paging::fromRequest($request)));
    }

    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        return $this->decide($request, true);
    }

    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        return $this->decide($request, false);
    }

    /** Champs d'une personne autorisée (partagés avec le portail du parent). */
    public static function fields(Input $input): void
    {
        $input->string('name', 160);
        $input->string('relationship', 60);
        $input->string('phone', 30, false);
        $input->enum('grant_type', [AuthorizedPickupInfo::PERMANENT, AuthorizedPickupInfo::DAY]);
        $input->date('valid_on', false);
        $input->string('photo_base64', 3_000_000, false);
    }

    private function decide(ServerRequestInterface $request, bool $approve): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::MANAGE);

        return $this->json($this->service->decide($principal, $perimeter, self::id($request), $approve));
    }
}
