<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Gate\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicNursery\Gate\Application\GateService;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;
use Scholaris\Modules\Apps\PublicNursery\Support\RequestContext;

/** Porte de la maternelle publique : arrivée, maintien à l'école, registre du jour, contrôle O7 (direction). */
final class GateController
{
    public const STAFF = ['public_nursery.pickup.handover', 'public_nursery.arrival.record', 'settings.manage'];
    public const REGISTER = ['public_nursery.register.read', 'public_nursery.pickup.handover', 'public_schools.attendance.read', 'settings.manage'];
    public const AUDIT = ['public_nursery.register.read', 'public_schools.school.write', 'settings.manage'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly GateService $gate,
        private readonly PublicNurseryClock $clock,
    ) {
    }

    public function arrival(ServerRequestInterface $request): ResponseInterface
    {
        return $this->record($request, 'arrival');
    }

    public function hold(ServerRequestInterface $request): ResponseInterface
    {
        return $this->record($request, 'kept_at_school');
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $scope] = $this->context->require($request, self::REGISTER);

        return $this->responder->json(['data' => $this->gate->register($principal->tenantId, $establishmentId, $scope, $this->day($request))]);
    }

    public function audit(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context->require($request, self::AUDIT);
        $day = $this->day($request);
        $anomalies = $this->gate->audit($principal->tenantId, $establishmentId, $day);

        return $this->responder->json(['data' => ['date' => $day, 'anomalies' => $anomalies, 'ok' => $anomalies === []]]);
    }

    private function record(ServerRequestInterface $request, string $type): ResponseInterface
    {
        [$principal, $establishmentId, $scope] = $this->context->require($request, self::STAFF);
        $body = RequestContext::body($request);
        RequestContext::onlyKnown($body, ['learner_id', 'school_day', 'remark', 'client_uuid', 'occurred_at']);
        $learner = $body['learner_id'] ?? null;
        $errors = [];
        // Rejeu de l'écran de porte hors ligne (C-111, T735) : identifiant client stable et heure réelle du geste ; un rejeu rend
        // l'événement déjà enregistré, jamais un second.
        $clientUuid = $body['client_uuid'] ?? null;
        if ($clientUuid !== null && (!is_string($clientUuid) || preg_match('/^[A-Za-z0-9_-]{8,40}$/', $clientUuid) !== 1)) {
            $errors['client_uuid'][] = 'Identifiant client de 8 à 40 caractères (lettres, chiffres, _ et -).';
        }
        $occurredAt = $body['occurred_at'] ?? null;
        if ($occurredAt !== null && (!is_string($occurredAt) || \DateTimeImmutable::createFromFormat(DATE_ATOM, $occurredAt) === false)) {
            $errors['occurred_at'][] = 'Date et heure ISO 8601 attendues (ex. 2026-10-21T07:45:00+01:00).';
        }
        if (!is_string($learner) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $learner) !== 1) {
            $errors['learner_id'][] = 'Identifiant de l’enfant requis.';
        }
        $day = array_key_exists('school_day', $body) ? RequestContext::date($body['school_day']) : $this->clock->today();
        if ($day === null) {
            $errors['school_day'][] = 'Date au format AAAA-MM-JJ attendue.';
        }
        $remark = $body['remark'] ?? null;
        if ($remark !== null && (!is_string($remark) || mb_strlen($remark) > 500)) {
            $errors['remark'][] = 'Texte de 500 caractères au plus.';
        }
        if ($type === 'kept_at_school' && (!is_string($remark) || trim($remark) === '')) {
            $errors['remark'][] = 'Le maintien à l’école exige un motif (parent prévenu, attente d’une personne autorisée).';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        if (is_string($clientUuid)) {
            $existing = $this->gate->byClientUuid($principal->tenantId, $establishmentId, $clientUuid);
            if ($existing !== null) {
                return $this->responder->json(['data' => $existing]);
            }
        }

        return $this->responder->json(['data' => $this->gate->record(
            $principal->tenantId,
            $establishmentId,
            $scope,
            (string) $learner,
            $type,
            (string) $day,
            is_string($occurredAt) ? $occurredAt : $this->clock->now(),
            $principal->userId,
            is_string($remark) ? trim($remark) : null,
            is_string($clientUuid) ? 'offline' : 'online',
            is_string($clientUuid) ? $clientUuid : null,
        )], 201);
    }

    private function day(ServerRequestInterface $request): string
    {
        $value = $request->getQueryParams()['date'] ?? null;
        if ($value === null) {
            return $this->clock->today();
        }

        return RequestContext::date($value) ?? throw HttpException::validation(['date' => ['Date au format AAAA-MM-JJ attendue.']]);
    }
}
