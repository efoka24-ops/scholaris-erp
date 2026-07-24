"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import type { PaginatedResult } from "@scholaris/shared";
import { Wallet, Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import { openPrintable } from "@/lib/download";
import { formatAmount, PAYMENT_METHOD_LABELS, type Payment, type PaymentMethod } from "@/types/finance";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DataTable } from "@/components/shared/data-table";

interface PaymentRow extends Payment {
  student?: { firstName: string; lastName: string; matricule: string } | null;
  invoice?: { id: string } | null;
}

const columns: ColumnDef<PaymentRow>[] = [
  {
    id: "student",
    header: "Élève",
    cell: ({ row }) =>
      row.original.student ? `${row.original.student.lastName} ${row.original.student.firstName}` : "—",
  },
  {
    id: "amount",
    header: "Montant",
    cell: ({ row }) => formatAmount(row.original.amount),
  },
  {
    id: "method",
    header: "Méthode",
    cell: ({ row }) => PAYMENT_METHOD_LABELS[row.original.method],
  },
  {
    id: "receiptNumber",
    header: "Reçu",
    cell: ({ row }) => row.original.receiptNumber,
  },
  {
    id: "paidAt",
    header: "Date",
    cell: ({ row }) => new Date(row.original.paidAt).toLocaleDateString("fr-FR"),
  },
  {
    id: "actions",
    header: "",
    cell: ({ row }) => (
      <div className="flex gap-2">
        <Button variant="outline" size="sm" onClick={() => openPrintable(`/payments/${row.original.id}/receipt/print`)}>
          Reçu
        </Button>
        <Button asChild variant="outline" size="sm">
          <Link href={`/finance/invoices/${row.original.invoiceId}`}>Facture</Link>
        </Button>
      </div>
    ),
  },
];

export default function PaymentsPage() {
  const [result, setResult] = useState<PaginatedResult<PaymentRow> | null>(null);
  const [methodFilter, setMethodFilter] = useState<PaymentMethod | "">("");
  const [page, setPage] = useState(1);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setIsLoading(true);
    setError(null);
    resourceClient
      .get<PaginatedResult<PaymentRow>>("/payments", {
        params: { page, limit: 20, ...(methodFilter ? { method: methodFilter } : {}) },
      })
      .then((response) => setResult(response.data))
      .catch((requestError: any) => setError(requestError.response?.data?.message ?? "Impossible de charger les paiements."))
      .finally(() => setIsLoading(false));
  }, [page, methodFilter]);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Paiements</h1>
          <p className="text-sm text-muted-foreground">Enregistrement et suivi des paiements des frais de scolarité</p>
        </div>
        <Button asChild>
          <Link href="/finance/payments/new">
            <Plus className="mr-2 h-4 w-4" />
            Enregistrer un paiement
          </Link>
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Wallet className="h-5 w-5" />
            Liste des paiements
          </CardTitle>
          <CardDescription>Historique des paiements avec méthodes et reçus</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="flex items-center gap-2">
            <label htmlFor="method-filter" className="text-sm text-muted-foreground">
              Filtrer par méthode
            </label>
            <select
              id="method-filter"
              className="h-9 rounded-md border border-border bg-background px-2 text-sm"
              value={methodFilter}
              onChange={(event) => {
                setPage(1);
                setMethodFilter(event.target.value as PaymentMethod | "");
              }}
            >
              <option value="">Toutes les méthodes</option>
              {(Object.keys(PAYMENT_METHOD_LABELS) as PaymentMethod[]).map((method) => (
                <option key={method} value={method}>
                  {PAYMENT_METHOD_LABELS[method]}
                </option>
              ))}
            </select>
          </div>

          {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

          <DataTable
            columns={columns}
            data={result?.data ?? []}
            meta={result?.meta}
            isLoading={isLoading}
            onPageChange={setPage}
            emptyLabel="Aucun paiement enregistré pour ces critères"
          />

          <div className="flex gap-2">
            <Button asChild variant="outline">
              <Link href="/finance/invoices">Voir les factures</Link>
            </Button>
            <Button asChild variant="outline">
              <Link href="/finance/dashboard">Tableau de bord</Link>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
