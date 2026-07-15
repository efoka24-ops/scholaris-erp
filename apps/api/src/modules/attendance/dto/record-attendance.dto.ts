import { IsString, IsNotEmpty, IsArray, IsEnum, IsOptional, ValidateNested } from 'class-validator';
import { Type } from 'class-transformer';

class AttendanceRecordItem {
  @IsString()
  @IsNotEmpty()
  enrollmentId!: string;

  @IsEnum(['PRESENT', 'ABSENT', 'LATE', 'EXCUSED'])
  status!: string;

  @IsString()
  @IsOptional()
  notes?: string;
}

export class RecordAttendanceDto {
  @IsString()
  @IsNotEmpty()
  date!: string;

  @IsArray()
  @ValidateNested({ each: true })
  @Type(() => AttendanceRecordItem)
  records!: AttendanceRecordItem[];
}
