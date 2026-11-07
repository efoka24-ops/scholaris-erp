<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Tutelage\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Apps\Primary\Tutelage\Application\TutelageService;

/** Exports de tutelle (effectifs, résultats, recensement, examen) en CSV, XLSX ou JSON. */
final class TutelageController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly TutelageService $tutelage,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function types(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(['items' => $this->tutelage->types($principal, $establishmentId)]);
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $q = $request->getQueryParams();
        $year = is_string($q['academic_year_id'] ?? null) && $q['academic_year_id'] !== '' ? $q['academic_year_id'] : null;
        $reference = is_string($q['reference_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['reference_date']) === 1 ? $q['reference_date'] : null;
        $format = is_string($q['format'] ?? null) ? strtolower($q['format']) : 'csv';
        $file = $this->tutelage->export($principal, $establishmentId, self::id($request, 'type'), $year, $reference, $format);
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->filename);

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', $file->contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safe . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($file->content));
    }
}
