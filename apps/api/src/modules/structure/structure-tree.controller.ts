import { Controller, Get } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { StructureTreeService } from "./structure-tree.service";

@ApiTags("structure/tree")
@ApiBearerAuth()
@Controller("structure")
export class StructureTreeController {
  constructor(private readonly structureTreeService: StructureTreeService) {}

  @Get("tree")
  @RequirePermissions("structure:read")
  getTree() {
    return this.structureTreeService.getTree();
  }
}
