import {
  IsNotEmpty,
  IsNumber,
  IsObject,
  IsOptional,
  IsString,
  IsUrl,
  Min,
} from "class-validator";

/**
 * Corps de la requête d'initiation d'un décaissement CAMOO (POST /cashout).
 * Le champ `phoneNumber` accepte aussi la clé `phone_number` côté CAMOO ;
 * le client HTTP mappe vers la casse attendue par l'API.
 */
export class CreateCashoutDto {
  @IsNumber()
  @Min(1)
  amount!: number;

  @IsString()
  @IsNotEmpty()
  phoneNumber!: string;

  @IsOptional()
  @IsString()
  currency?: string;

  @IsOptional()
  @IsString()
  externalReference?: string;

  @IsOptional()
  @IsUrl({ require_tld: false })
  notificationUrl?: string;

  @IsOptional()
  @IsString()
  studentId?: string;

  @IsOptional()
  @IsString()
  invoiceId?: string;

  @IsOptional()
  @IsObject()
  shoppingCartDetails?: Record<string, unknown>;
}
