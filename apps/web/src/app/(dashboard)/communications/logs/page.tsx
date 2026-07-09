"use client";

import { useEffect, useState } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import type { PaginationMeta } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import { DataTable } from "@/components/shared/data-table";

const CHANNEL_LABELS: Record<string, string> = {
  EMAIL: "Email",
  SMS: "SMS",
  WHATSAPP: "WhatsApp",
  PUSH: "Push",
  INTERNAL: "Interne",
};

const STATUS_LABELS: Record<string, string> = {
  PENDING: "En attente",
  SENT: "Envoyé",
  DELIVERED: "Délivré",
  FAILED: "Échec",
};

interface CommunicationMessage {
  id: string;
  channel: string;
  recipientUserId: string;
  status: string;
  createdAt: string;
}

const columns: ColumnDef<CommunicationMessage>[] = [
  {
    accessorKey: "channel",
    header: "Canal",
    cell: ({ row }) => CHANNEL_LABELS[row.original.channel] ?? row.original.channel,
  },
  { accessorKey: "recipientUserId", header: "Destinataire" },
  {
    accessorKey: "status",
    header: "Statut",
    cell: ({ row }) => STATUS_LABELS[row.original.status] ?? row.original.status,
  },
  {
    accessorKey: "createdAt",
    header: "Date",
    cell: ({ row }) => new Date(row.original.createdAt).toLocaleString("fr-FR"),
  },
];

export default function CommunicationLogsPage() {
  const [messages, setMessages] = useState<CommunicationMessage[]>([]);
  const [meta, setMeta] = useState<PaginationMeta | undefined>(undefined);
  const [page, setPage] = useState(1);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    resourceClient
      .get("/communications", { params: { page, limit: 20 } })
      .then(({ data }) => {
        if (cancelled) return;
        setMessages(data?.data ?? []);
        setMeta(data?.meta);
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [page]);

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Journal des communications</h1>
        <p className="text-sm text-muted-foreground">Historique des envois email, SMS, WhatsApp, push et interne.</p>
      </div>

      <DataTable
        columns={columns}
        data={messages}
        meta={meta}
        isLoading={isLoading}
        onPageChange={setPage}
        emptyLabel="Aucune communication envoyée"
      />
    </div>
  );
}
