<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Curriculum\Application;

use DI\Container;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\GradeProbe;

/** Découvre les sondes de notes déclarées par les sous-modules (`higher.curriculum.grade_probes.*`) et les interroge. */
final class GradeProbes
{
    /** @var list<GradeProbe>|null */
    private ?array $probes = null;

    public function __construct(private readonly Container $container)
    {
    }

    public function anyGrades(string $tenantId, string $establishmentId, string $curriculumId): bool
    {
        foreach ($this->probes() as $probe) {
            if ($probe->hasGrades($tenantId, $establishmentId, $curriculumId)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<GradeProbe> */
    private function probes(): array
    {
        if ($this->probes !== null) {
            return $this->probes;
        }
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, GradeProbe::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $probe = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($probe instanceof GradeProbe) {
                    $found[spl_object_id($probe)] = $probe;
                }
            }
        }

        return $this->probes = array_values($found);
    }
}
