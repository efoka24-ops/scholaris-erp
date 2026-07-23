"use client";

import { useCallback, useEffect, useState } from "react";
import { resourceClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface EstablishmentRequest {
  id: string;
  name: string;
  code: string;
  type: string;
  status: string;
  address: string | null;
  phone: string | null;
  email: string | null;
  directorFirstName: string;
  directorLastName: string;
  directorEmail: string;
  directorPhone: string | null;
  requestStatus: "PENDING" | "APPROVED" | "REJECTED";
  rejectionReason: string | null;
  createdAt: string;
}

const STATUS_LABELS: Record<string, string> = {
  PENDING: "En attente",
  APPROVED: "Validée",
  REJECTED: "Rejetée",
};

export default function EstablishmentRequestsPage() {
  const [items, setItems] = useState<EstablishmentRequest[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  const load = useCallback(() => {
    setIsLoading(true);
    resourceClient
      .get<EstablishmentRequest[]>("/establishment-requests")
      .then((r) => setItems(r.data))
      .catch(() => setItems([]))
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function approve(id: string) {
    setError(null);
    setMessage(null);
    setBusyId(id);
    try {
      const { data } = await resourceClient.put<{ tenantId: string; directorEmail: string; emailSent: boolean }>(
        `/establishment-requests/${id}/approve`,
        {},
      );
      setMessage(
        `Établissement créé. ${
          data.emailSent
            ? `Identifiants envoyés à ${data.directorEmail}.`
            : `⚠️ Email non envoyé (SMTP non configuré) — communiquez les identifiants manuellement.`
        }`,
      );
      load();
    } catch (e: any) {
      setError(e.response?.data?.message ?? "Impossible de valider la demande.");
    } finally {
      setBusyId(null);
    }
  }

  async function reject(id: string) {
    const reason = window.prompt("Motif du rejet (facultatif) :") ?? undefined;
    setError(null);
    setMessage(null);
    setBusyId(id);
    try {
      await resourceClient.put(`/establishment-requests/${id}/reject`, { reason });
      setMessage("Demande rejetée.");
      load();
    } catch (e: any) {
      setError(e.response?.data?.message ?? "Impossible de rejeter la demande.");
    } finally {
      setBusyId(null);
    }
  }

  if (isLoading) return <LoadingSpinner label="Chargement des demandes…" />;

  const pending = items.filter((i) => i.requestStatus === "PENDING");
  const processed = items.filter((i) => i.requestStatus !== "PENDING");

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Demandes d&apos;établissement</h1>
        <p className="text-sm text-muted-foreground">
          Validez ou rejetez les demandes de création déposées par les directeurs. À la validation, l&apos;établissement
          et le compte administrateur sont créés et les identifiants envoyés par email.
        </p>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}
      {message ? <p className="text-sm font-medium text-primary">{message}</p> : null}

      <Card>
        <CardHeader>
          <CardTitle>En attente ({pending.length})</CardTitle>
        </CardHeader>
        <CardContent>
          {pending.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">Aucune demande en attente.</p>
          ) : (
            <div className="flex flex-col gap-4">
              {pending.map((r) => (
                <div key={r.id} className="rounded-md border border-border p-4">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <p className="font-medium">
                        {r.name} <span className="font-mono text-xs text-muted-foreground">({r.code})</span>
                      </p>
                      <p className="text-sm text-muted-foreground">
                        {r.type} · {r.status}
                        {r.address ? ` · ${r.address}` : ""}
                      </p>
                      <p className="mt-1 text-sm">
                        Directeur : <strong>{r.directorFirstName} {r.directorLastName}</strong> — {r.directorEmail}
                        {r.directorPhone ? ` · ${r.directorPhone}` : ""}
                      </p>
                    </div>
                    <div className="flex gap-2">
                      <Button size="sm" disabled={busyId === r.id} onClick={() => approve(r.id)}>
                        {busyId === r.id ? "Validation…" : "Valider"}
                      </Button>
                      <Button size="sm" variant="destructive" disabled={busyId === r.id} onClick={() => reject(r.id)}>
                        Rejeter
                      </Button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Traitées ({processed.length})</CardTitle>
        </CardHeader>
        <CardContent>
          {processed.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">Aucune demande traitée.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="py-2 pr-4 font-medium">Établissement</th>
                    <th className="py-2 pr-4 font-medium">Directeur</th>
                    <th className="py-2 pr-4 font-medium">Statut</th>
                  </tr>
                </thead>
                <tbody>
                  {processed.map((r) => (
                    <tr key={r.id} className="border-b border-border/50">
                      <td className="py-2 pr-4">
                        {r.name} <span className="font-mono text-xs text-muted-foreground">({r.code})</span>
                      </td>
                      <td className="py-2 pr-4 text-muted-foreground">{r.directorEmail}</td>
                      <td className="py-2 pr-4">
                        {STATUS_LABELS[r.requestStatus]}
                        {r.rejectionReason ? ` — ${r.rejectionReason}` : ""}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
