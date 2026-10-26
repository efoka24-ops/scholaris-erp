<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\EvaluationService;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\ReportCardService;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoReportCards;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;

/** Bulletins (DOC-08 à DOC-10), publication par le directeur (API-07), rectificatif (RG-EVA-3) et banque d'appréciations (EX-EVA-07). */
final class ReportCardController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ReportCardService $service,
        private readonly EvaluationService $evaluation,
        private readonly PdoReportCards $cards,
        private readonly PrimaryClock $clock,
    ) {
    }

    public function generate(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);

        return $this->responder->json(['data' => $this->service->generate($principal, Input::establishment($request), Input::body($request))], 201);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $structureId = Input::requiredQuery($request, 'structure_id', 'Classe requise.');
        $this->evaluation->reader($principal, $establishmentId, $structureId);
        $kind = Input::query($request, 'kind');
        $ref = Input::query($request, 'scope_ref');

        $page = Input::page(
            $request,
            fn (?string $after, int $limit): array => $this->cards->list($principal->tenantId, $establishmentId, $structureId, $kind, $ref, $after, $limit),
            static fn (array $row): array => ReportCardService::resource($row),
        );
        $names = $this->evaluation->names($principal->tenantId, array_values(array_unique(array_column($page['data'], 'person_id'))));
        foreach ($page['data'] as $index => $card) {
            $page['data'][$index]['name'] = $names[(string) $card['person_id']] ?? '';
        }

        return $this->responder->json($page);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        $card = $this->service->card($principal->tenantId, $establishmentId, Input::attribute($request, 'id'));
        $this->evaluation->reader($principal, $establishmentId, (string) $card['structure_id']);
        $resource = ReportCardService::resource($card);
        $resource['name'] = $this->evaluation->names($principal->tenantId, [(string) $card['person_id']])[(string) $card['person_id']] ?? '';

        return $this->responder->json(['data' => $resource]);
    }

    public function appraise(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);

        return $this->responder->json(['data' => $this->service->appraise($principal, Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))]);
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $locale = Input::body($request)['locale'] ?? 'fr';

        return $this->responder->json(['data' => $this->service->publish($principal, Input::establishment($request), Input::attribute($request, 'id'), is_string($locale) ? $locale : 'fr')]);
    }

    public function rectify(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);

        return $this->responder->json(['data' => $this->service->rectify($principal, Input::establishment($request), Input::attribute($request, 'id'), Input::body($request))]);
    }

    public function phrases(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        if ($this->evaluationReach($request)) {
            throw HttpException::notFound();
        }
        $locale = Input::query($request, 'locale');

        return $this->responder->json(Input::page(
            $request,
            fn (?string $after, int $limit): array => $this->cards->phrases($principal->tenantId, $establishmentId, $locale, $after, $limit),
            static fn (array $row): array => ['id' => (string) $row['id'], 'locale' => (string) $row['locale'], 'phrase' => (string) $row['phrase']],
        ));
    }

    public function addPhrase(ServerRequestInterface $request): ResponseInterface
    {
        $principal = Input::principal($request);
        $establishmentId = Input::establishment($request);
        if ($this->evaluationReach($request)) {
            throw HttpException::forbidden('Permission requise : ' . implode(' ou ', EvaluationService::WRITERS) . '.');
        }
        $body = Input::body($request);
        $errors = [];
        $locale = $body['locale'] ?? 'fr';
        if (!in_array($locale, ['fr', 'en'], true)) {
            $errors['locale'][] = 'Langue attendue : fr ou en.';
        }
        $phrase = Input::text($body['phrase'] ?? null, 300, 'phrase', $errors, true);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = $this->cards->addPhrase($principal->tenantId, $establishmentId, (string) $locale, (string) $phrase, $principal->userId, $this->clock->now());

        return $this->responder->json(['data' => ['id' => $id, 'locale' => $locale, 'phrase' => $phrase]], 201);
    }

    /** Vrai si l'acteur n'a aucune permission d'évaluation dans l'école. */
    private function evaluationReach(ServerRequestInterface $request): bool
    {
        $principal = Input::principal($request);

        return $this->evaluation->anyReach($principal, Input::establishment($request))->isEmpty();
    }
}
