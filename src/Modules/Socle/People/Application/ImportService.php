<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\People\Domain\DuplicateMatcher;
use Scholaris\Modules\Socle\People\Domain\PersonAttributes;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/**
 * Import CSV de personnes. Toutes les lignes sont contrôlées ; s'il reste une erreur bloquante, RIEN n'est enregistré
 * et le rapport donne l'erreur ligne par ligne. L'option « valider seulement » ne crée jamais de fiche.
 */
final class ImportService
{
    public const MAX_ROWS = 1000;
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const GENDERS = [
        'm' => 'male', 'h' => 'male', 'male' => 'male', 'masculin' => 'male', 'homme' => 'male', 'garcon' => 'male', 'garçon' => 'male',
        'f' => 'female', 'female' => 'female', 'feminin' => 'female', 'féminin' => 'female', 'femme' => 'female', 'fille' => 'female',
    ];

    public function __construct(
        private readonly PersonService $people,
        private readonly PeopleClock $clock,
        private readonly PdoStore $store,
    ) {
    }

    /** @return array{total: int, valid: int, created: int, validate_only: bool, errors: list<array{line: int, field: ?string, message: string}>} */
    public function run(string $tenantId, string $csv, bool $validateOnly): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw HttpException::validation(['file' => ['Le fichier dépasse 2 Mo : découpez-le en plusieurs imports.']]);
        }
        [$header, $records, $errors] = $this->parse($csv);
        $today = $this->clock->today();
        $accepted = [];
        $valid = [];
        $seenNational = [];

        foreach ($records as $line => $record) {
            $lineErrors = [];
            $confirm = in_array(mb_strtolower($record['confirm_different'] ?? ''), ['1', 'oui', 'yes', 'true', 'vrai'], true);
            $data = array_filter(array_intersect_key($record, array_flip(PersonAttributes::FIELDS)), static fn (string $v): bool => $v !== '');
            if (isset($data['gender'])) {
                $data['gender'] = self::GENDERS[mb_strtolower($data['gender'])] ?? $data['gender'];
            }
            [$values, $fieldErrors] = PersonAttributes::validate($data, $today);
            foreach ($fieldErrors as $field => $messages) {
                $lineErrors[] = ['line' => $line, 'field' => $field, 'message' => $messages[0]];
            }
            if ($fieldErrors === []) {
                $national = $values['national_id'] ?? null;
                if (is_string($national) && (isset($seenNational[$national]) || $this->store->count('people', $tenantId, ['national_id' => $national]) > 0)) {
                    $lineErrors[] = ['line' => $line, 'field' => 'national_id', 'message' => 'Ce matricule national existe déjà.'];
                }
                if (is_string($national)) {
                    $seenNational[$national] = true;
                }
                if (!$confirm) {
                    foreach ($accepted as $previous) {
                        if (DuplicateMatcher::criteria($values, $previous) !== []) {
                            $lineErrors[] = ['line' => $line, 'field' => null, 'message' => 'Doublon d\'une ligne précédente du fichier. Ajoutez confirm_different=oui si ce sont deux personnes.'];
                            break;
                        }
                    }
                    $existing = $this->people->duplicates($tenantId, $values);
                    if ($existing !== []) {
                        $lineErrors[] = ['line' => $line, 'field' => null, 'message' => sprintf(
                            'Une fiche existe déjà (%d critères sur %d, fiche %s). Ajoutez confirm_different=oui si ce sont deux personnes.',
                            count($existing[0]['criteria']),
                            DuplicateMatcher::TOTAL,
                            $existing[0]['person']['id'],
                        )];
                    }
                }
                $accepted[] = $values;
            }
            if ($lineErrors === []) {
                $valid[] = $values;
            }
            array_push($errors, ...$lineErrors);
        }
        unset($header);

        $created = 0;
        if ($errors === [] && !$validateOnly) {
            $this->store->transaction(function () use ($tenantId, $valid, &$created): void {
                foreach ($valid as $values) {
                    $this->people->create($tenantId, $values);
                    ++$created;
                }
            });
        }

        return ['total' => count($records), 'valid' => count($valid), 'created' => $created, 'validate_only' => $validateOnly, 'errors' => $errors];
    }

    /** @return array{0: list<string>, 1: array<int, array<string, string>>, 2: list<array{line: int, field: ?string, message: string}>} */
    private function parse(string $csv): array
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($csv, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Flux mémoire indisponible.');
        }
        fwrite($stream, $csv);
        rewind($stream);

        $errors = [];
        $header = [];
        $records = [];
        $line = 0;
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            ++$line;
            if ($cells === [null] || implode('', array_map(static fn (?string $c): string => trim((string) $c), $cells)) === '') {
                continue;
            }
            if ($header === []) {
                $header = array_map(static fn (?string $c): string => mb_strtolower(trim((string) $c)), $cells);
                $allowed = [...PersonAttributes::FIELDS, 'confirm_different'];
                foreach ($header as $column) {
                    if (!in_array($column, $allowed, true)) {
                        $errors[] = ['line' => $line, 'field' => $column, 'message' => 'Colonne inconnue. Colonnes acceptées : ' . implode(', ', $allowed) . '.'];
                    }
                }
                foreach (['first_name', 'last_name'] as $required) {
                    if (!in_array($required, $header, true)) {
                        $errors[] = ['line' => $line, 'field' => $required, 'message' => 'Colonne obligatoire absente.'];
                    }
                }
                continue;
            }
            if (count($records) >= self::MAX_ROWS) {
                $errors[] = ['line' => $line, 'field' => null, 'message' => 'Plus de ' . self::MAX_ROWS . ' lignes : découpez le fichier.'];
                break;
            }
            $row = [];
            foreach ($header as $index => $column) {
                $row[$column] = trim((string) ($cells[$index] ?? ''));
            }
            $records[$line] = $row;
        }
        fclose($stream);
        if ($header === []) {
            $errors[] = ['line' => 1, 'field' => null, 'message' => 'Fichier vide : la première ligne doit contenir les noms de colonnes.'];
        }

        return [$header, $errors === [] ? $records : [], $errors];
    }
}
