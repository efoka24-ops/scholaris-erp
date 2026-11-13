<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Modules\Socle\Documents\Application\DownloadLinkService;
use Scholaris\Modules\Socle\Documents\Http\Policies\DocumentPolicy;

/**
 * SS14 : POST /documents/{id}/download-link (lien à durée limitée, droit documents.read) et GET /public/documents/{token}
 * (route publique, limitée en débit par le noyau : PDF si le lien est valide, 404 inconnu, 410 expiré, épuisé ou révoqué).
 */
final class DownloadLinkController extends BaseController
{
    public function __construct(HttpKit $kit, private readonly DownloadLinkService $links)
    {
        parent::__construct($kit);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $this->authorize($request, DocumentPolicy::READ);
        $link = $this->links->create($this->tenant(), $this->establishment(), $this->id($request), $principal->userId);

        return $this->responder->json(['data' => $link], 201)->withHeader('Cache-Control', 'no-store');
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $opened = $this->links->open((string) $request->getAttribute('token'), $this->ip($request));

        return $this->binary($opened['pdf'], 'application/pdf', $opened['number'] . '.pdf')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
