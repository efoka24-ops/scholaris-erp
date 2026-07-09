"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { Room } from "@/types/structure";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/shared/data-table";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

const ROOM_TYPE_LABELS: Record<Room["type"], string> = {
  SALLE_CLASSE: "Salle de classe",
  LABORATOIRE: "Laboratoire",
  SALLE_INFO: "Salle informatique",
  AMPHITHEATRE: "Amphithéâtre",
  TERRAIN_SPORT: "Terrain de sport",
};

const columns: ColumnDef<Room>[] = [
  { accessorKey: "code", header: "Code" },
  { accessorKey: "name", header: "Nom" },
  { id: "type", header: "Type", cell: ({ row }) => ROOM_TYPE_LABELS[row.original.type] },
  { accessorKey: "capacity", header: "Capacité" },
  {
    id: "occupancy",
    header: "Classes affectées",
    cell: ({ row }) => `${row.original.classroomsCount} classe(s)`,
  },
];

export default function RoomsPage() {
  const [rooms, setRooms] = useState<Room[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    resourceClient
      .get<Room[]>("/rooms")
      .then((response) => setRooms(response.data))
      .finally(() => setIsLoading(false));
  }, []);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Salles et ressources</h1>
          <p className="text-sm text-muted-foreground">
            Le taux d'occupation détaillé (créneaux horaires) arrive avec le module Emplois du temps.
          </p>
        </div>
        <Button asChild size="sm">
          <Link href="/academics/rooms/new">
            <Plus className="mr-2 h-4 w-4" />
            Nouvelle salle
          </Link>
        </Button>
      </div>

      {isLoading ? <LoadingSpinner label="Chargement des salles…" /> : <DataTable columns={columns} data={rooms} />}
    </div>
  );
}
