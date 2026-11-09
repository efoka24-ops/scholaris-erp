<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Migration\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Enrolment\Application\EnrolmentService;
use Scholaris\Modules\Apps\Secondary\Migration\Application\MigrationService;
use Scholaris\Modules\Apps\Secondary\Migration\Domain\MigrationDefaults;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Reprise de données d'un ancien logiciel (T977) : jeux et modèles de fichiers, lots (simulation par fichier joint, rapport ligne
 * par ligne, application). Le fichier arrive en multipart/form-data (champ `file`, CSV ou XLSX) depuis l'écran ; un appel JSON
 * (`content` ou `content_base64`) reste accepté pour les scripts.
 */
final class MigrationController extends Controller
{
    use ExportsList;

    private const FORMATS = ['csv', 'xlsx'];

    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly MigrationService $migration,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function kinds(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->responder->json(['data' => $this->migration->kinds($principal, $establishmentId)]);
    }

    public function template(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $kind = self::id($request, 'kind');
        $template = $this->migration->template($principal, $establishmentId, $kind);

        return $this->csvResponse($this->responses, $this->streams, 'modele_reprise_' . $kind . '.csv', $template['columns'], $template['sample'], $request);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $kind = $request->getQueryParams()['kind'] ?? null;

        return $this->responder->json(['data' => $this->migration->index($principal, $establishmentId, is_string($kind) ? $kind : null)]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->migration->show($principal, $establishmentId, self::id($request)));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $body = self::createBody($request);
        $errors = [];
        $kind = is_string($body['kind'] ?? null) ? $body['kind'] : '';
        if (!MigrationDefaults::exists($kind)) {
            $errors['kind'] = ['Jeu attendu parmi : ' . implode(', ', MigrationDefaults::KINDS) . '.'];
        }
        $year = is_string($body['academic_year_id'] ?? null) ? trim($body['academic_year_id']) : '';
        if ($year === '' || mb_strlen($year) > 40) {
            $errors['academic_year_id'] = ['Année scolaire obligatoire.'];
        }
        $entryDate = is_string($body['entry_date'] ?? null) && trim($body['entry_date']) !== '' ? trim($body['entry_date']) : null;
        if ($kind === MigrationDefaults::STUDENTS && ($entryDate === null || !EnrolmentService::isDate($entryDate))) {
            $errors['entry_date'] = ['Date d\'entrée au format AAAA-MM-JJ attendue pour les élèves.'];
        }
        $format = is_string($body['format'] ?? null) ? strtolower($body['format']) : 'csv';
        if (!in_array($format, self::FORMATS, true)) {
            $errors['format'] = ['Format attendu : csv ou xlsx.'];
        }
        $content = null;
        if (is_string($body['content_base64'] ?? null)) {
            $decoded = base64_decode($body['content_base64'], true);
            $content = $decoded === false ? null : $decoded;
        } elseif (is_string($body['content'] ?? null)) {
            $content = $body['content'];
        }
        if ($content === null) {
            $errors['file'] = ['Joignez le fichier de reprise (champ « file », CSV ou XLSX).'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $name = is_string($body['file_name'] ?? null) && trim($body['file_name']) !== '' ? trim($body['file_name']) : null;

        return $this->json($this->migration->simulate($principal, $establishmentId, [
            'kind' => $kind, 'academic_year_id' => $year, 'entry_date' => $entryDate, 'format' => $format, 'content' => (string) $content, 'file_name' => $name,
        ]), 201);
    }

    public function apply(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->migration->apply($principal, $establishmentId, self::id($request)));
    }

    /**
     * Corps de la simulation : multipart/form-data (champs kind, academic_year_id, entry_date?, format?, et fichier `file`, dont
     * l'extension donne le format) ou JSON (content / content_base64).
     *
     * @return array<string, mixed>
     */
    private static function createBody(ServerRequestInterface $request): array
    {
        $files = $request->getUploadedFiles();
        $form = $request->getParsedBody();
        if ($files === [] && !(is_array($form) && $form !== [])) {
            $raw = (string) $request->getBody();
            if (trim($raw) === '') {
                return [];
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                throw HttpException::badRequest('Le corps de la requête doit être un objet JSON ou un formulaire avec un fichier joint.');
            }

            return $data;
        }
        $body = [];
        foreach (is_array($form) ? $form : [] as $field => $value) {
            if (is_string($value)) {
                $body[(string) $field] = $value;
            }
        }
        $file = $files['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $big = $file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

            throw HttpException::validation(['file' => [$big ? 'Fichier trop volumineux (8 Mo au plus) : découpez-le en plusieurs lots.' : 'Joignez le fichier de reprise (champ « file », CSV ou XLSX).']]);
        }
        $name = basename(str_replace('\\', '/', (string) $file->getClientFilename()));
        $body['content'] = (string) $file->getStream();
        $body['file_name'] = $name;
        if (!isset($body['format'])) {
            $body['format'] = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xlsx' ? 'xlsx' : 'csv';
        }

        return $body;
    }
}
