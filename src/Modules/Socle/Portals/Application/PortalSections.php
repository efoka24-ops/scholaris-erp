<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Portals\Application;

use DI\Container;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSection;
use Scholaris\Modules\Socle\Portals\Contracts\PortalSectionContext;
use Throwable;

/** Sections de portail déclarées sous `portals.sections.*` (S20). Une section en erreur est rendue indisponible (le portail reste servi). */
final class PortalSections
{
    public const PREFIX = 'portals.sections.';

    /** @var list<PortalSection>|null */
    private ?array $sections = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return array<string, array<string, mixed>> sections produites, par clé (vide si aucune application n'en déclare) */
    public function build(PortalSectionContext $context): array
    {
        $out = [];
        foreach ($this->all() as $section) {
            if (!in_array($context->portal, $section->portals(), true)) {
                continue;
            }
            try {
                $data = $section->build($context);
            } catch (Throwable) {
                $data = ['available' => false, 'reason' => 'Section momentanément indisponible.'];
            }
            if ($data !== null) {
                $out[$section->key()] = $data;
            }
        }

        return $out;
    }

    /** @return list<PortalSection> */
    private function all(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, self::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $section = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($section instanceof PortalSection) {
                    $found[$section->key()] = $section;
                }
            }
        }

        return $this->sections = array_values($found);
    }
}
