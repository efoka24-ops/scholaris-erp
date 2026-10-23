<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Domain;

/**
 * Cycle de la saisie (EX-NOT-05) au format déclaratif `assessment.lifecycle` du socle : états, états de saisie, état publié,
 * transitions (permission, 2FA récente). Une déclaration incohérente retombe sur le cycle IPES (IpesDefaults::LIFECYCLE).
 */
final class GradeLifecycle
{
    /**
     * @param list<string> $states
     * @param list<string> $editable
     * @param list<array{from: string, to: string, permission: string, require_recent_2fa: bool, role?: string|null}> $transitions
     */
    private function __construct(
        public readonly array $states,
        public readonly array $editable,
        public readonly string $published,
        public readonly array $transitions,
    ) {
    }

    public static function ipes(): self
    {
        return self::fromArray(IpesDefaults::LIFECYCLE) ?? throw new \LogicException('Cycle IPES incohérent.');
    }

    public static function fromArray(mixed $raw): ?self
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return null;
        }
        $states = $raw['states'] ?? null;
        $editable = $raw['editable'] ?? null;
        $published = $raw['published'] ?? null;
        $transitions = $raw['transitions'] ?? null;
        if (!self::names($states) || !self::names($editable) || !is_string($published) || !is_array($transitions) || $transitions === []) {
            return null;
        }
        if (!in_array($published, $states, true) || array_diff($editable, $states) !== [] || in_array($published, $editable, true)) {
            return null;
        }
        $clean = [];
        foreach ($transitions as $t) {
            if (!is_array($t) || !is_string($t['from'] ?? null) || !is_string($t['to'] ?? null) || !is_string($t['permission'] ?? null)
                || !in_array($t['from'], $states, true) || !in_array($t['to'], $states, true)
                || preg_match('/^[a-z][a-z0-9_.]{2,60}$/', $t['permission']) !== 1) {
                return null;
            }
            $clean[] = ['from' => $t['from'], 'to' => $t['to'], 'permission' => $t['permission'], 'require_recent_2fa' => ($t['require_recent_2fa'] ?? false) === true];
        }

        return new self($states, $editable, $published, $clean);
    }

    /** @phpstan-assert-if-true list<string> $value */
    private static function names(mixed $value): bool
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || preg_match('/^[a-z][a-z0-9_]{1,29}$/', $item) !== 1) {
                return false;
            }
        }

        return count(array_unique($value)) === count($value);
    }

    public function initial(): string
    {
        return $this->states[0];
    }

    public function isEditable(string $state): bool
    {
        return in_array($state, $this->editable, true);
    }

    public function isPublished(string $state): bool
    {
        return $state === $this->published;
    }

    /** État atteint ou dépassé (ordre de la liste des états). */
    public function reached(string $state, string $target): bool
    {
        $a = array_search($state, $this->states, true);
        $b = array_search($target, $this->states, true);

        return $a !== false && $b !== false && $a >= $b;
    }

    /**
     * Même cycle avec d'autres transitions (circuit de validation configuré par l'administrateur, voir GradesWorkflow) : une
     * transition peut alors exiger un rôle précis (`role`) en plus du droit.
     *
     * @param list<array{from: string, to: string, permission: string, require_recent_2fa: bool, role?: string|null}> $transitions
     */
    public function withTransitions(array $transitions): self
    {
        return new self($this->states, $this->editable, $this->published, $transitions);
    }

    /** @return array{from: string, to: string, permission: string, require_recent_2fa: bool, role?: string|null}|null */
    public function transition(string $from, string $to): ?array
    {
        foreach ($this->transitions as $t) {
            if ($t['from'] === $from && $t['to'] === $to) {
                return $t;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function targetsFrom(string $from): array
    {
        return array_values(array_map(static fn (array $t): string => $t['to'], array_filter($this->transitions, static fn (array $t): bool => $t['from'] === $from)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['states' => $this->states, 'editable' => $this->editable, 'published' => $this->published, 'transitions' => $this->transitions];
    }
}
