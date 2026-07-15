import { IsString, IsNotEmpty, IsOptional } from 'class-validator';

export class CreateSanctionDto {
  @IsString()
  @IsNotEmpty()
  incidentId!: string;

  @IsString()
  @IsNotEmpty()
  type!: string; // WARNING, DETENTION, SUSPENSION, EXPULSION

  @IsString()
  @IsNotEmpty()
  description!: string;

  @IsString()
  @IsOptional()
  startDate?: string;

  @IsString()
  @IsOptional()
  endDate?: string;
}
