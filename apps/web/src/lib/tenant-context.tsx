"use client";

import { createContext, useContext, useMemo, type ReactNode } from "react";
import { useAuth } from "./auth-context";

interface TenantContextValue {
  tenantId: string | null;
}

const TenantContext = createContext<TenantContextValue | undefined>(undefined);

// Séparé d'AuthContext car un Super Admin (Phase 8+) pourra un jour parcourir
// plusieurs établissements sans se ré-authentifier ; pour l'instant, dérivé du JWT.
export function TenantProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const value = useMemo(() => ({ tenantId: user?.tenantId ?? null }), [user]);
  return <TenantContext.Provider value={value}>{children}</TenantContext.Provider>;
}

export function useTenant(): TenantContextValue {
  const context = useContext(TenantContext);
  if (!context) {
    throw new Error("useTenant doit être utilisé à l'intérieur de <TenantProvider>");
  }
  return context;
}
