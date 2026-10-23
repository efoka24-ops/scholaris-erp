<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\EnrolmentAccess;
use Scholaris\Modules\Apps\Higher\Enrolment\Application\PersonNames;

/** GET /higher/directory/names?ids=a,b,c : noms des personnes rattachées à l'établissement (100 identifiants au plus). */
final class DirectoryController
{
    private const ID = '/^[A-Za-z0-9_-]{1,40}$/';

    public function __construct(private readonly JsonResponder $responder, private readonly PersonNames $names)
    {
    }

    public function names(ServerRequestInterface $request): ResponseInterface
    {
        $principal = EnrolmentAccess::principal($request);
        $establishmentId = EnrolmentAccess::establishmentId($request);
        $this->names->authorize($principal, $establishmentId);
        $raw = $request->getQueryParams()['ids'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            throw HttpException::validation(['ids' => ['Liste d\'identifiants séparés par des virgules attendue.']]);
        }
        $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), static fn (string $id): bool => $id !== '')));
        if (count($ids) > PersonNames::MAX) {
            throw HttpException::validation(['ids' => [sprintf('%d identifiants au plus par appel.', PersonNames::MAX)]]);
        }
        foreach ($ids as $id) {
            if (preg_match(self::ID, $id) !== 1) {
                throw HttpException::validation(['ids' => ['Identifiant invalide : lettres, chiffres, « - » et « _ » seulement.']]);
            }
        }

        return $this->responder->json(['data' => $this->names->names($principal, $establishmentId, $ids)]);
    }
}
