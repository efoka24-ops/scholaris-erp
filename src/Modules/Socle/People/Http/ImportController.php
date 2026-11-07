<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\People\Application\AccessGuard;
use Scholaris\Modules\Socle\People\Application\ImportService;
use Scholaris\Modules\Socle\People\Application\PersonService;

/**
 * Import CSV (UTF-8 ou Windows-1252, séparateur « , » ou « ; »). Deux façons de l'envoyer : multipart (champ « file »,
 * champ « validate_only ») ou JSON {"csv": "...", "validate_only": true}.
 */
final class ImportController extends BaseController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AccessGuard $guard,
        PersonService $people,
        private readonly ImportService $import,
    ) {
        parent::__construct($responder, $context, $guard, $people);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $this->guard->requireManage($this->principal($request), $this->context->establishmentId());

        $file = $request->getUploadedFiles()['file'] ?? null;
        if ($file instanceof UploadedFileInterface) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw HttpException::validation(['file' => ['Le fichier n\'a pas pu être reçu. Réessayez.']]);
            }
            $csv = (string) $file->getStream();
            $form = $request->getParsedBody();
            $validateOnly = is_array($form) && in_array(strtolower((string) ($form['validate_only'] ?? '')), ['1', 'true', 'oui', 'yes'], true);
        } else {
            $body = (string) $request->getBody() === '' ? [] : json_decode((string) $request->getBody(), true);
            if (!is_array($body) || !isset($body['csv']) || !is_string($body['csv'])) {
                throw HttpException::validation(['file' => ['Joignez un fichier CSV (champ « file ») ou envoyez {"csv": "..."}.']]);
            }
            $csv = $body['csv'];
            $validateOnly = ($body['validate_only'] ?? false) === true;
        }

        $report = $this->import->run($this->context->tenantId(), $csv, $validateOnly);
        if ($report['errors'] !== [] && !$validateOnly) {
            return $this->problem($request, 422, 'Import refusé', 'Aucune personne n\'a été enregistrée : corrigez les erreurs du rapport puis relancez l\'import.', 'import-rejected', ['report' => $report]);
        }
        if ($report['created'] > 0) {
            $this->context->audit($request, 'person.imported', 'person', 'import', null, ['created' => $report['created']]);
        }

        return $this->ok(['data' => $report], $report['created'] > 0 ? 201 : 200);
    }
}
