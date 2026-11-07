<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Grades\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Primary\Grades\Application\CompetencySheetService;
use Scholaris\Modules\Apps\Primary\Grades\Application\ConductService;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\Controller;
use Scholaris\Modules\Apps\Primary\Support\Input;
use Scholaris\Modules\Apps\Primary\Support\Store;

/** Feuille de compétences d'une classe (saisie par lot hors ligne, verrouillage, corrections approuvées) et conduite (EX-PRI-20, 31). */
final class GradesController extends Controller
{
    private const MAX_ENTRIES = 3000;

    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly CompetencySheetService $sheets, private readonly ConductService $conduct)
    {
        parent::__construct($responder, $access, $store);
    }

    public function sheet(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->sheets->sheet($principal, $establishmentId, self::id($request), self::period($request)));
    }

    public function saveSheet(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->custom('entries', static fn (mixed $v): array => self::entries($v, ['person_id', 'competency_id'], ['scale_code', 'absence_code', 'remark', 'client_uuid', 'version']));
        $values = $input->done();

        return $this->json($this->sheets->save($principal, $establishmentId, self::id($request), self::period($request), $values['entries']));
    }

    public function lock(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->sheets->lock($principal, $establishmentId, self::id($request), self::period($request)));
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->sheets->publish($principal, $establishmentId, self::id($request), self::period($request)));
    }

    public function requestCorrection(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->string('new_code', 20);
        $input->string('reason', 255);
        $values = $input->done();

        return $this->json($this->sheets->requestCorrection($principal, $establishmentId, self::id($request), (string) $values['new_code'], (string) $values['reason']), 201);
    }

    public function corrections(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $status = $request->getQueryParams()['status'] ?? null;

        return $this->json(['items' => $this->sheets->corrections($principal, $establishmentId, is_string($status) && $status !== '' ? $status : null)]);
    }

    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->sheets->decide($principal, $establishmentId, self::id($request), true));
    }

    public function reject(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->sheets->decide($principal, $establishmentId, self::id($request), false));
    }

    public function history(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json(['items' => $this->sheets->history($principal, $establishmentId, self::id($request))]);
    }

    public function conduct(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->conduct->get($principal, $establishmentId, self::id($request), self::period($request)));
    }

    public function saveConduct(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $input = Input::of($request);
        $input->custom('entries', static fn (mixed $v): array => self::entries($v, ['person_id', 'scale_code'], ['remark']));
        $values = $input->done();

        return $this->json($this->conduct->save($principal, $establishmentId, self::id($request), self::period($request), $values['entries']));
    }

    private static function period(ServerRequestInterface $request): string
    {
        $period = $request->getQueryParams()['period_id'] ?? null;
        if (!is_string($period) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $period) !== 1) {
            throw HttpException::validation(['period_id' => ['La période est obligatoire.']]);
        }

        return $period;
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     * @return list<array<string, mixed>>
     */
    private static function entries(mixed $value, array $required, array $optional): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ENTRIES) {
            throw new \InvalidArgumentException(sprintf('Liste de %d éléments au plus attendue.', self::MAX_ENTRIES));
        }
        $entries = [];
        foreach ($value as $i => $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException("L'élément $i doit être un objet.");
            }
            $clean = [];
            foreach ($required as $field) {
                if (!isset($entry[$field]) || !is_string($entry[$field]) || $entry[$field] === '' || strlen($entry[$field]) > 255) {
                    throw new \InvalidArgumentException("Élément $i : « $field » est obligatoire.");
                }
                $clean[$field] = $entry[$field];
            }
            foreach ($optional as $field) {
                if (!array_key_exists($field, $entry) || $entry[$field] === null) {
                    continue;
                }
                $isVersion = $field === 'version';
                if ($isVersion ? !is_int($entry[$field]) : (!is_string($entry[$field]) || strlen($entry[$field]) > 255)) {
                    throw new \InvalidArgumentException("Élément $i : « $field » est invalide.");
                }
                $clean[$field] = $entry[$field];
            }
            $unknown = array_diff(array_keys($entry), [...$required, ...$optional]);
            if ($unknown !== []) {
                throw new \InvalidArgumentException("Élément $i : champ(s) non reconnu(s) : " . implode(', ', array_map('strval', $unknown)) . '.');
            }
            $entries[] = $clean;
        }

        return $entries;
    }
}
