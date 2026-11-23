<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Documents\Contracts\ExtendedDocumentIssuer;
use Scholaris\Modules\Socle\Documents\Contracts\IssueOptions;
use Scholaris\Modules\Socle\Documents\Domain\DocumentTable;
use Tests\Support\Documents\DocumentsTestCase;

/** DOC-BUL : tableaux structurés {{table:x}} à l'émission, variables facultatives d'une version plus récente du modèle. */
final class TableTemplateTest extends DocumentsTestCase
{
    protected function setUp(): void
    {
        $this->bootDocuments();
    }

    public function testLeTableauEstRenduParLeMoteurEtSesCellulesEchappees(): void
    {
        [$created] = $this->api('POST', '/document-types', 'alice', 't1', 'e1', [
            'code' => 'bulletin_test', 'label' => 'Bulletin', 'category' => 'bulletin', 'type_letter' => 'B', 'variables' => ['subjects_table'],
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        [$refused] = $this->api('POST', '/document-templates', 'alice', 't1', 'e1', ['type_code' => 'bulletin_test', 'locale' => 'fr', 'html' => '<div>{{table:moyennes}}</div>']);
        self::assertSame(422, $refused->getStatusCode(), 'Un tableau de variable non déclarée est refusé.');
        [$stored, $json] = $this->api('POST', '/document-templates', 'alice', 't1', 'e1', ['type_code' => 'bulletin_test', 'locale' => 'fr', 'html' => '<html><body><div>{{table:subjects_table}}</div></body></html>']);
        self::assertSame(201, $stored->getStatusCode(), (string) $stored->getBody());
        $this->api('POST', '/document-templates/' . $json['data']['id'] . '/validate', 'alice', 't1');

        $table = DocumentTable::encode(['columns' => ['Matière', 'Moyenne'], 'rows' => [['<script>x</script>', '12,50'], ['kind' => 'total', 'cells' => ['TOTAL', '12,50']]]]);
        $doc = $this->issuer()->issue('t1', 'e1', 'bulletin_test', 'p1', ['subjects_table' => $table], 'alice');

        $content = (string) $this->connections()->pdo()->query("SELECT content FROM documents WHERE id = '" . $doc->id . "'")->fetchColumn();
        self::assertStringContainsString('<thead><tr><th>Matière</th><th>Moyenne</th></tr></thead>', $content);
        self::assertStringContainsString('<td>&lt;script&gt;x&lt;/script&gt;</td>', $content);
        self::assertStringContainsString('<tr class="doc-row-total">', $content);

        try {
            $this->issuer()->issue('t1', 'e1', 'bulletin_test', 'p1', ['subjects_table' => '{"rows": [{"kind": "x", "cells": []}]}'], 'alice');
            self::fail('Un tableau mal formé est refusé.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testUneVariableFacultativeNonDeclareeParLeTypeInstalleEstIgnoree(): void
    {
        $issuer = $this->issuer();
        self::assertInstanceOf(ExtendedDocumentIssuer::class, $issuer);
        $data = ['academic_year' => '2026-2027', 'class_name' => '3eA', 'units_table' => '{"rows": []}'];

        $doc = $issuer->issueWithOptions('t1', 'e1', 'attestation_simple', 'p1', $data, 'alice', 'fr', new IssueOptions(optionalVariables: ['units_table']));
        self::assertSame('valid', $doc->status);

        $this->expectException(HttpException::class);
        $issuer->issueWithOptions('t1', 'e1', 'attestation_simple', 'p1', $data, 'alice', 'fr', new IssueOptions());
    }
}
