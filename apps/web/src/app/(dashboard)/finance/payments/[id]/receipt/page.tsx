"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { resourceClient } from "@/lib/api-client";
import { formatAmount, PAYMENT_METHOD_LABELS, type PaymentReceipt } from "@/types/finance";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

/**
 * Données structurées du reçu — la mise en forme PDF/impression arrivera avec
 * le Module 6. Cette page reste imprimable en l'état (Ctrl+P) en attendant.
 */
export default function PaymentReceiptPage() {
  const params = useParams<{ id: string }>();
  const [receipt, setReceipt] = useState<PaymentReceipt | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!params?.id) return;
    resourceClient
      .get<PaymentReceipt>(`/payments/${params.id}/receipt`)
      .then((response) => setReceipt(response.data))
      .catch((err) => setError(err.response?.data?.message ?? "Impossible de charger le reçu."));
  }, [params?.id]);

  if (error) {
    return <p className="text-sm font-medium text-destructive">{error}</p>;
  }
  if (!receipt) {
    return <LoadingSpinner label="Chargement du reçu…" />;
  }

  return (
    <div className="flex max-w-xl flex-col gap-4">
      <Card>
        <CardHeader>
          <CardTitle>Reçu de paiement — {receipt.receiptNumber}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4 text-sm">
          <div>
            <p className="font-medium">{receipt.establishment.name}</p>
            <p className="text-muted-foreground">{receipt.establishment.address ?? ""}</p>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <span className="text-xs uppercase text-muted-foreground">Élève</span>
              <p>
                {receipt.student.lastName} {receipt.student.firstName} ({receipt.student.matricule})
              </p>
            </div>
            <div>
              <span className="text-xs uppercase text-muted-foreground">Date</span>
              <p>{new Date(receipt.paidAt).toLocaleDateString("fr-FR")}</p>
            </div>
            <div>
              <span className="text-xs uppercase text-muted-foreground">Montant</span>
              <p className="text-base font-semibold">{formatAmount(receipt.amount)}</p>
            </div>
            <div>
              <span className="text-xs uppercase text-muted-foreground">Mode de paiement</span>
              <p>{PAYMENT_METHOD_LABELS[receipt.method]}</p>
            </div>
            {receipt.reference ? (
              <div>
                <span className="text-xs uppercase text-muted-foreground">Référence</span>
                <p>{receipt.reference}</p>
              </div>
            ) : null}
            {receipt.receivedBy ? (
              <div>
                <span className="text-xs uppercase text-muted-foreground">Reçu par</span>
                <p>{receipt.receivedBy}</p>
              </div>
            ) : null}
          </div>
          <div className="rounded-md border border-border bg-secondary/40 p-3">
            <p>Total facture : {formatAmount(receipt.invoice.totalAmount)}</p>
            <p>Payé à ce jour : {formatAmount(receipt.invoice.paidAmount)}</p>
            <p>Solde restant : {formatAmount(receipt.invoice.balance)}</p>
          </div>
          <Button variant="outline" onClick={() => window.print()}>
            Imprimer
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
