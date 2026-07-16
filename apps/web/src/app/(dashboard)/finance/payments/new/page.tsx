"use client";

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { resourceClient } from "@/lib/api-client";
import { formatAmount, PAYMENT_METHOD_LABELS, type Invoice, type PaymentMethod } from "@/types/finance";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

export default function NewPaymentPage() {
  return (
    <Suspense fallback={null}>
      <NewPaymentForm />
    </Suspense>
  );
}

function NewPaymentForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const invoiceIdParam = searchParams?.get("invoiceId") ?? "";

  const [invoiceId, setInvoiceId] = useState(invoiceIdParam);
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState<PaymentMethod>("CASH");
  const [reference, setReference] = useState("");
  const [notes, setNotes] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (!invoiceId) {
      setInvoice(null);
      return;
    }
    resourceClient
      .get<Invoice>(`/invoices/${invoiceId}`)
      .then((response) => setInvoice(response.data))
      .catch(() => setInvoice(null));
  }, [invoiceId]);

  async function submit() {
    setError(null);
    setIsSubmitting(true);
    try {
      const { data: payment } = await resourceClient.post<{ id: string }>("/payments", {
        invoiceId,
        amount: Number(amount),
        method,
        ...(reference ? { reference } : {}),
        ...(notes ? { notes } : {}),
      });
      router.push(`/finance/invoices/${invoiceId}`);
      void payment;
    } catch (submitError: any) {
      setError(submitError.response?.data?.message ?? "Impossible d'enregistrer le paiement.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex max-w-lg flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Nouveau paiement</h1>
        <p className="text-sm text-muted-foreground">Enregistrement d'un paiement contre une facture</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Facture</CardTitle>
          <CardDescription>Identifiant de la facture à créditer.</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <Label>Facture *</Label>
            <Input value={invoiceId} onChange={(e) => setInvoiceId(e.target.value)} placeholder="Identifiant de la facture" />
          </div>

          {invoice ? (
            <div className="rounded-md border border-border bg-secondary/40 p-3 text-sm">
              <p className="font-medium">
                {invoice.student ? `${invoice.student.lastName} ${invoice.student.firstName}` : "—"}
              </p>
              <p>Montant total : {formatAmount(invoice.totalAmount)}</p>
              <p>Déjà payé : {formatAmount(invoice.paidAmount)}</p>
              <p className="font-medium">Solde restant : {formatAmount(invoice.balance)}</p>
            </div>
          ) : null}

          <div className="grid grid-cols-2 gap-4">
            <div className="flex flex-col gap-1.5">
              <Label>Montant *</Label>
              <Input
                type="number"
                min={0}
                max={invoice?.balance}
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Mode de paiement *</Label>
              <select
                className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                value={method}
                onChange={(e) => setMethod(e.target.value as PaymentMethod)}
              >
                {(Object.keys(PAYMENT_METHOD_LABELS) as PaymentMethod[]).map((value) => (
                  <option key={value} value={value}>
                    {PAYMENT_METHOD_LABELS[value]}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label>Référence</Label>
            <Input
              value={reference}
              onChange={(e) => setReference(e.target.value)}
              placeholder="N° transaction Mobile Money, n° chèque…"
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label>Notes</Label>
            <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
          </div>

          {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

          <Button disabled={!invoiceId || !amount || isSubmitting} onClick={submit}>
            {isSubmitting ? "Enregistrement…" : "Enregistrer le paiement"}
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
