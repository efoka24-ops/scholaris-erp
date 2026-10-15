<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\factory;
use function DI\get;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Documents\Application\DocumentService;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentCatalog;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Documents\Domain\QrCodeGenerator;
use Scholaris\Modules\Socle\Documents\Infrastructure\DompdfRenderer;
use Scholaris\Modules\Socle\Documents\Infrastructure\LibraryQrCodeGenerator;
use Scholaris\Modules\Socle\Documents\Infrastructure\PdoDocumentCatalog;
use Scholaris\Modules\Socle\Documents\Infrastructure\PlaceholderPdfRenderer;
use Scholaris\Modules\Socle\Documents\Infrastructure\SimulatedQrCodeGenerator;
use Scholaris\Modules\Socle\Integrations\Contracts\PdfRenderer;

/**
 * Liaisons du module Documents : contrat public, rendu PDF et code QR.
 * Valeur vide (« auto ») : réel en production, simulé ailleurs (local, recette, tests).
 */
return [
    DocumentIssuer::class => get(DocumentService::class),
    // Émission avec options pour les applications (S12, S16, S17) ; gardes d'émission : clé de liste par application (S18).
    \Scholaris\Modules\Socle\Documents\Contracts\ExtendedDocumentIssuer::class => get(DocumentService::class),
    'documents.issuance_guards.socle' => [],
    DocumentCatalog::class => autowire(PdoDocumentCatalog::class),
    PdfRenderer::class => factory(static function (Config $config): PdfRenderer {
        $choice = strtolower((string) $config->get('documents.pdf_renderer', ''));
        $real = $choice === 'dompdf' || ($choice === '' && $config->get('app.env') === 'production');
        if (!$real) {
            return new PlaceholderPdfRenderer();
        }
        $cache = (string) $config->get('documents.pdf_cache_path', 'storage/cache/dompdf');
        if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $cache)) {
            $cache = dirname(__DIR__, 4) . '/' . $cache;
        }

        return new DompdfRenderer($cache, (string) $config->get('documents.pdf_paper', 'A4'));
    }),
    QrCodeGenerator::class => factory(static function (Config $config): QrCodeGenerator {
        $choice = strtolower((string) $config->get('documents.qr_generator', ''));
        $real = $choice === 'library' || ($choice === '' && $config->get('app.env') === 'production');

        return $real ? new LibraryQrCodeGenerator() : new SimulatedQrCodeGenerator();
    }),

    // Export complet du client (tenant:export) : liste blanche de colonnes, aucun secret.
    'privacy.tenant_data_source.documents' => \DI\autowire(\Scholaris\Modules\Socle\Documents\Infrastructure\PrivacyExportSource::class),
];
