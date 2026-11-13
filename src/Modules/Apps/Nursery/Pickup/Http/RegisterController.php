<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\PackageService;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\RegisterService;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;

/** Registre du jour et paquet du matin d'une section (hors ligne). */
final class RegisterController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        private readonly RegisterService $register,
        private readonly PackageService $packages,
        private readonly NurseryClock $clock,
    ) {
        parent::__construct($responder, $access);
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.register.read']);

        return $this->json($this->register->forDay($perimeter, $this->day($request)));
    }

    public function package(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.pickup.handover']);

        return $this->json($this->packages->forSection($perimeter, self::id($request), $this->day($request)));
    }

    private function day(ServerRequestInterface $request): string
    {
        $date = $request->getQueryParams()['date'] ?? null;
        if ($date === null || $date === '') {
            return $this->clock->today();
        }
        $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw HttpException::validation(['date' => ['Date AAAA-MM-JJ attendue.']]);
        }

        return $date;
    }
}
