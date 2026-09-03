"use client";

import { useEffect, useState } from "react";
import { useAuth } from "@/hooks/use-auth";
import { resourceClient } from "@/lib/api-client";
import { OPTIONAL_MODULES } from "@/lib/establishment-features";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

export default function ModulesPage() {
  const { user } = useAuth();
  const [enabled, setEnabled] = useState<Set<string>>(new Set());
  const [configured, setConfigured] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);

  useEffect(() => {
    if (!user?.tenantId) return;
    resourceClient
      .get<string[]>(`/tenants/${user.tenantId}/modules`)
      .then((r) => {
        const list = Array.isArray(r.data) ? r.data : [];
        // Aucune config encore → tout activé par défaut (comportement actuel).
        if (list.length === 0) {
          setEnabled(new Set(OPTIONAL_MODULES.map((m) => m.key)));
          setConfigured(false);
        } else {
          setEnabled(new Set(list));
          setConfigured(true);
        }
      })
      .catch(() => setEnabled(new Set(OPTIONAL_MODULES.map((m) => m.key))))
      .finally(() => setIsLoading(false));
  }, [user?.tenantId]);

  function toggle(key: string) {
    setEnabled((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }

  async function save() {
    if (!user?.tenantId) return;
    setErr(null);
    setMsg(null);
    setIsSaving(true);
    try {
      await resourceClient.put(`/tenants/${user.tenantId}/modules`, {
        enabledModules: [...enabled],
      });
      setConfigured(true);
      window.dispatchEvent(new CustomEvent("modules-updated", { detail: [...enabled] }));
      setMsg("Modules mis à jour. Le menu latéral reflète votre sélection.");
    } catch (e: any) {
      setErr(e.response?.data?.message ?? "Échec de l'enregistrement.");
    } finally {
      setIsSaving(false);
    }
  }

  if (isLoading) return <LoadingSpinner label="Chargement des modules…" />;

  return (
    <div className="flex max-w-2xl flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Modules & fonctionnalités</h1>
        <p className="text-sm text-muted-foreground">
          Activez ou désactivez les modules optionnels de votre établissement. Les modules décochés sont masqués
          du menu et leurs pages deviennent inaccessibles.
        </p>
      </div>

      {err ? <p className="text-sm font-medium text-destructive">{err}</p> : null}
      {msg ? <p className="text-sm font-medium text-primary">{msg}</p> : null}
      {!configured ? (
        <p className="rounded-md border border-border bg-secondary/40 p-3 text-sm text-muted-foreground">
          Aucune configuration enregistrée : tous les modules optionnels sont actuellement visibles. Votre première
          sauvegarde définit la sélection.
        </p>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Modules optionnels</CardTitle>
          <CardDescription>Vie scolaire & gestion — activables selon les besoins de l'établissement</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-1">
          {OPTIONAL_MODULES.map((m) => (
            <label
              key={m.key}
              className="flex cursor-pointer items-center justify-between rounded-md px-2 py-2.5 hover:bg-secondary"
            >
              <span className="text-sm font-medium">{m.label}</span>
              <input
                type="checkbox"
                className="h-4 w-4"
                checked={enabled.has(m.key)}
                onChange={() => toggle(m.key)}
              />
            </label>
          ))}
        </CardContent>
      </Card>

      <Button className="w-fit" disabled={isSaving} onClick={save}>
        {isSaving ? "Enregistrement…" : "Enregistrer"}
      </Button>
    </div>
  );
}
