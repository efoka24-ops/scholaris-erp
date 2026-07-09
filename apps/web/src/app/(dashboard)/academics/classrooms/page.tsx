"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { ClassRoom, Level } from "@/types/structure";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/shared/data-table";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

export default function ClassroomsPage() {
  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [levels, setLevels] = useState<Level[]>([]);
  const [levelFilter, setLevelFilter] = useState<string>("");
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    resourceClient.get<Level[]>("/levels").then((response) => setLevels(response.data));
  }, []);

  useEffect(() => {
    setIsLoading(true);
    resourceClient
      .get<ClassRoom[]>("/classrooms", { params: levelFilter ? { levelId: levelFilter } : {} })
      .then((response) => setClassrooms(response.data))
      .finally(() => setIsLoading(false));
  }, [levelFilter]);

  const levelById = useMemo(() => new Map(levels.map((level) => [level.id, level])), [levels]);

  const columns: ColumnDef<ClassRoom>[] = [
    { accessorKey: "code", header: "Code" },
    { accessorKey: "name", header: "Classe" },
    {
      id: "level",
      header: "Niveau",
      cell: ({ row }) => levelById.get(row.original.levelId)?.name ?? "—",
    },
    { accessorKey: "capacity", header: "Capacité" },
    { accessorKey: "section", header: "Section" },
  ];

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Classes</h1>
          <p className="text-sm text-muted-foreground">Toutes les classes de l'établissement</p>
        </div>
        <Button asChild size="sm">
          <Link href="/academics/classrooms/new">
            <Plus className="mr-2 h-4 w-4" />
            Nouvelle classe
          </Link>
        </Button>
      </div>

      <div className="flex items-center gap-2">
        <label htmlFor="level-filter" className="text-sm text-muted-foreground">
          Filtrer par niveau
        </label>
        <select
          id="level-filter"
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
          value={levelFilter}
          onChange={(event) => setLevelFilter(event.target.value)}
        >
          <option value="">Tous les niveaux</option>
          {levels.map((level) => (
            <option key={level.id} value={level.id}>
              {level.name}
            </option>
          ))}
        </select>
      </div>

      {isLoading ? (
        <LoadingSpinner label="Chargement des classes…" />
      ) : (
        <DataTable columns={columns} data={classrooms} emptyLabel="Aucune classe pour ce filtre" />
      )}
    </div>
  );
}
