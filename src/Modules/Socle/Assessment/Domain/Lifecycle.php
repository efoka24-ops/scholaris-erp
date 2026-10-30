<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Domain;

/**
 * Cycle de vie des notes, déclarable par application (EX-S09-05, T328) : liste ordonnée d'états, états où la saisie
 * reste possible, état « publié », et pour chaque transition le droit requis et l'exigence éventuelle d'une 2FA récente.
 * Le défaut du socle : brouillon, saisie, validée et verrouillée, publiée.
 */
final class Lifecycle
{
    /**
     * @param list<string> $states le premier est l'état initial
     * @param list<string> $editable états où une note peut encore être saisie ou modifiée
     * @param list<array{from: string, to: string, permission: string, require_recent_2fa: bool}> $transitions
     */
    public function __construct(
        public readonly array $states,
        public readonly array $editable,
        public readonly string $published,
        public readonly array $transitions,
    ) {
    }

    public static function default(): self
    {
        return new self(
            ['draft', 'entry', 'locked', 'published'],
            ['draft', 'entry'],
            'published',
            [
                ['from' => 'draft', 'to' => 'entry', 'permission' => 'assessment.enter', 'require_recent_2fa' => false],
                ['from' => 'entry', 'to' => 'locked', 'permission' => 'assessment.validate', 'require_recent_2fa' => false],
                ['from' => 'locked', 'to' => 'published', 'permission' => 'assessment.publish', 'require_recent_2fa' => false],
            ],
        );
    }

    /** @param array<string, mixed> $raw définition déclarée par l'application ; null si elle est incohérente */
    public static function fromArray(array $raw): ?self
    {
        $states = $raw['states'] ?? null;
        $editable = $raw['editable'] ?? null;
        $published = $raw['published'] ?? null;
        $transitions = $raw['transitions'] ?? null;
        if (!self::isNameList($states) || !self::isNameList($editable) || !is_string($published) || !is_array($transitions)) {
            return null;
        }
        if (!in_array($published, $states, true) || array_diff($editable, $states) !== [] || in_array($published, $editable, true)) {
            return null;
        }

        $clean = [];
        foreach ($transitions as $transition) {
            if (!is_array($transition)
                || !isset($transition['from'], $transition['to'], $transition['permission'])
                || !is_string($transition['from']) || !is_string($transition['to']) || !is_string($transition['permission'])
                || !in_array($transition['from'], $states, true) || !in_array($transition['to'], $states, true)
                || preg_match('/^[a-z][a-z0-9_.]{2,60}$/', $transition['permission']) !== 1) {
                return null;
            }
            $clean[] = [
                'from' => $transition['from'],
                'to' => $transition['to'],
                'permission' => $transition['permission'],
                'require_recent_2fa' => ($transition['require_recent_2fa'] ?? false) === true,
            ];
        }

        return $clean === [] ? null : new self($states, $editable, $published, $clean);
    }

    /** @phpstan-assert-if-true list<string> $value */
    private static function isNameList(mixed $value): bool
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

    /** @return array{from: string, to: string, permission: string, require_recent_2fa: bool}|null */
    public function transition(string $from, string $to): ?array
    {
        foreach ($this->transitions as $transition) {
            if ($transition['from'] === $from && $transition['to'] === $to) {
                return $transition;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function targetsFrom(string $from): array
    {
        $out = [];
        foreach ($this->transitions as $transition) {
            if ($transition['from'] === $from) {
                $out[] = $transition['to'];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['states' => $this->states, 'editable' => $this->editable, 'published' => $this->published, 'transitions' => $this->transitions];
    }
}
