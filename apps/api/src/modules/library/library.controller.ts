import { Controller, Get, Post, Put, Delete, Body, Param, Query, UseGuards } from '@nestjs/common';
import { LibraryService } from './library.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../common/guards/permissions.guard';
import { RequirePermissions } from '../../common/decorators/require-permissions.decorator';
import { CurrentUser } from '../../common/decorators/current-user.decorator';
import type { AuthenticatedUser } from '../auth/jwt-payload.interface';

@Controller('library')
@UseGuards(JwtAuthGuard, PermissionsGuard)
export class LibraryController {
  constructor(private readonly libraryService: LibraryService) {}

  @Get('books')
  @RequirePermissions('library:read')
  async findAllBooks(@CurrentUser() user: AuthenticatedUser, @Query() query: any) {
    return this.libraryService.findAllBooks(user.tenantId, query);
  }

  @Post('books')
  @RequirePermissions('library:create')
  async createBook(@CurrentUser() user: AuthenticatedUser, @Body() dto: any) {
    return this.libraryService.createBook(user.tenantId, dto);
  }

  @Post('borrow')
  @RequirePermissions('library:create')
  async borrowBook(@CurrentUser() user: AuthenticatedUser, @Body() dto: any) {
    return this.libraryService.borrowBook(user.tenantId, dto);
  }

  @Put('return/:borrowId')
  @RequirePermissions('library:update')
  async returnBook(@CurrentUser() user: AuthenticatedUser, @Param('borrowId') borrowId: string) {
    return this.libraryService.returnBook(user.tenantId, borrowId);
  }
}
