import { OmitType, PartialType } from "@nestjs/swagger";
import { CreateStudentDto } from "./create-student.dto";

/** Les parents se gèrent via le dossier élève, pas via ce PUT (liaison M:N séparée). */
export class UpdateStudentDto extends PartialType(OmitType(CreateStudentDto, ["parents", "force"] as const)) {}
