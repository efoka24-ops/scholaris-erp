<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Domain;

/**
 * Prérequis d'une campagne (EX-ADM-03) : diplômes admis, séries, année minimale, mention minimale ; pièces exigées.
 * Défauts : niveau 1 = baccalauréat ou GCE A Level ; niveau 3 ou plus (licence 3, master) = BTS, HND ou licence (master 2 : master 1).
 * Le contrôle porte sur les déclarations ; RG-ADM-1 exige en plus que les pièces bloquantes soient VÉRIFIÉES pour « admis ».
 */
final class Prerequisites
{
    public const DIPLOMAS = ['bac', 'gce_al', 'bts', 'hnd', 'licence', 'master1', 'master', 'other'];
    public const MENTIONS = ['passable', 'assez_bien', 'bien', 'tres_bien'];

    /**
     * @param list<string> $diplomas diplômes admis
     * @param list<string> $series séries admises (vide = toutes)
     */
    public function __construct(
        public readonly array $diplomas,
        public readonly array $series = [],
        public readonly ?int $minYear = null,
        public readonly ?string $minMention = null,
    ) {
    }

    /** Défauts du CDC selon le rang du niveau dans la filière. */
    public static function defaultFor(int $levelOrdinal): self
    {
        return match (true) {
            $levelOrdinal <= 2 => new self(['bac', 'gce_al']),
            $levelOrdinal === 3 => new self(['bts', 'hnd', 'licence']),
            $levelOrdinal === 4 => new self(['licence']),
            default => new self(['master1', 'master']),
        };
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $strings = static fn (mixed $v): array => is_array($v) ? array_values(array_filter($v, 'is_string')) : [];

        return new self(
            $strings($data['diplomas'] ?? []),
            $strings($data['series'] ?? []),
            isset($data['min_year']) && is_int($data['min_year']) ? $data['min_year'] : null,
            isset($data['min_mention']) && is_string($data['min_mention']) ? $data['min_mention'] : null,
        );
    }

    /** @return array{diplomas: list<string>, series: list<string>, min_year: ?int, min_mention: ?string} */
    public function toArray(): array
    {
        return ['diplomas' => $this->diplomas, 'series' => $this->series, 'min_year' => $this->minYear, 'min_mention' => $this->minMention];
    }

    /** @return list<string> erreurs de définition (vide si valide) */
    public function errors(): array
    {
        $errors = [];
        if ($this->diplomas === [] || array_diff($this->diplomas, self::DIPLOMAS) !== []) {
            $errors[] = 'Diplômes admis parmi : ' . implode(', ', self::DIPLOMAS) . '.';
        }
        if ($this->minMention !== null && !in_array($this->minMention, self::MENTIONS, true)) {
            $errors[] = 'Mention minimale parmi : ' . implode(', ', self::MENTIONS) . '.';
        }

        return $errors;
    }

    /** @return list<string> motifs de non-conformité de la déclaration du candidat (vide = prérequis satisfaits) */
    public function unmetBy(string $diploma, ?string $series, ?int $year, ?string $mention): array
    {
        $unmet = [];
        if (!in_array($diploma, $this->diplomas, true)) {
            $unmet[] = 'Diplôme non admis pour ce niveau (attendu : ' . implode(', ', $this->diplomas) . ').';
        }
        if ($this->series !== [] && ($series === null || !in_array(strtoupper($series), array_map('strtoupper', $this->series), true))) {
            $unmet[] = 'Série non admise (attendue : ' . implode(', ', $this->series) . ').';
        }
        if ($this->minYear !== null && ($year === null || $year < $this->minYear)) {
            $unmet[] = 'Diplôme obtenu avant ' . $this->minYear . '.';
        }
        if ($this->minMention !== null) {
            $rank = $mention === null ? false : array_search($mention, self::MENTIONS, true);
            if ($rank === false || $rank < (int) array_search($this->minMention, self::MENTIONS, true)) {
                $unmet[] = 'Mention insuffisante (minimum : ' . $this->minMention . ').';
            }
        }

        return $unmet;
    }

    /**
     * Pièces exigées par défaut : le diplôme (bloquant, il justifie les prérequis : RG-ADM-1), l'acte de naissance et la photo.
     *
     * @return list<array{code: string, label: string, blocking: bool, proves_prerequisite: bool}>
     */
    public static function defaultDocuments(): array
    {
        return [
            ['code' => 'diploma', 'label' => 'Diplôme ou attestation de réussite exigé', 'blocking' => true, 'proves_prerequisite' => true],
            ['code' => 'transcript', 'label' => 'Relevé de notes du diplôme', 'blocking' => false, 'proves_prerequisite' => false],
            ['code' => 'birth_certificate', 'label' => 'Acte de naissance', 'blocking' => false, 'proves_prerequisite' => false],
            ['code' => 'photo', 'label' => 'Photo d\'identité', 'blocking' => false, 'proves_prerequisite' => false],
        ];
    }

    /**
     * @param list<mixed> $documents
     * @return array{0: list<array{code: string, label: string, blocking: bool, proves_prerequisite: bool}>, 1: list<string>}
     */
    public static function normalizeDocuments(array $documents): array
    {
        $out = [];
        $errors = [];
        foreach ($documents as $i => $doc) {
            if (!is_array($doc) || !is_string($doc['code'] ?? null) || preg_match('/^[a-z][a-z0-9_]{1,39}$/', $doc['code']) !== 1
                || !is_string($doc['label'] ?? null) || trim($doc['label']) === '') {
                $errors[] = 'Pièce ' . $i . ' : code (minuscules) et libellé attendus.';
                continue;
            }
            $out[$doc['code']] = [
                'code' => $doc['code'],
                'label' => mb_substr(trim($doc['label']), 0, 160),
                'blocking' => ($doc['blocking'] ?? false) === true,
                'proves_prerequisite' => ($doc['proves_prerequisite'] ?? false) === true,
            ];
        }
        $out = array_values($out);
        if ($out !== [] && array_filter($out, static fn (array $d): bool => $d['proves_prerequisite'] && $d['blocking']) === []) {
            $errors[] = 'Au moins une pièce bloquante doit justifier les prérequis (RG-ADM-1).';
        }

        return [$out, $errors];
    }
}
