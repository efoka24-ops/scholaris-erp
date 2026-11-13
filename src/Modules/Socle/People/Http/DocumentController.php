<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\People\Application\AccessGuard;
use Scholaris\Modules\Socle\People\Application\DocumentService;
use Scholaris\Modules\Socle\People\Application\PersonService;
use Scholaris\Modules\Socle\People\Domain\DocumentKind;
use Scholaris\Modules\Socle\People\Domain\UploadPolicy;
use Scholaris\Modules\Socle\People\Http\Requests\Input;
use Scholaris\Modules\Socle\People\Http\Resources\SimpleResources;

/** Pièces du dossier : téléversement multipart (kind, expires_on, file), liste avec complétude, lien signé, téléchargement. */
final class DocumentController extends BaseController
{
    public function __construct(
        JsonResponder $responder,
        RequestContext $context,
        AccessGuard $guard,
        PersonService $people,
        private readonly DocumentService $documents,
        private readonly StreamFactoryInterface $streams,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($responder, $context, $guard, $people);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->visiblePerson($request, $id);
        $tenantId = $this->context->tenantId();

        return $this->listing(
            SimpleResources::document()->collection($this->documents->forPerson($tenantId, $id)),
            ['completeness' => $this->documents->completeness($tenantId, $id)],
        );
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->managedPerson($request, $id);

        $form = $request->getParsedBody();
        $form = is_array($form) ? $form : [];
        $errors = [];
        foreach (array_keys($form) as $name) {
            if (!in_array($name, ['kind', 'expires_on'], true)) {
                $errors[(string) $name] = ['Champ non autorisé.'];
            }
        }
        $kind = $form['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, DocumentKind::values(), true)) {
            $errors['kind'] = ['Type de pièce requis. Valeurs possibles : ' . implode(', ', DocumentKind::values()) . '.'];
        }
        $expiresOn = $form['expires_on'] ?? null;
        if ($expiresOn === '') {
            $expiresOn = null;
        }
        if ($expiresOn !== null) {
            try {
                $expiresOn = Input::date()($expiresOn);
            } catch (\InvalidArgumentException $e) {
                $errors['expires_on'] = [$e->getMessage()];
            }
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        $content = '';
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $errors['file'] = [$file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'Le fichier dépasse 5 Mo. Réduisez-le puis réessayez.'
                : 'Joignez un fichier (champ « file », PDF, JPG ou PNG, 5 Mo maximum).'];
        } elseif (($file->getSize() ?? 0) > UploadPolicy::MAX_BYTES) {
            $errors['file'] = ['Le fichier dépasse 5 Mo. Réduisez-le puis réessayez.'];
        } else {
            $content = (string) $file->getStream();
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $row = $this->documents->upload($this->context->tenantId(), $id, (string) $kind, $expiresOn === null ? null : (string) $expiresOn, $content, $this->principal($request)->userId);
        $out = SimpleResources::document()->toArray($row + ['status' => 'received']);
        $this->context->audit($request, 'person.document_added', 'person', $id, null, $out);

        return $this->ok(['data' => $out], 201);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->managedPerson($request, $id);
        $documentId = $this->param($request, 'docId');
        $row = $this->documents->get($this->context->tenantId(), $id, $documentId);
        $this->documents->delete($this->context->tenantId(), $id, $documentId);
        $this->context->audit($request, 'person.document_removed', 'person', $id, SimpleResources::document()->toArray($row), null);

        return $this->noContent();
    }

    /** Lien de téléchargement signé et temporaire. */
    public function link(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->param($request, 'id');
        $this->visiblePerson($request, $id);

        return $this->ok(['data' => $this->documents->signedLink($this->context->tenantId(), $id, $this->param($request, 'docId'))]);
    }

    /** Route publique : la signature HMAC et l'expiration tiennent lieu d'authentification. */
    public function download(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $expires = $query['expires'] ?? null;
        $signature = $query['signature'] ?? null;
        if (!is_string($expires) || !ctype_digit($expires) || !is_string($signature) || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            throw HttpException::forbidden('Lien de téléchargement invalide ou expiré. Demandez un nouveau lien.');
        }
        $found = $this->documents->fetchSigned($this->param($request, 'id'), $this->param($request, 'docId'), (int) $expires, $signature);
        $row = $found['row'];
        $this->audit->append((string) $row['tenant_id'], null, 'person.document_downloaded', 'person', (string) $row['person_id'], null, ['document_id' => $row['id']]);

        $extension = pathinfo((string) $row['storage_path'], PATHINFO_EXTENSION);

        return $this->responder->json([], 200)
            ->withHeader('Content-Type', (string) $row['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="piece-' . $row['kind'] . '.' . $extension . '"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($this->streams->createStream($found['content']));
    }
}
