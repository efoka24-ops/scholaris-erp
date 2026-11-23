<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Development\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicNursery\Development\Application\ProgressBookService;
use Scholaris\Modules\Apps\PublicNursery\Support\RequestContext;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Carnet de suivi DOC-18 (FR-M12) par HTTP : brouillon de l'éducatrice (portée de section), validation et publication par la direction,
 * réédition Duplicata ou Rectificatif, PDF d'un carnet publié. Un carnet non publié n'est jamais servi en PDF.
 */
final class ProgressBookController
{
    public const WRITE = ['public_nursery.dev.observe', 'settings.manage'];
    public const VALIDATE = ['public_nursery.dev.validate', 'public_schools.school.write', 'settings.manage'];
    public const FORBIDDEN_TERMS = 'public_nursery.dev.discouraged_terms';

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly RequestContext $context,
        private readonly ProgressBookService $books,
        private readonly SettingsReader $settings,
        private readonly AuditLog $audit,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $scope] = $this->context->require($request, [...self::WRITE, ...self::VALIDATE]);
        $book = $this->books->find($principal->tenantId, $establishmentId, self::id($request));
        if ($book === null || !$scope->contains((string) $book['structure_id'])) {
            throw HttpException::notFound('Carnet introuvable.');
        }

        return $this->responder->json(['data' => $book]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $scope] = $this->context->require($request, self::WRITE);
        $body = RequestContext::body($request);
        RequestContext::onlyKnown($body, ['learner_id', 'structure_id', 'period_id', 'school_year', 'items', 'synthesis']);
        $errors = [];
        foreach (['learner_id' => 40, 'structure_id' => 40, 'period_id' => 40, 'school_year' => 9] as $field => $max) {
            if (!is_string($body[$field] ?? null) || trim($body[$field]) === '' || mb_strlen($body[$field]) > $max) {
                $errors[$field][] = sprintf('Texte non vide de %d caractères au plus.', $max);
            }
        }
        $items = $body['items'] ?? [];
        if (!is_array($items) || !array_is_list($items) || count($items) > 200) {
            $errors['items'][] = 'Liste de 200 éléments au plus (domain, label, level, comment).';
            $items = [];
        }
        $synthesis = $body['synthesis'] ?? '';
        if (!is_string($synthesis) || mb_strlen($synthesis) > 3000) {
            $errors['synthesis'][] = 'Texte de 3000 caractères au plus.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if (!$scope->contains((string) $body['structure_id'])) {
            $this->audit->append($principal->tenantId, $principal->userId, 'public_nursery.scope.denied', 'section', (string) $body['structure_id']);

            throw HttpException::forbidden('Cette section est hors de votre périmètre.');
        }
        /** @var list<array<string, mixed>> $list */
        $list = array_values(array_filter($items, 'is_array'));
        $book = $this->books->saveDraft($principal->tenantId, $establishmentId, (string) $body['learner_id'], (string) $body['structure_id'], (string) $body['period_id'], (string) $body['school_year'], $list, (string) $synthesis);

        return $this->responder->json(['data' => $book], 201);
    }

    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context->require($request, self::VALIDATE);
        $terms = $this->settings->value($principal->tenantId, $establishmentId, self::FORBIDDEN_TERMS, []);
        $terms = is_array($terms) ? array_values(array_filter($terms, 'is_string')) : [];

        return $this->responder->json(['data' => $this->books->validate($principal->tenantId, $establishmentId, self::id($request), $principal->userId, $terms)]);
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context->require($request, self::VALIDATE);

        return $this->responder->json(['data' => $this->books->publish($principal->tenantId, $establishmentId, self::id($request), $principal->userId)]);
    }

    public function reissue(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context->require($request, self::VALIDATE);
        $body = RequestContext::body($request);
        RequestContext::onlyKnown($body, ['edition_label']);

        return $this->responder->json(['data' => $this->books->reissue($principal->tenantId, $establishmentId, self::id($request), $principal->userId, is_string($body['edition_label'] ?? null) ? $body['edition_label'] : '')]);
    }

    public function pdf(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $scope] = $this->context->require($request, [...self::WRITE, ...self::VALIDATE]);
        $book = $this->books->find($principal->tenantId, $establishmentId, self::id($request));
        if ($book === null || !$scope->contains((string) $book['structure_id'])) {
            throw HttpException::notFound('Carnet introuvable.');
        }
        $locale = ($request->getQueryParams()['locale'] ?? 'fr') === 'en' ? 'en' : 'fr';

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($this->streams->createStream($this->books->pdf($principal->tenantId, $establishmentId, self::id($request), $locale)));
    }

    private static function id(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('id');

        return is_string($id) ? $id : '';
    }
}
