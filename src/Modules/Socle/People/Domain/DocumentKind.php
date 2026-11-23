<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Domain;

enum DocumentKind: string
{
    case BirthCertificate = 'birth_certificate';
    case IdPhoto = 'id_photo';
    case PreviousReport = 'previous_report';
    case MedicalCertificate = 'medical_certificate';
    case VaccinationCard = 'vaccination_card';
    case GuardianId = 'guardian_id';
    case ResidenceProof = 'residence_proof';
    case Other = 'other';

    /** @return list<string> pièces comptées dans le dossier (« 5 / 7 ») */
    public static function required(): array
    {
        return [
            self::BirthCertificate->value,
            self::IdPhoto->value,
            self::PreviousReport->value,
            self::MedicalCertificate->value,
            self::VaccinationCard->value,
            self::GuardianId->value,
            self::ResidenceProof->value,
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
