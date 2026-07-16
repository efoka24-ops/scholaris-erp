"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { Pencil, Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import { CHANNEL_LABELS, type Channel, type CommunicationTemplate } from "@/types/communication-templates";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DataTable } from "@/components/shared/data-table";

const CHANNELS = Object.keys(CHANNEL_LABELS) as Channel[];

export default function CommunicationTemplatesPage() {
  const [templates, setTemplates] = useState<CommunicationTemplate[]>([]);
  const [channelFilter, setChannelFilter] = useState<Channel | "">("");
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setIsLoading(true);
    setError(null);
    resourceClient
      .get<CommunicationTemplate[]>("/communication-templates")
      .then((response) => setTemplates(response.data))
      .catch((requestError: any) =>
        setError(requestError.response?.data?.message ?? "Impossible de charger les templates."),
      )
      .finally(() => setIsLoading(false));
  }, []);

  const filtered = channelFilter ? templates.filter((template) => template.channel === channelFilter) : templates;

  const columns: ColumnDef<CommunicationTemplate>[] = [
    { accessorKey: "code", header: "Code" },
    { accessorKey: "name", header: "Nom" },
    {
      id: "channel",
      header: "Canal",
      cell: ({ row }) => CHANNEL_LABELS[row.original.channel],
    },
    {
      id: "bodyPreview",
      header: "Aperçu du contenu",
      cell: ({ row }) => (
        <span className="line-clamp-1 max-w-md text-muted-foreground">{row.original.bodyFr}</span>
      ),
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => (
        <Button asChild variant="outline" size="sm">
          <Link href={`/communications/templates/${row.original.id}/edit`}>
            <Pencil className="h-3.5 w-3.5" />
          </Link>
        </Button>
      ),
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Templates de communication</h1>
          <p className="text-sm text-muted-foreground">Modèles réutilisables pour emails et SMS</p>
        </div>
        <Button asChild>
          <Link href="/communications/templates/new">
            <Plus className="mr-2 h-4 w-4" />
            Nouveau template
          </Link>
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Bibliothèque de templates</CardTitle>
          <CardDescription>Modèles personnalisables avec variables dynamiques</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="flex items-center gap-2">
            <label htmlFor="channel-filter" className="text-sm text-muted-foreground">
              Filtrer par canal
            </label>
            <select
              id="channel-filter"
              className="h-9 rounded-md border border-border bg-background px-2 text-sm"
              value={channelFilter}
              onChange={(event) => setChannelFilter(event.target.value as Channel | "")}
            >
              <option value="">Tous les canaux</option>
              {CHANNELS.map((channel) => (
                <option key={channel} value={channel}>
                  {CHANNEL_LABELS[channel]}
                </option>
              ))}
            </select>
          </div>

          {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

          <DataTable
            columns={columns}
            data={filtered}
            isLoading={isLoading}
            emptyLabel="Aucun template pour ce filtre"
          />
        </CardContent>
      </Card>
    </div>
  );
}
