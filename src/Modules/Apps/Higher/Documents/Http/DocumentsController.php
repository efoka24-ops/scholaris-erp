<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Documents\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Documents\Application\HigherDocumentCatalog;
use Scholaris\Modules\Apps\Higher\Documents\Application\HigherDocumentService;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/** Routes /higher/documents : catalogue, profil documentaire des écoles, émission, registre, annulation, duplicata. */
final class DocumentsController
{
    private const ID = '/^[A-Za-z0-9_-]{1,40}$/';

    public function __construct(
        private readonly HigherDocumentCatalog $catalog,
        private readonly HigherDocumentService $documents,
        private readonly JsonResponder $responder,
    ) {
    }

    public function catalog(ServerRequestInterface $request): ResponseInterface
    {
        $this->documents->perimeter(self::principal($request), self::establishment($request), HigherDocumentService::READ, HigherDocumentService::ISSUE, HigherDocumentService::TEMPLATES);

        return $this->responder->json(['data' => array_values(array_map(HigherDocumentCatalog::describe(...), $this->catalog->all()))]);
    }

    public function type(ServerRequestInterface $request): ResponseInterface
    {
        $this->documents->perimeter(self::principal($request), self::establishment($request), HigherDocumentService::READ, HigherDocumentService::ISSUE, HigherDocumentService::TEMPLATES);
        $code = $request->getAttribute('code');
        $type = is_string($code) ? $this->catalog->find($code) : null;

        return $type instanceof HigherDocumentType
            ? $this->responder->json(['data' => HigherDocumentCatalog::describe($type)])
            : throw HttpException::notFound('Type de document inconnu.');
    }

    public function profile(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->documents->profile(self::principal($request), self::establishment($request), self::route($request, 'schoolId'))]);
    }

    public function putProfile(ServerRequestInterface $request): ResponseInterface
    {
        $body = self::body($request, ['school_code', 'display_name', 'header_text', 'logo_url', 'primary_color', 'default_locale']);
        $errors = [];
        $code = $body['school_code'] ?? null;
        if (!is_string($code) || preg_match('/^[A-Z0-9]{1,10}$/', $code) !== 1) {
            $errors['school_code'][] = 'Code d\'école du numéro : 1 à 10 majuscules ou chiffres (ex. EC).';
        }
        $name = $body['display_name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 160) {
            $errors['display_name'][] = 'Nom affiché de 1 à 160 caractères.';
        }
        $header = $body['header_text'] ?? null;
        if ($header !== null && (!is_string($header) || mb_strlen($header) > 500)) {
            $errors['header_text'][] = 'En-tête de 500 caractères au plus.';
        }
        $logo = $body['logo_url'] ?? null;
        if ($logo !== null && (!is_string($logo) || mb_strlen($logo) > 500 || preg_match('#^https://[^\s"\'<>]+$#', $logo) !== 1)) {
            $errors['logo_url'][] = 'Adresse https:// du logo attendue.';
        }
        $color = $body['primary_color'] ?? null;
        if ($color !== null && (!is_string($color) || preg_match(HigherDocumentService::COLOR, $color) !== 1)) {
            $errors['primary_color'][] = 'Couleur #RRGGBB attendue.';
        }
        $locale = $body['default_locale'] ?? null;
        if ($locale !== null && !in_array($locale, ['fr', 'en'], true)) {
            $errors['default_locale'][] = 'Langue fr ou en.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $this->responder->json(['data' => $this->documents->putProfile(self::principal($request), self::establishment($request), self::route($request, 'schoolId'), [
            'school_code' => (string) $code, 'display_name' => trim((string) $name), 'header_text' => is_string($header) ? $header : null,
            'logo_url' => is_string($logo) ? $logo : null, 'primary_color' => is_string($color) ? $color : null, 'default_locale' => is_string($locale) ? $locale : null,
        ])]);
    }

    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        $body = self::body($request, ['code', 'person_id', 'school_id', 'program_id', 'level_id', 'locale', 'data']);
        $errors = [];
        $code = $body['code'] ?? null;
        if (!is_string($code) || preg_match(HigherDocumentType::CODE, $code) !== 1) {
            $errors['code'][] = 'Code DOC-nn attendu.';
        }
        foreach (['person_id' => true, 'school_id' => true, 'program_id' => false, 'level_id' => false] as $field => $required) {
            $value = $body[$field] ?? null;
            if (($value === null && $required) || ($value !== null && (!is_string($value) || preg_match(self::ID, $value) !== 1))) {
                $errors[$field][] = 'Identifiant attendu.';
            }
        }
        $locale = $body['locale'] ?? null;
        if ($locale !== null && !in_array($locale, ['fr', 'en'], true)) {
            $errors['locale'][] = 'Langue fr ou en.';
        }
        $data = $body['data'] ?? [];
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            $errors['data'][] = 'Objet de variables attendu.';
            $data = [];
        }
        $clean = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || !($value === null || is_scalar($value)) || (is_string($value) && mb_strlen($value) > 2000)) {
                $errors['data'][] = 'Valeurs simples attendues (texte de 2 000 caractères au plus).';
                break;
            }
            $clean[$key] = $value;
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $this->responder->json(['data' => $this->documents->issue(
            self::principal($request),
            self::establishment($request),
            (string) $code,
            (string) $body['person_id'],
            (string) $body['school_id'],
            isset($body['program_id']) ? (string) $body['program_id'] : null,
            isset($body['level_id']) ? (string) $body['level_id'] : null,
            $clean,
            is_string($locale) ? $locale : null,
        )], 201);
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $filters = [];
        foreach (['person_id' => 'person_id', 'code' => 'doc_code', 'school_id' => 'school_id', 'status' => 'doc_status'] as $param => $column) {
            $value = $request->getQueryParams()[$param] ?? null;
            if (is_string($value) && $value !== '') {
                if (preg_match(self::ID, $value) !== 1) {
                    throw HttpException::validation([$param => ['Valeur invalide.']]);
                }
                $filters[$column] = $value;
            }
        }

        return $this->responder->json(['data' => $this->documents->index(self::principal($request), self::establishment($request), $filters)]);
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->documents->show(self::principal($request), self::establishment($request), self::route($request, 'id'))]);
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $body = self::body($request, ['reason', 'replaced_by']);
        $reason = $body['reason'] ?? null;
        $replacedBy = $body['replaced_by'] ?? null;
        if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 400) {
            throw HttpException::validation(['reason' => ['Motif de 1 à 400 caractères obligatoire (RG-DOC-5).']]);
        }
        if ($replacedBy !== null && (!is_string($replacedBy) || preg_match(self::ID, $replacedBy) !== 1)) {
            throw HttpException::validation(['replaced_by' => ['Identifiant attendu.']]);
        }

        return $this->responder->json(['data' => $this->documents->cancel(self::principal($request), self::establishment($request), self::route($request, 'id'), trim($reason), $replacedBy)]);
    }

    public function duplicate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->json(['data' => $this->documents->duplicate(self::principal($request), self::establishment($request), self::route($request, 'id'))]);
    }

    private static function principal(ServerRequestInterface $request): Principal
    {
        $principal = $request->getAttribute('principal');

        return $principal instanceof Principal ? $principal : throw HttpException::unauthorized();
    }

    private static function establishment(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('establishment_id');

        return is_string($id) && $id !== '' ? $id : throw HttpException::badRequest('En-tête X-Establishment-Id manquant.');
    }

    private static function route(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) && preg_match(self::ID, $value) === 1 ? $value : throw HttpException::notFound();
    }

    /**
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private static function body(ServerRequestInterface $request, array $allowed): array
    {
        $raw = trim((string) $request->getBody());
        $data = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }
        $unknown = array_diff(array_map('strval', array_keys($data)), $allowed);
        if ($unknown !== []) {
            throw HttpException::validation(array_fill_keys(array_values($unknown), ['Champ non autorisé.']));
        }

        return $data;
    }
}
