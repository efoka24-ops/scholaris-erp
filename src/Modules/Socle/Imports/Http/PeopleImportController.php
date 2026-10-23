<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Imports\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Imports\Application\PeopleImportService;
use Scholaris\Modules\Socle\Imports\Domain\PeopleColumns;

/**
 * Import en masse des acteurs d'un établissement (permission `imports.people.manage`, contrôlée avant toute lecture du corps ou
 * du lot). Modèle téléchargeable, analyse sans écriture, bilan, fichier d'erreurs, application unique. Les réponses portent des
 * données de personnes : jamais mises en cache. Les mots de passe provisoires ne figurent QUE dans la réponse de l'application.
 */
final class PeopleImportController
{
    use ExportsList;

    public function __construct(
        private readonly PeopleImportService $service,
        private readonly JsonResponder $responder,
        private readonly PermissionChecker $permissions,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /** GET /imports/people/template?format=csv|xlsx */
    public function template(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorize($request);
        $template = $this->service->template();

        return $this->csvResponse($this->responses, $this->streams, 'modele_import_acteurs.csv', $template['headers'], $template['rows'], $request);
    }

    /** POST /imports/people : analyse (multipart, champ `file`). Rien n'est créé dans Identity. */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->authorize($request);
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw HttpException::validation(['file' => ['Joignez un fichier CSV ou Excel (.xlsx) dans le champ « file ».']]);
        }
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE) {
            throw HttpException::validation(['file' => ['Fichier trop volumineux (2 Mo au plus) : découpez-le en plusieurs fichiers.']]);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw HttpException::validation(['file' => ['Le fichier n\'a pas pu être reçu. Réessayez.']]);
        }
        if (($file->getSize() ?? 0) > PeopleColumns::MAX_BYTES) {
            throw HttpException::validation(['file' => ['Fichier trop volumineux (2 Mo au plus) : découpez-le en plusieurs fichiers.']]);
        }

        $view = $this->service->preview($principal, $establishmentId, (string) $file->getClientFilename(), (string) $file->getStream(), self::ip($request));
        $meta = ['unknown_columns' => $view['unknown_columns'], 'allowed_roles' => $view['allowed_roles']];
        unset($view['unknown_columns'], $view['allowed_roles']);

        return $this->noStore($this->responder->json(['data' => $view, 'meta' => $meta], 201));
    }

    /** GET /imports/people/{id}?status= : bilan et lignes. */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->authorize($request);
        $status = $request->getQueryParams()['status'] ?? null;
        if ($status !== null && (!is_string($status) || !in_array($status, PeopleImportService::STATUSES, true))) {
            throw HttpException::validation(['status' => ['État attendu parmi : ' . implode(', ', PeopleImportService::STATUSES) . '.']]);
        }

        return $this->noStore($this->responder->json(['data' => $this->service->show($principal, $establishmentId, self::id($request), $status)]));
    }

    /** GET /imports/people/{id}/errors.csv : lignes à corriger, dans les colonnes du modèle. */
    public function errors(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->authorize($request);
        $id = self::id($request);
        $errors = $this->service->errors($principal, $establishmentId, $id);

        return $this->csvResponse($this->responses, $this->streams, 'erreurs_import_' . $id . '.csv', $errors['headers'], $errors['rows']);
    }

    /**
     * POST /imports/people/{id}/commit : applique le lot. Corps JSON facultatif : `credentials` (provisional_password par défaut, ou
     * activation_code), `send_codes` (activation_code seulement). `?format=csv` rend directement le fichier « identifiant ; mot de
     * passe provisoire » (même remise unique que la réponse JSON).
     */
    public function commit(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->authorize($request);
        $id = self::id($request);
        $this->service->assertExists($principal, $establishmentId, $id); // 404 neutre avant de lire le corps
        $body = self::body($request);
        $unknown = array_values(array_diff(array_keys($body), ['credentials', 'send_codes']));
        $errors = [];
        foreach ($unknown as $field) {
            $errors[(string) $field] = ['Champ inconnu.'];
        }
        $mode = $body['credentials'] ?? PeopleImportService::PROVISIONAL;
        if (!in_array($mode, [PeopleImportService::PROVISIONAL, PeopleImportService::ACTIVATION], true)) {
            $errors['credentials'] = ['Valeur attendue : provisional_password ou activation_code.'];
        }
        $sendCodes = $body['send_codes'] ?? false;
        if (!is_bool($sendCodes)) {
            $errors['send_codes'] = ['Booléen attendu.'];
        } elseif ($sendCodes && $mode !== PeopleImportService::ACTIVATION) {
            $errors['send_codes'] = ['L\'envoi de codes ne concerne que credentials=activation_code : un mot de passe provisoire ne s\'envoie jamais par message.'];
        }
        $format = $request->getQueryParams()['format'] ?? 'json';
        if (!in_array($format, ['json', 'csv'], true)) {
            $errors['format'] = ['Le format doit être json ou csv.'];
        } elseif ($format === 'csv' && $mode !== PeopleImportService::PROVISIONAL) {
            $errors['format'] = ['Le fichier d\'identifiants n\'existe qu\'avec credentials=provisional_password.'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $result = $this->service->commit($principal, $establishmentId, $id, (string) $mode, $sendCodes === true, self::ip($request));
        if ($format === 'csv') {
            if ($result['replay']) {
                throw HttpException::conflict('Ce lot est déjà appliqué : les mots de passe provisoires ne sont remis qu\'une fois. Ré-émettez-en un par personne (POST /users/{id}/provisional-password).');
            }

            return $this->credentialsCsv($id, $result['credentials']);
        }
        $view = $result['view'];
        if (!$result['replay'] && $mode === PeopleImportService::PROVISIONAL) {
            $view['provisional_credentials'] = $result['credentials'];
        }

        return $this->noStore($this->responder->json(['data' => $view, 'meta' => ['replay' => $result['replay']]]));
    }

    /** @return array{0: Principal, 1: string} */
    private function authorize(ServerRequestInterface $request): array
    {
        $principal = $request->getAttribute('principal');
        if (!$principal instanceof Principal) {
            throw HttpException::unauthorized();
        }
        $establishmentId = $request->getAttribute('establishment_id');
        if (!is_string($establishmentId) || $establishmentId === '') {
            throw HttpException::badRequest('L\'en-tête X-Establishment-Id est obligatoire.');
        }
        if (!$this->permissions->can($principal, PeopleImportService::PERMISSION, $establishmentId)) {
            throw HttpException::forbidden('Permission requise : ' . PeopleImportService::PERMISSION . '.');
        }

        return [$principal, $establishmentId];
    }

    /**
     * Fichier « identifiant ; mot de passe provisoire » remis une seule fois. Les noms issus du fichier d'import sont neutralisés
     * contre l'injection de formule ; un identifiant téléphonique « +237… » reste lisible.
     *
     * @param list<array<string, mixed>> $credentials
     */
    private function credentialsCsv(string $id, array $credentials): ResponseInterface
    {
        $cell = static fn (mixed $value): string => preg_match('/^\+\d{8,15}$/', (string) $value) === 1 ? (string) $value : self::csvCell($value);
        $handle = fopen('php://temp/maxmemory:2097152', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Export impossible.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['identifiant', 'mot de passe provisoire', 'nom', 'prénoms', 'rôle', 'ligne'], ';');
        foreach ($credentials as $row) {
            fputcsv($handle, [$cell($row['login']), $cell($row['provisional_password']), $cell($row['last_name']), $cell($row['first_name']), $cell($row['role']), $cell($row['line'])], ';');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $this->noStore($this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="identifiants_import_' . preg_replace('/[^A-Za-z0-9]/', '', $id) . '.csv"')
            ->withBody($this->streams->createStream($csv)));
    }

    private function noStore(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    private static function id(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('id');
        if (!is_string($id) || $id === '') {
            throw HttpException::notFound();
        }

        return $id;
    }

    private static function ip(ServerRequestInterface $request): ?string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /** @return array<string, mixed> */
    private static function body(ServerRequestInterface $request): array
    {
        $raw = trim((string) $request->getBody());
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }

        return $data;
    }
}
