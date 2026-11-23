<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Deliberation;

use Tests\Support\Higher\Deliberation\TranscriptTestCase;

/**
 * C-103 : la migration 202611051201 met à niveau les relevés DOC-09 et DOC-10 des établissements qui ont installé l'ancien modèle
 * (emplacements u01 à u10) : nouvelles variables au type, nouvelle version validée du modèle, ancienne version conservée, idempotence,
 * modèle déjà à tableau jamais touché, identifiants uniques entre établissements. Après mise à niveau, un relevé de 12 UE s'émet.
 */
final class TranscriptTemplateUpgradeTest extends TranscriptTestCase
{
    private const MIGRATION = 'database/migrations/202611051201_c103_higher_transcript_templates.sql';
    private const OTHER = ['tenant-autre', 'estab-autre'];
    private const CUSTOM = ['tenant-perso', 'estab-perso'];

    protected function setUp(): void
    {
        $this->bootTranscripts();
        $pdo = $this->pdo();
        // Retour à l'état « installé avant DOC-BUL » pour l'établissement de test : variables d'origine, modèle à emplacements.
        $pdo->exec("DELETE FROM document_templates WHERE type_code IN ('hi_doc09', 'hi_doc10') AND tenant_id = '" . self::T . "'");
        $pdo->exec("UPDATE document_types SET variables = REPLACE(REPLACE(REPLACE(variables, ',\"units_table\"', ''), ',\"annual_table\"', ''), ',\"rank_info\"', '') WHERE code IN ('hi_doc09', 'hi_doc10') AND tenant_id = '" . self::T . "'");
        foreach (['hi_doc09', 'hi_doc10'] as $code) {
            foreach (['fr', 'en'] as $locale) {
                $this->template(self::T, self::E, $code, $locale, 1, '<p>{{u01_code}} {{u01_title}} {{decision}}</p>', 'L' . $code . $locale);
            }
        }
        // Un autre client a installé le même catalogue (mêmes numéros de version, identifiants différents).
        foreach (['hi_doc09' => '"school_name","decision","jury_ref"', 'hi_doc10' => '"school_name","decision","jury_ref"'] as $code => $variables) {
            $this->type(self::OTHER[0], self::OTHER[1], $code, '[' . $variables . ']');
            foreach (['fr', 'en'] as $locale) {
                $this->template(self::OTHER[0], self::OTHER[1], $code, $locale, 3, '<p>{{u01_code}}</p>', 'O' . $code . $locale);
            }
        }
        // Un établissement a déjà un modèle à tableau : il ne doit pas être touché.
        $this->type(self::CUSTOM[0], self::CUSTOM[1], 'hi_doc09', '["school_name","units_table"]');
        $this->template(self::CUSTOM[0], self::CUSTOM[1], 'hi_doc09', 'fr', 2, '<div>{{table:units_table}} perso</div>', 'Pperso');
    }

    private function type(string $tenant, string $establishment, string $code, string $variables): void
    {
        $this->pdo()->prepare('INSERT INTO document_types (id, tenant_id, establishment_id, code, label, category, type_letter, number_template, qr_enabled, blockable, variables, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([substr('01T' . md5($tenant . $code) . 'XXXXXXXXXXXX', 0, 26), $tenant, $establishment, $code, 'Relevé', 'bulletin', 'R', 'REL-{ECOLE}-{AAAA}-{N6}', 1, 0, $variables, '2026-01-01T00:00:00+01:00', '2026-01-01T00:00:00+01:00']);
    }

    private function template(string $tenant, string $establishment, string $code, string $locale, int $version, string $html, string $seed): void
    {
        $this->pdo()->prepare('INSERT INTO document_templates (id, tenant_id, establishment_id, type_code, locale, html, version, validated_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([strtoupper(substr('01J' . md5($seed) . 'ZZZZZZZZZZZZ', 0, 26)), $tenant, $establishment, $code, $locale, $html, $version, '2026-01-01T00:00:00+01:00', null, '2026-01-01T00:00:00+01:00']);
    }

    private function migrate(): void
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(dirname(__DIR__, 4) . '/' . self::MIGRATION));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->pdo()->exec($statement);
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        return $this->pdo()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testLaMigrationDeclareLesVariablesEtAjouteUneVersionValideeSansPerdreLAncienne(): void
    {
        $this->migrate();

        $variables = (string) $this->pdo()->query("SELECT variables FROM document_types WHERE code = 'hi_doc09' AND tenant_id = '" . self::T . "'")->fetchColumn();
        self::assertStringContainsString('"units_table"', $variables);
        self::assertStringContainsString('"rank_info"', $variables);
        self::assertStringContainsString('"jury_ref"', $variables, 'Les variables d\'origine restent.');
        $annual = (string) $this->pdo()->query("SELECT variables FROM document_types WHERE code = 'hi_doc10' AND tenant_id = '" . self::T . "'")->fetchColumn();
        self::assertStringContainsString('"annual_table"', $annual);

        foreach (['hi_doc09', 'hi_doc10'] as $code) {
            foreach (['fr', 'en'] as $locale) {
                $versions = $this->rows("SELECT version, html, validated_at FROM document_templates WHERE tenant_id = '" . self::T . "' AND type_code = '" . $code . "' AND locale = '" . $locale . "' ORDER BY version");
                self::assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['version'], $versions), $code . ' ' . $locale);
                self::assertStringContainsString('{{u01_code}}', $versions[0]['html'], 'Ancienne version conservée telle quelle.');
                self::assertStringContainsString('{{table:' . ($code === 'hi_doc09' ? 'units_table' : 'annual_table') . '}}', $versions[1]['html']);
                self::assertNotNull($versions[1]['validated_at'], 'Nouvelle version validée donc active.');
            }
        }
        $other = $this->rows("SELECT locale, version FROM document_templates WHERE tenant_id = '" . self::OTHER[0] . "' AND type_code = 'hi_doc09' ORDER BY locale, version");
        self::assertSame([['locale' => 'en', 'version' => 3], ['locale' => 'en', 'version' => 4], ['locale' => 'fr', 'version' => 3], ['locale' => 'fr', 'version' => 4]], $other, 'Autre client : version suivante.');
        $ids = array_column($this->rows('SELECT id FROM document_templates'), 'id');
        self::assertSame(count($ids), count(array_unique($ids)), 'Identifiants uniques entre établissements.');
        self::assertSame(1, count($this->rows("SELECT id FROM document_templates WHERE tenant_id = '" . self::CUSTOM[0] . "'")), 'Modèle déjà à tableau : jamais touché.');
    }

    public function testLaMigrationEstIdempotente(): void
    {
        $this->migrate();
        $before = $this->rows('SELECT id, version, html FROM document_templates ORDER BY id');
        $variables = $this->rows('SELECT code, variables FROM document_types ORDER BY id');
        $this->migrate();

        self::assertSame($before, $this->rows('SELECT id, version, html FROM document_templates ORDER BY id'));
        self::assertSame($variables, $this->rows('SELECT code, variables FROM document_types ORDER BY id'));
    }

    public function testLeModeleMisANiveauNAPasDeLimiteDeDixUe(): void
    {
        $this->migrate();

        [$response, $json] = $this->api('POST', '/higher/deliberation/transcripts', 'jury-prep', self::T, self::E, ['person_id' => 'marie', 'period_id' => 'S9']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $content = (string) $this->pdo()->query("SELECT content FROM documents WHERE id = '" . $json['data']['document_id'] . "'")->fetchColumn();
        self::assertStringContainsString('U912', $content);
    }
}
