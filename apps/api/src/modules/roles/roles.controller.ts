import { Body, Controller, Delete, Get, Param, Post, Put, Query } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiResponse, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { RolesService } from "./roles.service";
import { CreateRoleDto } from "./dto/create-role.dto";
import { UpdateRoleDto } from "./dto/update-role.dto";
import { FindRolesQueryDto } from "./dto/find-roles-query.dto";

@ApiTags("roles")
@ApiBearerAuth()
@Controller("roles")
export class RolesController {
  constructor(private readonly rolesService: RolesService) {}

  @Get()
  @RequirePermissions("roles:read")
  @ApiOperation({ summary: "Liste des rôles (établissement courant + rôles système)" })
  findAll(@Query() query: FindRolesQueryDto) {
    return this.rolesService.findAll(query);
  }

  @Get(":id")
  @RequirePermissions("roles:read")
  @ApiOperation({ summary: "Détail d'un rôle avec ses permissions" })
  @ApiResponse({ status: 404, description: "Rôle introuvable" })
  findOne(@Param("id") id: string) {
    return this.rolesService.findOne(id);
  }

  @Post()
  @RequirePermissions("roles:create")
  @ApiOperation({ summary: "Créer un rôle personnalisé pour l'établissement courant" })
  @ApiResponse({ status: 201, description: "Rôle créé" })
  @ApiResponse({ status: 409, description: "Un rôle avec ce nom existe déjà" })
  create(@Body() dto: CreateRoleDto, @CurrentUser() user: AuthenticatedUser) {
    return this.rolesService.create(dto, user.tenantId);
  }

  @Put(":id")
  @RequirePermissions("roles:update")
  @ApiOperation({ summary: "Renommer un rôle et/ou remplacer ses permissions" })
  @ApiResponse({ status: 403, description: "Rôle système ou d'un autre établissement" })
  update(@Param("id") id: string, @Body() dto: UpdateRoleDto, @CurrentUser() user: AuthenticatedUser) {
    return this.rolesService.update(id, dto, user.tenantId);
  }

  @Delete(":id")
  @RequirePermissions("roles:delete")
  @ApiOperation({ summary: "Supprimer un rôle personnalisé inutilisé" })
  @ApiResponse({ status: 403, description: "Rôle système ou d'un autre établissement" })
  @ApiResponse({ status: 409, description: "Rôle encore assigné à des utilisateurs" })
  remove(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser) {
    return this.rolesService.remove(id, user.tenantId);
  }
}

@ApiTags("permissions")
@ApiBearerAuth()
@Controller("permissions")
export class PermissionsController {
  constructor(private readonly rolesService: RolesService) {}

  @Get()
  @RequirePermissions("permissions:read")
  @ApiOperation({ summary: "Liste de toutes les permissions disponibles, groupées par ressource/module" })
  findAll() {
    return this.rolesService.findAllPermissionsGrouped();
  }
}
