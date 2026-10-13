<?php

declare(strict_types=1);

namespace Tests\Unit\People;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Scholaris\Modules\Socle\People\Domain\Completeness;
use Scholaris\Modules\Socle\People\Domain\DocumentKind;
use Scholaris\Modules\Socle\People\Domain\DuplicateMatcher;
use Scholaris\Modules\Socle\People\Domain\EnrollmentStatus;
use Scholaris\Modules\Socle\People\Domain\Majority;
use Scholaris\Modules\Socle\People\Domain\Names;
use Scholaris\Modules\Socle\People\Domain\PersonAttributes;
use Scholaris\Modules\Socle\People\Domain\UploadPolicy;
use Scholaris\Modules\Socle\People\Domain\UrlSigner;
use Scholaris\Modules\Socle\People\Infrastructure\HealthCipher;

final class DomainRulesTest extends TestCase
{
    private const EXISTING = ['first_name' => 'Nadia', 'last_name' => 'Foka', 'birth_date' => '2010-05-14', 'phone' => '+237677123456'];

    public function testTroisCriteresSurTrois(): void
    {
        $input = ['first_name' => 'Nadia', 'last_name' => 'Foka', 'birth_date' => '2010-05-14', 'phone' => '677 12 34 56'];

        self::assertSame(['name', 'birth_date', 'phone'], DuplicateMatcher::criteria($input, self::EXISTING));
    }

    public function testLeNomSeulOuSansNomNeFaitPasUnDoublon(): void
    {
        self::assertSame([], DuplicateMatcher::criteria(['first_name' => 'Nadia', 'last_name' => 'Foka'], self::EXISTING));
        self::assertSame([], DuplicateMatcher::criteria(['first_name' => 'Paul', 'last_name' => 'Ndi', 'birth_date' => '2010-05-14', 'phone' => '677123456'], self::EXISTING));
        self::assertSame(['name', 'phone'], DuplicateMatcher::criteria(['first_name' => 'Nadia', 'last_name' => 'Foka', 'birth_date' => '2011-01-01', 'phone' => '677123456'], self::EXISTING));
    }

    public function testLesNomsIgnorentAccentsCasseEtOrdre(): void
    {
        self::assertSame(Names::key('Éloïse', 'NGO  Bayiha'), Names::key('ngo', 'bayiha eloise'));
        self::assertSame('677123456', Names::phoneKey('+237 6 77 12 34 56'));
        self::assertNull(Names::phoneKey(null));
    }

    public function testMajorite(): void
    {
        self::assertTrue(Majority::isAdult('2008-10-08', '2026-10-08'));
        self::assertFalse(Majority::isAdult('2008-10-09', '2026-10-08'));
        self::assertFalse(Majority::isAdult(null, '2026-10-08'), 'Sans date de naissance : mineur par prudence.');
    }

    public function testPiecesTypesEtTaille(): void
    {
        self::assertSame('pdf', UploadPolicy::inspect("%PDF-1.7\nx")['ext']);
        self::assertSame('image/jpeg', UploadPolicy::inspect("\xFF\xD8\xFF\xE0abc")['mime']);
        self::assertSame('png', UploadPolicy::inspect("\x89PNG\r\n\x1a\nabc")['ext']);

        foreach (['MZ' . str_repeat('x', 100), '<?php echo 1;', '', '%PDF-' . str_repeat('x', UploadPolicy::MAX_BYTES)] as $bad) {
            try {
                UploadPolicy::inspect($bad);
                self::fail('Fichier accepté à tort.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testUrlSigneeExpireEtNeSeFalsifiePas(): void
    {
        $signer = new UrlSigner('secret');
        $signature = $signer->sign('p|d', 1000);

        self::assertTrue($signer->verify('p|d', 1000, $signature, 999));
        self::assertFalse($signer->verify('p|d', 1000, $signature, 1001), 'Expirée.');
        self::assertFalse($signer->verify('p|autre', 1000, $signature, 999), 'Sujet différent.');
        self::assertFalse($signer->verify('p|d', 2000, $signature, 999), 'Expiration modifiée.');
        $this->expectException(RuntimeException::class);
        (new UrlSigner(''))->sign('x', 1);
    }

    public function testChiffrementDeLaSante(): void
    {
        $cipher = new HealthCipher('cle');
        $encrypted = $cipher->encrypt('allergie aux arachides');

        self::assertStringNotContainsString('arachides', $encrypted);
        self::assertSame('allergie aux arachides', $cipher->decrypt($encrypted));
        $this->expectException(RuntimeException::class);
        (new HealthCipher('autre'))->decrypt($encrypted);
    }

    public function testCompletude(): void
    {
        $full = ['first_name' => 'a', 'last_name' => 'b', 'birth_date' => 'x', 'birth_place' => 'x', 'gender' => 'male', 'nationality' => 'x', 'phone' => 'x'];

        self::assertSame(100, Completeness::percent($full, true, true, 7, 7));
        self::assertSame(0, Completeness::percent([], false, false, 0, 7));
        self::assertSame(82, Completeness::percent($full, true, true, 2, 7));
    }

    public function testValidationDeLIdentite(): void
    {
        [$values, $errors] = PersonAttributes::validate(['first_name' => ' Nadia ', 'last_name' => 'Foka', 'phone' => '+237 677-12 34 56', 'gender' => 'female', 'birth_date' => '2010-05-14', 'email' => 'N@X.CM'], '2026-10-08');
        self::assertSame([], $errors);
        self::assertSame('Nadia', $values['first_name']);
        self::assertSame('+237677123456', $values['phone']);
        self::assertSame('n@x.cm', $values['email']);

        [, $errors] = PersonAttributes::validate(['birth_date' => '2030-01-01', 'gender' => 'x', 'phone' => 'abc', 'national_id' => '1', 'locale' => 'de'], '2026-10-08');
        self::assertSame(['first_name', 'last_name', 'birth_date', 'gender', 'phone', 'locale', 'national_id'], array_keys($errors));
    }

    public function testStatutsEtPieces(): void
    {
        self::assertCount(7, DocumentKind::required());
        self::assertContains(EnrollmentStatus::Suspended, EnrollmentStatus::Active->allowedNext());
        self::assertSame([], EnrollmentStatus::Withdrawn->allowedNext());
        self::assertFalse(EnrollmentStatus::Withdrawn->isLive());
    }
}
