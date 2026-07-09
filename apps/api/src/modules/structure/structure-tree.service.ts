import { Injectable } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";

@Injectable()
export class StructureTreeService {
  constructor(private readonly prisma: PrismaService) {}

  /**
   * Arborescence complète : cycles → (filières/programmes → niveaux) et
   * niveaux directement rattachés au cycle (sans filière, ex: collège) →
   * classes. Un seul aller-retour DB, tri par `order`/nom à chaque niveau.
   */
  async getTree() {
    return this.prisma.cycle.findMany({
      orderBy: { order: "asc" },
      include: {
        programs: {
          orderBy: { name: "asc" },
          include: {
            levels: {
              orderBy: { order: "asc" },
              include: { classrooms: { orderBy: { name: "asc" } } },
            },
          },
        },
        levels: {
          where: { programId: null },
          orderBy: { order: "asc" },
          include: { classrooms: { orderBy: { name: "asc" } } },
        },
      },
    });
  }
}
