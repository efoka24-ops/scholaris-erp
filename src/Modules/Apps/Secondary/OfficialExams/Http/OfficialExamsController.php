<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\OfficialExams\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Application\OfficialExamService;
use Scholaris\Modules\Apps\Secondary\OfficialExams\Domain\ExamRules;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** API des sessions, candidats, exports CSV génériques et résultats d'examen (T906, T907, T949). */
final class OfficialExamsController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly OfficialExamService $exams,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function sessions(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->page($this->exams->sessions($principal, $establishmentId, $request));
    }

    public function createSession(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->code('exam_code', 40);
        $input->string('exam_label', 160);
        $input->reference('academic_year_id');
        $input->reference('class_structure_id');
        $input->reference('comparison_period_id', false, true);
        $input->date('registration_deadline', false, true);
        $input->date('exam_on', false, true);
        $input->custom('required_pieces', self::stringList(...), false);
        $input->custom('optional_subjects', self::stringList(...), false);
        $input->custom('export_columns', self::exportColumns(...), false);
        $input->custom('passing_codes', self::stringList(...), false);
        $data = $input->done();
        $data += ['required_pieces' => [], 'optional_subjects' => [], 'export_columns' => [], 'passing_codes' => []];

        return $this->json($this->exams->createSession($principal, $establishmentId, $data), 201);
    }

    public function showSession(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->exams->showSession($principal, $establishmentId, self::id($request, 'session_id')));
    }

    public function updateSession(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->code('exam_code', 40, false);
        $input->string('exam_label', 160, false);
        $input->reference('comparison_period_id', false, true);
        $input->date('registration_deadline', false, true);
        $input->date('exam_on', false, true);
        $input->custom('required_pieces', self::stringList(...), false);
        $input->custom('optional_subjects', self::stringList(...), false);
        $input->custom('export_columns', self::exportColumns(...), false);
        $input->custom('passing_codes', self::stringList(...), false);
        $input->enum('status', [ExamRules::SESSION_CLOSED], false);

        return $this->json($this->exams->updateSession($principal, $establishmentId, self::id($request, 'session_id'), $input->done()));
    }

    public function candidates(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->page($this->exams->candidates($principal, $establishmentId, self::id($request, 'session_id'), $request));
    }

    public function generate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        Input::of($request)->done();

        return $this->json($this->exams->generate($principal, $establishmentId, self::id($request, 'session_id')));
    }

    public function addCandidate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $personId = (string) $input->reference('person_id');
        $input->done();

        return $this->json($this->exams->addCandidate($principal, $establishmentId, self::id($request, 'session_id'), $personId), 201);
    }

    public function updateCandidate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request, true);
        $input->custom('pieces_received', self::stringList(...), false);
        $input->custom('selected_subjects', self::stringList(...), false);

        return $this->json($this->exams->updateCandidate($principal, $establishmentId, self::id($request), $input->done()));
    }

    public function removePhoto(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        Input::of($request)->done();

        return $this->json($this->exams->removePhoto($principal, $establishmentId, self::id($request)));
    }

    public function removeCandidate(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        Input::of($request)->done();

        return $this->json($this->exams->removeCandidate($principal, $establishmentId, self::id($request)));
    }

    public function setPhoto(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $photo = $input->custom('photo_base64', static function (mixed $value): string {
            if (!is_string($value) || $value === '' || strlen($value) > 2_800_000) {
                throw new \InvalidArgumentException('Photo JPEG ou PNG encodée en base64 (2 Mo au plus) attendue.');
            }
            $content = base64_decode($value, true);
            $image = is_string($content) ? @getimagesizefromstring($content) : false;
            if (!is_array($image) || !in_array($image['mime'], ['image/jpeg', 'image/png'], true) || strlen($content) > 2_097_152) {
                throw new \InvalidArgumentException('Une image JPEG ou PNG valide de 2 Mo au plus est attendue.');
            }

            return $value;
        });
        $input->done();

        return $this->json($this->exams->setPhoto($principal, $establishmentId, self::id($request), (string) $photo));
    }

    public function photo(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        [, $media] = $this->exams->photo($principal, $establishmentId, self::id($request), $request->getQueryParams());

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', (string) $media['mime'])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($this->streams->createStream((string) $media['content']));
    }

    public function export(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $csv = $this->exams->export($principal, $establishmentId, self::id($request, 'session_id'));

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="candidats-' . preg_replace('/[^A-Za-z0-9_-]/', '', self::id($request, 'session_id')) . '.csv"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($this->streams->createStream($csv));
    }

    public function importResults(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $csv = $input->custom('csv', static function (mixed $value): string {
            if (!is_string($value) || $value === '' || strlen($value) > 2_000_000) {
                throw new \InvalidArgumentException('Fichier CSV de résultats (2 Mo au plus) attendu.');
            }

            return $value;
        }, false);
        $input->done();
        if ($csv === null) {
            throw HttpException::validation(['csv' => ['Contenu CSV obligatoire.']]);
        }

        return $this->json($this->exams->importResults($principal, $establishmentId, self::id($request, 'session_id'), self::parseResults($csv)));
    }

    public function summary(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->exams->summary($principal, $establishmentId, self::id($request, 'session_id')));
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new \InvalidArgumentException('Liste de 100 textes au plus attendue.');
        }
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '' || mb_strlen($item) > 120) {
                throw new \InvalidArgumentException('Chaque élément doit être un texte non vide de 120 caractères au plus.');
            }
        }

        return array_values(array_unique(array_map('trim', $value)));
    }

    /** @return list<array{field: string, header: string}> */
    private static function exportColumns(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 30) {
            throw new \InvalidArgumentException('Liste de 30 colonnes au plus attendue.');
        }
        $allowed = ['person_id', 'full_name', 'national_id', 'class_label', 'fee_status', 'candidate_status', 'selected_subjects', 'result_code', 'result_mark'];
        $columns = [];
        $fields = [];
        foreach ($value as $column) {
            if (!is_array($column) || !in_array($column['field'] ?? null, $allowed, true) || !is_string($column['header'] ?? null) || trim($column['header']) === '' || mb_strlen($column['header']) > 80) {
                throw new \InvalidArgumentException('Chaque colonne exige un champ autorisé et un en-tête de 80 caractères au plus.');
            }
            $field = (string) $column['field'];
            $header = trim($column['header']);
            if (isset($fields[$field]) || in_array($header, array_column($columns, 'header'), true)) {
                throw new \InvalidArgumentException('Chaque champ et en-tête de colonne doit être unique.');
            }
            $fields[$field] = true;
            $columns[] = ['field' => $field, 'header' => $header];
        }

        return $columns;
    }

    /** @return list<array{key: string, result_code: string, result_mark: ?float, line: int}> */
    private static function parseResults(string $csv): array
    {
        if (str_contains($csv, "\0")) {
            throw HttpException::validation(['csv' => ['Le fichier CSV contient des octets nuls.']]);
        }
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Flux CSV indisponible.');
        }
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',');
        if (!is_array($header)) {
            fclose($stream);
            throw HttpException::validation(['csv' => ['En-tête CSV attendu : key,result_code,result_mark.']]);
        }
        $header = array_map(static fn (mixed $v): string => strtolower(trim((string) $v)), $header);
        $columns = array_flip($header);
        if (!isset($columns['key'], $columns['result_code']) || count($header) !== count(array_unique($header))
            || array_diff($header, ['key', 'result_code', 'result_mark']) !== []) {
            fclose($stream);
            throw HttpException::validation(['csv' => ['En-têtes attendus : key,result_code et result_mark facultatif.']]);
        }
        if (count($header) > 3) {
            fclose($stream);
            throw HttpException::validation(['csv' => ['Trois colonnes au plus sont autorisées.']]);
        }
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($stream, 0, ',')) !== false) {
            ++$line;
            if ($cells === [null]) {
                continue;
            }
            if (count($cells) !== count($header)) {
                fclose($stream);
                throw HttpException::validation(['csv' => ['Le nombre de valeurs ne correspond pas à l’en-tête (ligne ' . $line . ').']]);
            }
            $key = trim((string) $cells[$columns['key']]);
            $result = trim((string) $cells[$columns['result_code']]);
            $markText = isset($columns['result_mark']) ? trim((string) $cells[$columns['result_mark']]) : '';
            if ($key === '' || mb_strlen($key) > 80 || $result === '' || mb_strlen($result) > 40 || strlen($markText) > 40 || ($markText !== '' && (!is_numeric($markText) || !is_finite((float) $markText)))) {
                fclose($stream);
                throw HttpException::validation(['csv' => ['Identité et code de résultat requis, note numérique facultative (ligne ' . $line . ').']]);
            }
            $rows[] = ['key' => $key, 'result_code' => $result, 'result_mark' => $markText === '' ? null : (float) $markText, 'line' => $line];
            if (count($rows) > 10000) {
                fclose($stream);
                throw HttpException::validation(['csv' => ['10 000 lignes au plus.']]);
            }
        }
        fclose($stream);

        return $rows;
    }
}
