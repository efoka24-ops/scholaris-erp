"use client";

import { useEffect, useMemo, useState } from "react";
import { useAuth } from "@/hooks/use-auth";
import { resourceClient } from "@/lib/api-client";
import {
  hasMenuPermission,
  isMenuVisible,
  resolveCategory,
  type EstablishmentCategory,
} from "@/lib/establishment-features";
import { USER_GUIDE } from "@/lib/user-guide";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

export default function GuidePage() {
  const { user, hasPermission } = useAuth();
  const [category, setCategory] = useState<EstablishmentCategory | null>(null);
  const isSuperAdmin = hasPermission("tenants:create");

  useEffect(() => {
    if (!user?.tenantId) return;
    let cancelled = false;
    resourceClient
      .get<{ type?: string; configJson?: { establishmentCategory?: string } }>(`/tenants/${user.tenantId}`)
      .then((r) => {
        if (!cancelled) setCategory(resolveCategory(r.data.type, r.data.configJson?.establishmentCategory));
      })
      .catch(() => {
        if (!cancelled) setCategory(null);
      });
    return () => {
      cancelled = true;
    };
  }, [user?.tenantId]);

  // Guide filtré : uniquement les fonctionnalités accessibles à ce profil.
  const sections = useMemo(() => {
    const cat = category ?? "COLLEGE";
    return USER_GUIDE.map((s) => ({
      ...s,
      entries: s.entries.filter(
        (e) => isMenuVisible(e.href, cat, isSuperAdmin) && (isSuperAdmin || hasMenuPermission(e.href, hasPermission)),
      ),
    })).filter((s) => s.entries.length > 0);
  }, [category, isSuperAdmin, hasPermission]);

  return (
    <div className="flex max-w-3xl flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Guide d'utilisation</h1>
        <p className="text-sm text-muted-foreground">
          Ce guide est adapté à votre profil{user?.email ? ` (${user.email})` : ""} : il ne présente que les
          fonctionnalités auxquelles vous avez accès et comment les utiliser.
        </p>
      </div>

      {sections.length === 0 ? (
        <p className="text-sm text-muted-foreground">Chargement de votre guide…</p>
      ) : (
        sections.map((s) => (
          <Card key={s.section}>
            <CardHeader>
              <CardTitle>{s.section}</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
              {s.entries.map((e) => (
                <div key={e.href} className="border-l-2 border-primary/40 pl-4">
                  <h3 className="text-base font-semibold">{e.title}</h3>
                  <p className="mt-1 text-sm text-muted-foreground">{e.what}</p>
                  <ul className="mt-2 list-inside list-disc space-y-1 text-sm">
                    {e.how.map((step, i) => (
                      <li key={i}>{step}</li>
                    ))}
                  </ul>
                </div>
              ))}
            </CardContent>
          </Card>
        ))
      )}
    </div>
  );
}
