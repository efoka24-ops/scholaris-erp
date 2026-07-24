import { Body, Controller, ForbiddenException, Get, Param, Post, Put } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { TenantsService } from "./tenants.service";
import { CreateTenantDto } from "./dto/create-tenant.dto";
import { UpdateTenantDto } from "./dto/update-tenant.dto";
import { UpdateEnabledModulesDto } from "./dto/update-enabled-modules.dto";

@ApiTags("tenants")
@ApiBearerAuth()
@Controller("tenants")
export class TenantsController {
  constructor(private readonly tenantsService: TenantsService) {}

  // Gestion multi-établissements (Super Admin) : création/liste transverses,
  // gardées par tenants:create (permission détenue uniquement par le Super Admin).
  @Post()
  @RequirePermissions("tenants:create")
  @ApiOperation({ summary: "Créer un nouvel établissement (Super Admin)" })
  create(@Body() dto: CreateTenantDto) {
    return this.tenantsService.create(dto);
  }

  @Get()
  @RequirePermissions("tenants:create")
  @ApiOperation({ summary: "Lister tous les établissements (Super Admin)" })
  findAll() {
    return this.tenantsService.findAll();
  }

  @Get(":id")
  @RequirePermissions("tenants:read")
  findOne(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser) {
    this.assertOwnTenant(id, user);
    return this.tenantsService.findOne(id);
  }

  @Put(":id")
  @RequirePermissions("tenants:update")
  @ApiOperation({ summary: "Met à jour les informations générales de l'établissement" })
  update(@Param("id") id: string, @Body() dto: UpdateTenantDto, @CurrentUser() user: AuthenticatedUser) {
    this.assertOwnTenant(id, user);
    return this.tenantsService.update(id, dto);
  }

  @Get(":id/config")
  @RequirePermissions("tenants:read")
  @ApiOperation({ summary: "Configuration du moteur de calcul (config_json)" })
  getConfig(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser) {
    this.assertOwnTenant(id, user);
    return this.tenantsService.getConfig(id);
  }

  @Put(":id/config")
  @RequirePermissions("tenants:update")
  @ApiOperation({ summary: "Remplace la configuration du moteur de calcul (validée par Zod, 400 si invalide)" })
  updateConfig(@Param("id") id: string, @Body() config: unknown, @CurrentUser() user: AuthenticatedUser) {
    this.assertOwnTenant(id, user);
    return this.tenantsService.updateConfig(id, config);
  }

  @Get(":id/bulletin-groups")
  @RequirePermissions("tenants:read")
  @ApiOperation({ summary: "Configuration des groupes de matières du bulletin" })
  getBulletinGroups(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser) {
    this.assertOwnTenant(id, user);
    return this.tenantsService.getBulletinGroups(id);
  }

  @Put(":id/bulletin-groups")
  @RequirePermissions("tenants:update")
  @ApiOperation({ summary: "Définit les groupes de matières et l'affectation des matières" })
  updateBulletinGroups(
    @Param("id") id: string,
    @Body() config: any,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    this.assertOwnTenant(id, user);
    return this.tenantsService.updateBulletinGroups(id, config);
  }

  @Get(":id/modules")
  @RequirePermissions("tenants:read")
  @ApiOperation({ summary: "Modules/fonctionnalités activés pour cet établissement" })
  getEnabledModules(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser) {
    this.assertOwnTenant(id, user);
    return this.tenantsService.getEnabledModules(id);
  }

  @Put(":id/modules")
  @RequirePermissions("tenants:update")
  @ApiOperation({ summary: "Remplace la liste des modules/fonctionnalités activés pour cet établissement" })
  updateEnabledModules(
    @Param("id") id: string,
    @Body() dto: UpdateEnabledModulesDto,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    this.assertOwnTenant(id, user);
    return this.tenantsService.updateEnabledModules(id, dto.enabledModules);
  }

  /** Le modèle Tenant ne porte pas de tenant_id : l'isolation est vérifiée ici. */
  private assertOwnTenant(id: string, user: AuthenticatedUser): void {
    if (id !== user.tenantId) {
      throw new ForbiddenException("Accès refusé à un autre établissement");
    }
  }
}
