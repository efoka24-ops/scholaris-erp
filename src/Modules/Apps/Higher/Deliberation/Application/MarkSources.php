<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application;

use DI\Container;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkSheet;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkSource;

/** Sources de notes déclarées sous `higher.deliberation.mark_sources.*` ; la première qui connaît le groupe répond. */
final class MarkSources
{
    /** @var list<MarkSource>|null */
    private ?array $sources = null;

    public function __construct(private readonly Container $container)
    {
    }

    public function sheet(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session, ?string $academicYearId = null): ?MarkSheet
    {
        foreach ($this->sources() as $source) {
            $sheet = $source->sheet($tenantId, $establishmentId, $structureId, $periodId, $session, $academicYearId);
            if ($sheet !== null) {
                return $sheet;
            }
        }

        return null;
    }

    /** @throws HttpException 409 si aucune source ne fournit les notes */
    public function require(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session, ?string $academicYearId = null): MarkSheet
    {
        return $this->sheet($tenantId, $establishmentId, $structureId, $periodId, $session, $academicYearId)
            ?? throw HttpException::conflict('Aucune source de notes n\'est disponible pour ce groupe et cette période : la simulation et la liste du rattrapage exigent les notes verrouillées des EC.');
    }

    /** @return list<MarkSource> */
    private function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, MarkSource::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $source = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($source instanceof MarkSource) {
                    $found[spl_object_id($source)] = $source;
                }
            }
        }

        return $this->sources = array_values($found);
    }
}
