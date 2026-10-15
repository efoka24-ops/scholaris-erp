<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Organisation\Contracts\ApplicationDescriptor;
use Scholaris\Modules\Socle\Organisation\Contracts\ApplicationRegistry;
use Scholaris\Modules\Socle\Organisation\Domain\EstablishmentType;

/** GET /vocabulary?type=higher|secondary|primary|nursery : termes de l'interface par type d'établissement. */
final class VocabularyController
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly ApplicationRegistry $applications,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $type = $request->getQueryParams()['type'] ?? null;
        if ($type === null) {
            return $this->responder->json(['data' => array_map($this->present(...), $this->applications->all())]);
        }

        $descriptor = is_string($type) ? $this->applications->for($type) : null;
        if ($descriptor === null) {
            throw HttpException::validation(
                ['type' => ['Type inconnu. Valeurs possibles : ' . implode(', ', EstablishmentType::values()) . '.']],
                'Type d\'établissement inconnu.',
            );
        }

        return $this->responder->json(['data' => $this->present($descriptor)]);
    }

    /** @return array{type: string, label: string, terms: array<string, string>} */
    private function present(ApplicationDescriptor $descriptor): array
    {
        return ['type' => $descriptor->establishmentType, 'label' => $descriptor->label, 'terms' => $descriptor->vocabulary];
    }
}
