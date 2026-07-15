import { IsString, IsNotEmpty, IsInt, Min, Max, IsOptional } from 'class-validator';

export class CreateTimetableDto {
  @IsString()
  @IsNotEmpty()
  classroomId!: string;

  @IsString()
  @IsNotEmpty()
  subjectId!: string;

  @IsString()
  @IsOptional()
  teacherId?: string;

  @IsString()
  @IsNotEmpty()
  roomId!: string;

  @IsString()
  @IsNotEmpty()
  academicYearId!: string;

  @IsInt()
  @Min(1)
  @Max(7)
  dayOfWeek!: number; // 1 = Lundi, 7 = Dimanche

  @IsString()
  @IsNotEmpty()
  startTime!: string; // Format HH:mm

  @IsString()
  @IsNotEmpty()
  endTime!: string; // Format HH:mm
}
