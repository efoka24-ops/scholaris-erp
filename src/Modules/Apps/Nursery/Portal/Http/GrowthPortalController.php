<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Portal\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Nursery\Development\Application\LifeBookService;
use Scholaris\Modules\Apps\Nursery\Development\Application\NeedService;
use Scholaris\Modules\Apps\Nursery\Development\Application\ProgressBookService;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\Controller;
use Scholaris\Modules\Apps\Nursery\Support\Paging;

/**
 * Portail du parent, suivi de l'enfant : carnets PUBLIÉS (jamais un brouillon ni un carnet non validé), cahier de vie de la section de
 * son enfant (médias filtrés par consentement photo à chaque lecture), besoins signalés. Un parent ne voit que ses enfants (404 sinon).
 */
final class GrowthPortalController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        private readonly ProgressBookService $books,
        private readonly LifeBookService $lifebook,
        private readonly NeedService $needs,
        private readonly ChildDirectory $children,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $access);
    }

    public function progressBooks(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $learnerId] = $this->own($request);

        return $this->page(Paging::fromRequest($request)->slice($this->books->publishedFor($principal->tenantId, $establishmentId, $learnerId)));
    }

    public function lifebook(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $learnerId] = $this->own($request);
        $section = $this->children->sectionOf($principal->tenantId, $establishmentId, $learnerId) ?? throw HttpException::notFound('Enfant introuvable.');

        return $this->page(Paging::fromRequest($request)->slice($this->lifebook->parentEntries($principal->tenantId, $establishmentId, $section)));
    }

    public function lifebookMedia(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $sections = [];
        foreach ($this->access->guardian($principal, $establishmentId) as $learnerId) {
            $section = $this->children->sectionOf($principal->tenantId, $establishmentId, $learnerId);
            if ($section !== null) {
                $sections[] = $section;
            }
        }
        $content = $this->lifebook->parentMedia($principal->tenantId, $establishmentId, $sections, self::id($request), $principal);

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', $content->mime)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($this->streams->createStream($content->content));
    }

    public function needs(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId, $learnerId] = $this->own($request);

        return $this->page(Paging::fromRequest($request)->slice($this->needs->forParent($principal, $establishmentId, $learnerId)));
    }

    /** @return array{0: \Scholaris\Core\Security\Principal, 1: string, 2: string} */
    private function own(ServerRequestInterface $request): array
    {
        [$principal, $establishmentId] = $this->context($request);
        $learnerId = $request->getQueryParams()['learner_id'] ?? null;
        if (!is_string($learnerId) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $learnerId) !== 1) {
            throw HttpException::validation(['learner_id' => ['Identifiant de l’enfant requis.']]);
        }
        $this->access->ownChild($principal, $establishmentId, $learnerId);

        return [$principal, $establishmentId, $learnerId];
    }
}
