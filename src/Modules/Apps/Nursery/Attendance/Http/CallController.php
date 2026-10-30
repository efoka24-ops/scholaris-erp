<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Attendance\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Attendance\Application\CallService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceMark;

/** Appel du matin de la section et arrivées (aide maternelle, éducatrice). */
final class CallController extends Controller
{
    private const PERMISSIONS = ['attendance.take', 'nursery.arrival.record'];

    public function __construct(JsonResponder $responder, Access $access, private readonly CallService $calls, private readonly NurseryClock $clock)
    {
        parent::__construct($responder, $access);
    }

    public function roll(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::PERMISSIONS);
        $date = $request->getQueryParams()['date'] ?? null;
        $day = is_string($date) && $date !== '' ? $date : $this->clock->today();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            throw HttpException::validation(['date' => ['Date AAAA-MM-JJ attendue.']]);
        }

        return $this->page($this->calls->roll($perimeter, self::id($request), $day, Paging::fromRequest($request)));
    }

    public function call(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['attendance.take']);
        $input = Input::of($request);
        $input->enum('day_part', CallService::DAY_PARTS);
        $input->string('client_uuid', 40);
        $input->date('date', false);
        $input->bool('validate', false);
        $input->custom('exceptions', static function (mixed $value): array {
            if (!is_array($value) || !array_is_list($value) || count($value) > 500) {
                throw new \InvalidArgumentException('Liste d’exceptions attendue.');
            }
            foreach ($value as $exception) {
                if (!is_array($exception) || !is_string($exception['child_id'] ?? null) || !in_array($exception['status'] ?? null, [AttendanceMark::ABSENT, AttendanceMark::LATE], true)) {
                    throw new \InvalidArgumentException('Chaque exception porte « child_id » et un statut A (absent) ou R (en retard).');
                }
            }

            return $value;
        }, false);

        return $this->json($this->calls->call($principal, $perimeter, self::id($request), $input->done()), 201);
    }

    public function arrival(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::PERMISSIONS);
        $input = Input::of($request);
        $input->string('arrived_at', 32, false);
        $input->string('client_uuid', 40, false);
        $data = $input->done();

        return $this->json($this->calls->arrival($principal, $perimeter, self::id($request), isset($data['arrived_at']) ? (string) $data['arrived_at'] : null, isset($data['client_uuid']) ? (string) $data['client_uuid'] : null), 201);
    }
}
