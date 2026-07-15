import { IsString, IsNotEmpty, IsEnum, IsOptional } from 'class-validator';

export class CreateIncidentDto {
  @IsString()
  @IsNotEmpty()
  studentId!: string;

  @IsString()
  @IsNotEmpty()
  incidentDate!: string;

  @IsString()
  @IsNotEmpty()
  type!: string; // TARDINESS, ABSENCE, DISRUPTION, VIOLENCE, etc.

  @IsEnum(['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])
  severity!: string;

  @IsString()
  @IsNotEmpty()
  description!: string;

  @IsString()
  @IsOptional()
  location?: string;
}
