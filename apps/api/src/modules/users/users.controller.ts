import {
  Body,
  Controller,
  Delete,
  Get,
  Param,
  Post,
  Put,
  Query,
} from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiResponse, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { UsersService } from "./users.service";
import { CreateUserDto } from "./dto/create-user.dto";
import { UpdateUserDto } from "./dto/update-user.dto";
import { FindUsersQueryDto } from "./dto/find-users-query.dto";
import { AssignRolesDto } from "./dto/assign-roles.dto";
import { UpdateUserStatusDto } from "./dto/update-user-status.dto";

@ApiTags("users")
@ApiBearerAuth()
@Controller("users")
export class UsersController {
  constructor(private readonly usersService: UsersService) {}

  @Get()
  @RequirePermissions("users:read")
  @ApiOperation({ summary: "Get all users with pagination and filters" })
  @ApiResponse({ status: 200, description: "Users retrieved successfully" })
  findAll(
    @Query() query: FindUsersQueryDto,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.usersService.findAll(query, user.tenantId);
  }

  @Get("stats")
  @RequirePermissions("users:read")
  @ApiOperation({ summary: "Get user statistics" })
  @ApiResponse({ status: 200, description: "Statistics retrieved successfully" })
  getStats(@CurrentUser() user: AuthenticatedUser) {
    return this.usersService.getStats(user.tenantId);
  }

  @Get(":id")
  @RequirePermissions("users:read")
  @ApiOperation({ summary: "Get a single user by ID" })
  @ApiResponse({ status: 200, description: "User retrieved successfully" })
  @ApiResponse({ status: 404, description: "User not found" })
  findOne(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser) {
    return this.usersService.findOne(id, user.tenantId);
  }

  @Post()
  @RequirePermissions("users:create")
  @ApiOperation({ summary: "Create a new user" })
  @ApiResponse({ status: 201, description: "User created successfully" })
  @ApiResponse({ status: 409, description: "User with this email already exists" })
  create(@Body() dto: CreateUserDto, @CurrentUser() user: AuthenticatedUser) {
    return this.usersService.create(dto, user.tenantId);
  }

  @Put(":id")
  @RequirePermissions("users:update")
  @ApiOperation({ summary: "Update a user" })
  @ApiResponse({ status: 200, description: "User updated successfully" })
  @ApiResponse({ status: 404, description: "User not found" })
  update(
    @Param("id") id: string,
    @Body() dto: UpdateUserDto,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.usersService.update(id, dto, user.tenantId);
  }

  @Delete(":id")
  @RequirePermissions("users:delete")
  @ApiOperation({ summary: "Delete a user (soft delete)" })
  @ApiResponse({ status: 200, description: "User deleted successfully" })
  @ApiResponse({ status: 404, description: "User not found" })
  remove(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser) {
    return this.usersService.remove(id, user.tenantId);
  }

  @Put(":id/status")
  @RequirePermissions("users:update")
  @ApiOperation({ summary: "Activer, suspendre ou désactiver un compte utilisateur" })
  @ApiResponse({ status: 200, description: "Statut mis à jour" })
  @ApiResponse({ status: 404, description: "User not found" })
  updateStatus(
    @Param("id") id: string,
    @Body() dto: UpdateUserStatusDto,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.usersService.updateStatus(id, dto.status, user.tenantId);
  }

  @Post(":id/reset-password")
  @RequirePermissions("users:update")
  @ApiOperation({ summary: "Génère un mot de passe temporaire pour l'utilisateur" })
  @ApiResponse({ status: 200, description: "Mot de passe temporaire généré" })
  @ApiResponse({ status: 404, description: "User not found" })
  resetPassword(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser) {
    return this.usersService.resetPassword(id, user.tenantId);
  }

  @Put(":id/roles")
  @RequirePermissions("users:assign-roles")
  @ApiOperation({ summary: "Assign roles to a user" })
  @ApiResponse({ status: 200, description: "Roles assigned successfully" })
  @ApiResponse({ status: 404, description: "User not found" })
  assignRoles(
    @Param("id") id: string,
    @Body() dto: AssignRolesDto,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.usersService.assignRoles(id, dto.roleIds, user.tenantId);
  }
}
