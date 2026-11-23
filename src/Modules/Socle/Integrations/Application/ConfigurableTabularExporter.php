<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Application;

use DI\Container;
use InvalidArgumentException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\XlsxWriter;
use Scholaris\Modules\Socle\Integrations\Contracts\ExportFile;
use Scholaris\Modules\Socle\Integrations\Contracts\ExportType;
use Scholaris\Modules\Socle\Integrations\Contracts\TabularExporter;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/** Export à colonnes configurables par type (S14) : types déclarés sous `integrations.export_types.*`, colonnes par paramètre. */
final class ConfigurableTabularExporter implements TabularExporter
{
    public const PREFIX = 'integrations.export_types.';
    private const TYPES = ['csv' => 'text/csv; charset=utf-8', 'xlsx' => XlsxWriter::CONTENT_TYPE, 'json' => 'application/json'];

    /** @var array<string, ExportType>|null */
    private ?array $types = null;

    public function __construct(
        private readonly Container $container,
        private readonly ExportFileWriter $writer,
        private readonly SettingsReader $settings,
    ) {
    }

    public function columns(string $tenantId, ?string $establishmentId, string $exportType): array
    {
        $type = $this->type($exportType);
        $key = $type->columnsSetting();
        if ($key === null) {
            return $type->defaultColumns();
        }
        try {
            $raw = $this->settings->value($tenantId, $establishmentId, $key, null);
        } catch (HttpException) {
            $raw = null;
        }
        if (!is_array($raw) || $raw === []) {
            return $type->defaultColumns();
        }
        $columns = [];
        foreach ($raw as $header => $field) {
            if (!is_string($field) || !in_array($field, $type->fields(), true)) {
                throw HttpException::validation([$key => [sprintf(
                    'Champ source inconnu « %s ». Champs disponibles : %s.',
                    is_scalar($field) ? (string) $field : '?',
                    implode(', ', $type->fields()),
                )]]);
            }
            $columns[is_string($header) ? $header : $field] = $field;
        }

        return $columns;
    }

    public function render(string $tenantId, ?string $establishmentId, string $exportType, array $records, string $format = 'csv'): ExportFile
    {
        $format = strtolower($format);
        if (!isset(self::TYPES[$format])) {
            throw HttpException::validation(['format' => ['Format inconnu : csv, xlsx ou json.']]);
        }
        $type = $this->type($exportType);
        $columns = $this->columns($tenantId, $establishmentId, $exportType);
        try {
            [$content, , $rows] = $this->writer->render($format, ';', $columns, $records, $type->fields(), mb_substr($type->label(), 0, 31));
        } catch (InvalidArgumentException $e) {
            throw HttpException::validation(['columns' => [$e->getMessage()]]);
        }
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $type->key());

        return new ExportFile($content, $format, self::TYPES[$format], $name . '.' . $format, $rows, array_map('strval', array_keys($columns)));
    }

    private function type(string $key): ExportType
    {
        if ($this->types === null) {
            $this->types = [];
            $names = $this->container->getKnownEntryNames();
            sort($names);
            foreach ($names as $name) {
                if (!str_starts_with($name, self::PREFIX)) {
                    continue;
                }
                foreach ((array) $this->container->get($name) as $entry) {
                    $type = is_string($entry) ? $this->container->get($entry) : $entry;
                    if ($type instanceof ExportType) {
                        $this->types[$type->key()] = $type;
                    }
                }
            }
        }

        return $this->types[$key] ?? throw HttpException::notFound('Type d\'export inconnu : ' . $key . '.');
    }
}
