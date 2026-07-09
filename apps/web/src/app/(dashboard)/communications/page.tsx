"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { apiClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/shared/data-table";

// Canaux du Module 8 (§23.1 du guide) — doit rester synchronisé avec l'enum Prisma `Channel`.
const CHANNEL_LABELS: Record<string, string> = {
  EMAIL: "Email",
  SMS: "SMS",
  WHATSAPP: "WhatsApp",
  PUSH: "Push",
  INTERNAL: "Interne",
};

interface CommunicationTemplate {
  id: string;
  code: string;
  name: string;
  channel: string;
  updatedAt: string;
}

const columns: ColumnDef<CommunicationTemplate>[] = [
  { accessorKey: "code", header: "Code" },
  { accessorKey: "name", header: "Nom" },
  {
    accessorKey: "channel",
    header: "Canal",
    cell: ({ row }) => CHANNEL_LABELS[row.original.channel] ?? row.original.channel,
  },
  {
    accessorKey: "updatedAt",
    header: "Mis à jour",
    cell: ({ row }) => new Date(row.original.updatedAt).toLocaleDateString("fr-FR"),
  },
];

export default function CommunicationTemplatesPage() {
  const [templates, setTemplates] = useState<CommunicationTemplate[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    apiClient
      .get("/communication-templates")
      .then(({ data }) => {
        if (!cancelled) setTemplates(data.data ?? []);
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Modèles de communication</h1>
          <p className="text-sm text-muted-foreground">
            Convocations, relances, notifications — un modèle par canal, en français et en anglais.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" asChild>
            <Link href="/communications/logs">Journal d&apos;envoi</Link>
          </Button>
          <Button asChild>
            <Link href="/communications/new">Nouveau modèle</Link>
          </Button>
        </div>
      </div>

      <DataTable columns={columns} data={templates} isLoading={isLoading} emptyLabel="Aucun modèle de communication" />
    </div>
  );
}
