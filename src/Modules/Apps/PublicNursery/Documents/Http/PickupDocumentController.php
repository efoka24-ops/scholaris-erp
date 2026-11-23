<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Documents\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicNursery\Documents\Application\PickupDocuments;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;
use Scholaris\Modules\Apps\PublicNursery\Support\RequestContext;

/**
 * Documents de la porte (DOC-21 carte d'une personne autorisée, DOC-19 registre du jour) émis par le socle Documents. Le PDF, la
 * vérification publique et la réédition restent ceux du socle (`/documents/{id}`).
 */
final class PickupDocumentController
{
    /** Cartes : mêmes droits que la gestion des autorisés (direction, agent de porte). */
    public const CARD = ['public_nursery.pickup.handover', 'attendance.discipline.manage', 'settings.manage'];
    /** Registre du jour : direction (toute l'école), comme le contrôle O7. */
    public const REGISTER = ['public_nursery.register.read', 'public_schools.school.write', 'settings.manage'];
    public const LIST = ['public_nursery.register.read', 'public_nursery.pickup.handover', 'public_schools.school.write', 'settings.manage'];

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly PickupDocuments $documents,
        private readonly PublicNurseryClock $clock,
    ) {
    }

    public function card(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $scope] = $this->context->require($request, self::CARD);
        $body = RequestContext::body($request);
        RequestContext::onlyKnown($body, ['locale']);
        $result = $this->documents->pickupCard($principal, $establishmentId, $scope, self::id($request), self::locale($body));

        return $this->responder->json(['data' => $result], $result['already_issued'] === true ? 200 : 201);
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $scope] = $this->context->require($request, self::REGISTER);
        $body = RequestContext::body($request);
        RequestContext::onlyKnown($body, ['date', 'locale']);
        $day = array_key_exists('date', $body) ? RequestContext::date($body['date']) : $this->clock->today();
        if ($day === null) {
            throw HttpException::validation(['date' => ['Date au format AAAA-MM-JJ attendue.']]);
        }

        return $this->responder->json(['data' => $this->documents->register($principal, $establishmentId, $scope, $day, self::locale($body))], 201);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context->require($request, self::LIST);
        $query = $request->getQueryParams();
        $kind = isset($query['kind']) && is_string($query['kind']) && $query['kind'] !== '' ? $query['kind'] : null;
        if ($kind !== null && !isset(PickupDocuments::TYPES[$kind])) {
            throw HttpException::validation(['kind' => ['Valeurs possibles : ' . implode(', ', array_keys(PickupDocuments::TYPES)) . '.']]);
        }
        $learner = isset($query['learner_id']) && is_string($query['learner_id']) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $query['learner_id']) === 1 ? $query['learner_id'] : null;

        return $this->responder->json(['data' => $this->documents->list($principal->tenantId, $establishmentId, $kind, $learner)]);
    }

    /** @param array<string, mixed> $body */
    private static function locale(array $body): string
    {
        $locale = $body['locale'] ?? 'fr';
        if (!is_string($locale) || !in_array($locale, PickupDocuments::LOCALES, true)) {
            throw HttpException::validation(['locale' => ['Langue : fr ou en.']]);
        }

        return $locale;
    }

    private static function id(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('id');

        return is_string($id) && $id !== '' ? $id : throw HttpException::badRequest('Identifiant manquant.');
    }
}
