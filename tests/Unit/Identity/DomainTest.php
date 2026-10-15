<?php

declare(strict_types=1);

namespace Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Identity\Application\PasswordHasher;
use Scholaris\Modules\Socle\Identity\Application\SecretCipher;
use Scholaris\Modules\Socle\Identity\Domain\Identifier;
use Scholaris\Modules\Socle\Identity\Domain\PasswordPolicy;
use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;

final class DomainTest extends TestCase
{
    public function testIdentifiantTelephoneNormalise(): void
    {
        foreach (['691123456', '6 91 12 34 56', '+237 691 12 34 56', '00237691123456', '237691123456'] as $raw) {
            $id = Identifier::fromInput($raw);
            self::assertTrue($id->valid, $raw);
            self::assertFalse($id->isEmail);
            self::assertSame('+237691123456', $id->value, $raw);
        }
        self::assertSame('+237 6•• •• 34 56', Identifier::fromInput('691123456')->mask());
    }

    public function testIdentifiantEmail(): void
    {
        $id = Identifier::fromInput('  Awa.Njoya@Exemple.CM ');

        self::assertTrue($id->isEmail);
        self::assertTrue($id->valid);
        self::assertSame('awa.njoya@exemple.cm', $id->value);
        self::assertSame('a•••@exemple.cm', $id->mask());
        self::assertFalse(Identifier::fromInput('pas@un')->valid);
        self::assertFalse(Identifier::fromInput('abc')->valid);
        self::assertSame(Identifier::fromInput('691123456')->key(), Identifier::fromInput('+237 691123456')->key());
    }

    public function testPolitiqueDeMotDePasse(): void
    {
        $policy = new PasswordPolicy();

        self::assertSame([], $policy->violations('Mangue-Verte-Soleil-77'));
        self::assertNotSame([], $policy->violations('Court1!'));
        self::assertNotSame([], $policy->violations('password123'));
        self::assertNotSame([], $policy->violations('Password123'), 'Insensible à la casse.');
        self::assertNotSame([], $policy->violations('P@ssw0rd!!'), 'Variante l33t.');
        self::assertNotSame([], $policy->violations('azertyuiop'));
        self::assertNotSame([], $policy->violations('abcabcabcabc'));
        self::assertNotSame([], $policy->violations('xx+237691123456xx', ['+237691123456']));
        self::assertNotSame([], $policy->violations(str_repeat('a1', 70)));
    }

    public function testArgon2idEtChiffrement(): void
    {
        $hasher = new PasswordHasher(1024, 1);
        $hash = $hasher->hash('Mangue-Verte-Soleil-77');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('Mangue-Verte-Soleil-77', $hash));
        self::assertFalse($hasher->verify('autre', $hash));

        $cipher = new SecretCipher('une-cle');
        $encrypted = $cipher->encrypt('JBSWY3DPEHPK3PXP');
        self::assertStringNotContainsString('JBSW', $encrypted);
        self::assertSame('JBSWY3DPEHPK3PXP', $cipher->decrypt($encrypted));
        $this->expectException(\RuntimeException::class);
        (new SecretCipher(''))->encrypt('x');
    }

    public function testValiditeEtPerimetreDUneAffectation(): void
    {
        $make = static fn (ScopeType $type, ?string $est, string $start, ?string $end): RoleAssignment => new RoleAssignment(
            'a',
            't1',
            'p',
            'directeur',
            $type,
            $est,
            $est,
            $start,
            $end,
            null,
            null,
            '2026-10-01T00:00:00+01:00',
        );
        $a = $make(ScopeType::Establishment, 'e1', '2026-10-01T00:00:00+01:00', '2026-10-10T00:00:00+01:00');

        self::assertFalse($a->isActiveAt('2026-09-30T23:59:59+01:00'));
        self::assertTrue($a->isActiveAt('2026-10-05T10:00:00+01:00'));
        self::assertFalse($a->isActiveAt('2026-10-10T00:00:00+01:00'));
        self::assertTrue($a->appliesTo('e1'));
        self::assertFalse($a->appliesTo('e2'));
        self::assertFalse($a->appliesTo(null));
        self::assertTrue($make(ScopeType::Tenant, null, '2026-10-01T00:00:00+01:00', null)->appliesTo('n-importe-lequel'));
        self::assertTrue($make(ScopeType::Tenant, null, '2026-10-01T00:00:00+01:00', null)->appliesTo(null));
    }
}
