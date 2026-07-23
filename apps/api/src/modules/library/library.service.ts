import { Injectable } from '@nestjs/common';
import { PrismaService } from '../../prisma/prisma.service';

@Injectable()
export class LibraryService {
  constructor(private prisma: PrismaService) {}

  async findAllBooks(tenantId: string, query: any) {
    const { page = 1, limit = 50, search } = query;
    const skip = (page - 1) * limit;
    const where: any = { tenantId };

    if (search) {
      where.OR = [
        { title: { contains: search, mode: 'insensitive' } },
        { author: { contains: search, mode: 'insensitive' } },
        { isbn: { contains: search } },
      ];
    }

    const [items, total] = await Promise.all([
      this.prisma.libraryBook.findMany({ where, skip, take: limit }),
      this.prisma.libraryBook.count({ where }),
    ]);

    return { items, total, page, limit, totalPages: Math.ceil(total / limit) };
  }

  async createBook(tenantId: string, dto: any) {
    return this.prisma.libraryBook.create({
      data: { ...dto, tenantId },
    });
  }

  async borrowBook(tenantId: string, dto: any) {
    // dueDate vient d'un <input type="date"> (chaîne "AAAA-MM-JJ") : conversion
    // explicite en DateTime pour Prisma (évite un 500 sur l'emprunt).
    return this.prisma.libraryBorrow.create({
      data: {
        ...dto,
        ...(dto.dueDate ? { dueDate: new Date(dto.dueDate) } : {}),
        tenantId,
        borrowDate: new Date(),
        status: 'BORROWED',
      },
    });
  }

  async returnBook(tenantId: string, borrowId: string) {
    return this.prisma.libraryBorrow.update({
      where: { id: borrowId },
      data: { returnDate: new Date() },
    });
  }
}
