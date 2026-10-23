<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Application\HigherDocumentCatalog;
use Tests\Support\Higher\Billing\HigherFinanceTestCase;

/** Catalogue complet DOC-01 à DOC-32 (CDC) : un type par numéro, modèles FR et EN, jamais « BTS » ni « HND » (RG-EXN-3). */
final class DocumentCatalogCompletenessTest extends HigherFinanceTestCase
{
    private const RESERVED = ['holder_name', 'number', 'verification_url', 'qr', 'establishment_name', 'issued_on', 'year', 'signature', 'stamp'];

    protected function setUp(): void
    {
        $this->bootFinance();
        $this->installDocuments();
    }

    /** @return array<string, array<string, mixed>> */
    private function catalog(): array
    {
        [, $json] = $this->api('GET', '/higher/documents/catalog', 'admin', self::T, self::E);

        return array_column($json['data'], null, 'code');
    }

    public function testLesTrenteDeuxTypesSontDeclares(): void
    {
        $types = $this->catalog();
        $expected = array_map(static fn (int $n): string => sprintf('DOC-%02d', $n), range(1, 32));
        self::assertSame($expected, array_keys($types));
        $prefixes = array_column($types, 'prefix');
        self::assertSame($prefixes, array_unique($prefixes), 'Préfixes uniques.');
        foreach ($types as $code => $type) {
            self::assertMatchesRegularExpression('/^[A-Z]{3}$/', $type['prefix'], $code);
            if (!$type['issued_by_socle']) {
                self::assertSame($type['prefix'] . '-{ECOLE}-{AAAA}-{N6}', $type['number_template'], $code);
            }
        }
    }

    public function testModelesFrEtEnSansVariablesReserveesNiBtsHnd(): void
    {
        foreach ($this->catalog() as $code => $type) {
            self::assertDoesNotMatchRegularExpression(HigherDocumentCatalog::FORBIDDEN_TITLE, $type['label_fr'] . ' ' . $type['label_en'], $code);
            if ($type['issued_by_socle']) {
                continue;
            }
            self::assertSame(['fr', 'en'], array_column($type['socle']['templates'], 'locale'), $code);
            foreach ($type['socle']['templates'] as $template) {
                self::assertDoesNotMatchRegularExpression('/\b(BTS|HND)\b/i', $template['html'], $code);
            }
            $own = array_diff($type['variables'], HigherDocumentCatalog::COMMON_VARIABLES);
            self::assertSame([], array_intersect($own, self::RESERVED), $code . ' ne déclare aucune variable réservée.');
        }
    }

    public function testLesTypesAjoutesPorteSignataireQrEtBlocage(): void
    {
        $types = $this->catalog();
        $expected = [
            'DOC-11' => ['ARL', 'direction_groupe', true, true],
            'DOC-18' => ['LIT', 'direction_groupe', false, false],
            'DOC-19' => ['RQN', 'higher_chef_dept', false, false],
            'DOC-24' => ['CVA', 'higher_dir_ecole', true, false],

        ];
        foreach ($expected as $code => [$prefix, $signer, $qr, $blockable]) {
            self::assertSame([$prefix, $signer, $qr, $blockable], [$types[$code]['prefix'], $types[$code]['signer_role'], $types[$code]['qr'], $types[$code]['blockable']], $code);
        }
        self::assertStringContainsString('{{qr}}', $types['DOC-24']['socle']['templates'][0]['html']);
        self::assertStringNotContainsString('{{qr}}', $types['DOC-18']['socle']['templates'][1]['html']);
    }
}
