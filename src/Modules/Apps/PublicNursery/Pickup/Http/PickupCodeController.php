<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Pickup\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\MorningPackageService;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\PickupCodeService;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;
use Scholaris\Modules\Apps\PublicNursery\Support\RequestContext;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/**
 * Codes à usage unique du jour (T724) émis par le personnel de la porte ou la direction, et paquet du matin (T725). Le code n'est rendu
 * qu'à la création ; la remise (`POST /pickup/handovers` avec `code`) le contrôle ensuite en ligne.
 */
final class PickupCodeController
{
    public const STAFF = ['public_nursery.pickup.handover', 'attendance.discipline.manage', 'settings.manage'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly PickupCodeService $codes,
        private readonly MorningPackageService $packages,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PublicNurseryClock $clock,
    ) {
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $scope] = $this->context->require($request, self::STAFF);
        $body = RequestContext::body($request);
        RequestContext::onlyKnown($body, ['learner_id', 'pickup_id', 'valid_on']);
        $learner = $body['learner_id'] ?? null;
        $pickup = $body['pickup_id'] ?? null;
        $errors = [];
        if (!is_string($learner) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $learner) !== 1) {
            $errors['learner_id'][] = 'Identifiant de l’enfant requis.';
        }
        if (!is_string($pickup) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $pickup) !== 1) {
            $errors['pickup_id'][] = 'Identifiant de l’autorisation requis.';
        }
        $day = array_key_exists('valid_on', $body) ? RequestContext::date($body['valid_on']) : $this->clock->today();
        if ($day === null) {
            $errors['valid_on'][] = 'Date au format AAAA-MM-JJ attendue.';
        } elseif ($day < $this->clock->today()) {
            $errors['valid_on'][] = 'Un code se crée pour aujourd’hui ou un jour à venir.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $this->assertInScope($principal->tenantId, $establishmentId, (string) $learner, $scope->isWholeEstablishment() ? null : $scope);

        return $this->responder->json(['data' => $this->codes->issue($principal->tenantId, $establishmentId, (string) $learner, (string) $pickup, (string) $day, $principal->userId)], 201)->withHeader('Cache-Control', 'no-store');
    }

    public function package(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $scope] = $this->context->require($request, self::STAFF);
        $date = $request->getQueryParams()['date'] ?? null;
        $day = $date === null ? $this->clock->today() : RequestContext::date($date);
        if ($day === null) {
            throw HttpException::validation(['date' => ['Date au format AAAA-MM-JJ attendue.']]);
        }

        return $this->responder->json(['data' => $this->packages->forDay($principal->tenantId, $establishmentId, $scope, $day)])->withHeader('Cache-Control', 'no-store');
    }

    private function assertInScope(string $tenantId, string $establishmentId, string $learnerId, ?\Scholaris\Modules\Apps\PublicNursery\Support\SectionScope $scope): void
    {
        foreach ($this->enrollments->forPerson($tenantId, $learnerId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                if ($scope !== null && $enrollment->structureId !== null && !$scope->contains($enrollment->structureId)) {
                    throw HttpException::notFound('Enfant introuvable dans cet établissement.');
                }

                return;
            }
        }

        throw HttpException::notFound('Enfant introuvable dans cet établissement.');
    }
}
