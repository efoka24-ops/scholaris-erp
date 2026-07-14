"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { resourceClient } from "@/lib/api-client";
import {
  formatAmount,
  INVOICE_STATUS_LABELS,
  PAYMENT_METHOD_LABELS,
  DISCOUNT_TYPE_LABELS,
  type Invoice,
} from "@/types/finance";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-xs uppercase text-muted-foreground">{label}</span>
      <span className="text-sm font-medium">{value}</span>
    </div>
  );
}

export default function InvoiceDetailPage() {
  const params = useParams<{ id: string }>();
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [error, setError] = useState<string | null>(null);

  function load() {
    if (!params?.id) return;
    resourceClient
      .get<Invoice>(`/invoices/${params.id}`)
      .then((response) => setInvoice(response.data))
      .catch((err) => setError(err.response?.data?.message ?? "Impossible de charger la facture."));
  }

  useEffect(load, [params?.id]);

  if (error) {
    return <p className="text-sm font-medium text-destructive">{error}</p>;
  }
  if (!invoice) {
    return <LoadingSpinner label="Chargement de la facture…" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-muted-foreground">
            <Link href="/finance/invoices" className="hover:underline">
              Factures
            </Link>{" "}
            / {invoice.student ? `${invoice.student.lastName} ${invoice.student.firstName}` : invoice.id}
          </p>
          <h1 className="text-2xl font-semibold">
            Facture — {invoice.enrollment?.classroom?.name ?? "—"} ({invoice.academicYear?.label ?? "—"})
          </h1>
        </div>
        {invoice.balance > 0 ? (
          <Button asChild>
            <Link href={`/finance/payments/new?invoiceId=${invoice.id}`}>Enregistrer un paiement</Link>
          </Button>
        ) : null}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Résumé</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-4 md:grid-cols-4">
          <Field label="Montant total" value={formatAmount(invoice.totalAmount)} />
          <Field label="Payé" value={formatAmount(invoice.paidAmount)} />
          <Field label="Solde restant" value={formatAmount(invoice.balance)} />
          <Field label="Statut" value={INVOICE_STATUS_LABELS[invoice.status]} />
          <Field
            label="Échéance"
            value={invoice.dueDate ? new Date(invoice.dueDate).toLocaleDateString("fr-FR") : "—"}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Historique des paiements</CardTitle>
        </CardHeader>
        <CardContent>
          {(invoice.payments ?? []).length === 0 ? (
            <p className="text-sm text-muted-foreground">Aucun paiement enregistré.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-secondary/60 text-left">
                  <tr>
                    <th className="px-4 py-2 font-medium">Reçu</th>
                    <th className="px-4 py-2 font-medium">Date</th>
                    <th className="px-4 py-2 font-medium">Montant</th>
                    <th className="px-4 py-2 font-medium">Mode</th>
                    <th className="px-4 py-2 font-medium">Référence</th>
                    <th className="px-4 py-2 font-medium"></th>
                  </tr>
                </thead>
                <tbody>
                  {(invoice.payments ?? []).map((payment) => (
                    <tr key={payment.id} className="border-t border-border">
                      <td className="px-4 py-2">{payment.receiptNumber}</td>
                      <td className="px-4 py-2">{new Date(payment.paidAt).toLocaleDateString("fr-FR")}</td>
                      <td className="px-4 py-2">{formatAmount(payment.amount)}</td>
                      <td className="px-4 py-2">{PAYMENT_METHOD_LABELS[payment.method]}</td>
                      <td className="px-4 py-2">{payment.reference ?? "—"}</td>
                      <td className="px-4 py-2">
                        <Button asChild variant="outline" size="sm">
                          <Link href={`/finance/payments/${payment.id}/receipt`} target="_blank">
                            Reçu
                          </Link>
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {(invoice.discounts ?? []).length > 0 ? (
        <Card>
          <CardHeader>
            <CardTitle>Réductions appliquées</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-2">
            {(invoice.discounts ?? []).map((discount) => (
              <div key={discount.id} className="flex justify-between text-sm">
                <span>{discount.reason ?? DISCOUNT_TYPE_LABELS[discount.type]}</span>
                <span>
                  {discount.type === "PERCENTAGE" ? `${discount.value}%` : formatAmount(discount.value)}
                </span>
              </div>
            ))}
          </CardContent>
        </Card>
      ) : null}

      {invoice.feeStructure ? (
        <Card>
          <CardHeader>
            <CardTitle>Échéancier de la grille tarifaire</CardTitle>
            <CardDescription>{invoice.feeStructure.name}</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-1 text-sm">
            {invoice.feeStructure.installments.map((installment) => (
              <div key={installment.id} className="flex justify-between">
                <span>{installment.label}</span>
                <span>
                  {formatAmount(installment.amount)} — {new Date(installment.dueDate).toLocaleDateString("fr-FR")}
                </span>
              </div>
            ))}
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}
