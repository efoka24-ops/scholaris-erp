import { Module } from "@nestjs/common";
import { CyclesController } from "./cycles.controller";
import { CyclesService } from "./cycles.service";
import { DepartmentsController } from "./departments.controller";
import { DepartmentsService } from "./departments.service";
import { ProgramsController } from "./programs.controller";
import { ProgramsService } from "./programs.service";
import { LevelsController } from "./levels.controller";
import { LevelsService } from "./levels.service";
import { RoomsController } from "./rooms.controller";
import { RoomsService } from "./rooms.service";
import { ClassroomsController } from "./classrooms.controller";
import { ClassroomsService } from "./classrooms.service";
import { StructureTreeController } from "./structure-tree.controller";
import { StructureTreeService } from "./structure-tree.service";

@Module({
  controllers: [
    CyclesController,
    DepartmentsController,
    ProgramsController,
    LevelsController,
    RoomsController,
    ClassroomsController,
    StructureTreeController,
  ],
  providers: [
    CyclesService,
    DepartmentsService,
    ProgramsService,
    LevelsService,
    RoomsService,
    ClassroomsService,
    StructureTreeService,
  ],
})
export class StructureModule {}
