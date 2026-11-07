<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Documents\Http;

use PDO;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Documents\Application\DocumentRequests;
use Scholaris\Modules\Apps\Nursery\Documents\Application\NurseryDocuments;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Input;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentPdfReader;

/**
 * Émission des documents de la Maternelle privée (carnet publié, carte d'autorisé, registre du jour) et registre des documents émis.
 * Le PDF, la vérification publique et la réédition (Duplicata, Rectificatif) restent ceux du socle (`/documents/{id}`).
 */
final class DocumentController extends Controller
{
    private const READERS = ['nursery.child.read', 'nursery.dev.validate', 'nursery.register.read'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        private readonly DocumentRequests $requests,
        private readonly NurseryDocuments $documents,
        private readonly ChildDirectory $children,
        private readonly NurseryClock $clock,
        private readonly ConnectionFactory $connections,
        private readonly DocumentPdfReader $pdfReader,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $access);
    }

    public function progressBook(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.dev.validate']);

        return $this->json($this->requests->progressBook($principal, $perimeter, self::id($request), self::locale($request)), 201);
    }

    public function pickupCard(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.pickup.manage']);

        return $this->json($this->requests->pickupCard($principal, $perimeter, self::id($request), self::locale($request)), 201);
    }

    public function exitRegister(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, ['nursery.register.read']);
        $input = Input::of($request);
        $input->date('date', false);
        $input->enum('locale', NurseryDocuments::LOCALES, false);
        $data = $input->done();

        return $this->json($this->requests->exitRegister($principal, $perimeter, (string) ($data['date'] ?? $this->clock->today()), (string) ($data['locale'] ?? 'fr')), 201);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $perimeter = $this->access->require($principal, $establishmentId, self::READERS);
        $query = $request->getQueryParams();
        $kind = isset($query['kind']) && is_string($query['kind']) && $query['kind'] !== '' ? $query['kind'] : null;
        if ($kind !== null && !isset(NurseryDocuments::TYPES[$kind])) {
            throw HttpException::validation(['kind' => ['Valeurs possibles : ' . implode(', ', array_keys(NurseryDocuments::TYPES)) . '.']]);
        }
        $learnerId = isset($query['learner_id']) && is_string($query['learner_id']) && $query['learner_id'] !== '' ? $query['learner_id'] : null;
        if ($learnerId !== null) {
            $this->children->assertInPerimeter($perimeter, $learnerId);
        }
        // Hors tout l'établissement, seuls les documents des enfants du périmètre (jamais le registre, qui couvre l'école).
        $visible = $perimeter->wholeEstablishment ? null : array_map('strval', array_keys($this->children->sectionsByChild($perimeter)));

        return $this->page($this->documents->page($principal->tenantId, $establishmentId, Paging::fromRequest($request), $kind, $learnerId, $visible));
    }

    public function portal(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $learnerId = $request->getQueryParams()['learner_id'] ?? null;
        if (!is_string($learnerId) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $learnerId) !== 1) {
            throw HttpException::validation(['learner_id' => ['Identifiant de l’enfant requis.']]);
        }
        $this->access->ownChild($principal, $establishmentId, $learnerId);

        return $this->page(Paging::fromRequest($request)->slice($this->documents->forParent($principal->tenantId, $establishmentId, $learnerId)));
    }

    public function pdf(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $documentId = self::id($request);
        $query = $this->connections->pdo()->prepare(
            'SELECT learner_id FROM ' . NurseryDocuments::TABLE . ' WHERE tenant_id = ? AND establishment_id = ? AND document_id = ? AND status = ? AND kind IN (' . implode(', ', array_fill(0, count(NurseryDocuments::PARENT_KINDS), '?')) . ') LIMIT 1',
        );
        $params = [$principal->tenantId, $establishmentId, $documentId, 'valid', ...NurseryDocuments::PARENT_KINDS];
        $query->execute($params);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['learner_id'] ?? null) || $row['learner_id'] === '') {
            throw HttpException::notFound('Document introuvable.');
        }
        $learnerId = (string) $row['learner_id'];
        $this->access->ownChild($principal, $establishmentId, $learnerId);

        $pdf = $this->pdfReader->pdfOf($principal->tenantId, $establishmentId, $documentId);
        if ($pdf === null) {
            throw HttpException::notFound('Document introuvable.');
        }

        return $this->binary($pdf);
    }

    private function binary(string $pdf): ResponseInterface
    {
        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="document.pdf"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($this->streams->createStream($pdf));
    }

    private static function locale(ServerRequestInterface $request): string
    {
        $input = Input::of($request);
        $input->enum('locale', NurseryDocuments::LOCALES, false);

        return (string) ($input->done()['locale'] ?? 'fr');
    }
}
