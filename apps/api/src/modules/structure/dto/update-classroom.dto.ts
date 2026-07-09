import { PartialType } from "@nestjs/swagger";
import { CreateClassRoomDto } from "./create-classroom.dto";

export class UpdateClassRoomDto extends PartialType(CreateClassRoomDto) {}
