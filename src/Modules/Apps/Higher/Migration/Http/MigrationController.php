<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Migration\Application\BatchService;
use Scholaris\Modules\Apps\Higher\Migration\Domain\ImportKind;

/**
 * Reprise de données (lot I4) : modèles de fichiers, simulation obligatoire, application, annulation. Permission
 * `higher.migration.manage` ; application et annulation exigent une double authentification récente.
 */
final class MigrationController
{
    use ExportsList;

    public function __construct(
        private readonly JsonResponder $responder,
        private readonly BatchService $batches,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function kinds(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $this->batches->perimeter($principal, $establishmentId);

        return $this->responder->json(['data' => array_map(static fn (string $kind): array => [
            'kind' => $kind, 'label' => ImportKind::label($kind), 'columns' => ImportKind::columns($kind), 'required' => ImportKind::required($kind),
        ], ImportKind::all())]);
    }

    public function template(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $this->batches->perimeter($principal, $establishmentId);
        $kind = (string) $request->getAttribute('kind');
        if (!ImportKind::exists($kind)) {
            throw HttpException::notFound('Jeu de données inconnu.');
        }

        return $this->csvResponse($this->responses, $this->streams, 'modele_reprise_' . $kind . '.csv', ImportKind::columns($kind), ImportKind::sample($kind), $request);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $kind = $request->getQueryParams()['kind'] ?? null;

        return $this->responder->json(['data' => $this->batches->index($principal, $establishmentId, is_string($kind) && ImportKind::exists($kind) ? $kind : null)]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $body = self::body($request);
        $errors = [];
        $kind = is_string($body['kind'] ?? null) ? $body['kind'] : '';
        if (!ImportKind::exists($kind)) {
            $errors['kind'] = ['Jeu attendu parmi : ' . implode(', ', ImportKind::all()) . '.'];
        }
        $year = is_string($body['academic_year_id'] ?? null) ? trim($body['academic_year_id']) : '';
        if ($year === '' || mb_strlen($year) > 40) {
            $errors['academic_year_id'] = ['Année académique obligatoire.'];
        }
        $format = is_string($body['format'] ?? null) ? $body['format'] : 'csv';
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            $errors['format'] = ['Format attendu : csv ou xlsx.'];
        }
        if (($body['dry_run'] ?? true) !== true) {
            $errors['dry_run'] = ['La simulation est obligatoire d\'abord : envoyez dry_run=true, relisez le rapport, puis appliquez le lot (POST /higher/migration/batches/{id}/apply).'];
        }
        $content = '';
        if (is_string($body['content_base64'] ?? null)) {
            $decoded = base64_decode($body['content_base64'], true);
            if ($decoded === false) {
                $errors['content_base64'] = ['Contenu base64 invalide.'];
            } else {
                $content = $decoded;
            }
        } elseif (is_string($body['content'] ?? null)) {
            $content = $body['content'];
        } else {
            $errors['content'] = ['Contenu du fichier obligatoire (content en texte CSV, ou content_base64).'];
        }
        $name = is_string($body['file_name'] ?? null) && trim($body['file_name']) !== '' ? trim($body['file_name']) : null;
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $this->responder->json(['data' => $this->batches->simulate($principal, $establishmentId, [
            'kind' => $kind, 'academic_year_id' => $year, 'format' => $format, 'content' => $content, 'file_name' => $name,
        ])], 201);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $status = $request->getQueryParams()['status'] ?? null;

        return $this->responder->json(['data' => $this->batches->show($principal, $establishmentId, self::id($request), is_string($status) && $status !== '' ? $status : null)]);
    }

    public function apply(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);

        return $this->responder->json(['data' => $this->batches->apply($principal, $establishmentId, self::id($request))]);
    }

    public function revert(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = self::context($request);
        $reason = self::body($request)['reason'] ?? null;
        if (!is_string($reason) || mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 500) {
            throw HttpException::validation(['reason' => ['Motif obligatoire (5 à 500 caractères).']]);
        }

        return $this->responder->json(['data' => $this->batches->revert($principal, $establishmentId, self::id($request), trim($reason))]);
    }

    /** @return array{0: Principal, 1: string} */
    private static function context(ServerRequestInterface $request): array
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $establishmentId = $request->getAttribute('establishment_id');
        if (!is_string($establishmentId) || $establishmentId === '') {
            throw HttpException::badRequest('En-tête X-Establishment-Id manquant.');
        }

        return [$principal, $establishmentId];
    }

    private static function id(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('id');
        if (!is_string($id) || $id === '') {
            throw HttpException::notFound();
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private static function body(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }

        return $data;
    }
}
