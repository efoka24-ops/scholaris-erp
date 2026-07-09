"use client";

import { LogOut } from "lucide-react";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Breadcrumb } from "./breadcrumb";

export function Topbar() {
  const { user, logout } = useAuth();

  return (
    <header className="flex h-16 items-center justify-between border-b border-border px-6">
      <Breadcrumb />
      <div className="flex items-center gap-4">
        {user ? <span className="text-sm text-muted-foreground">{user.email}</span> : null}
        <Button variant="ghost" size="sm" onClick={() => logout()}>
          <LogOut className="mr-2 h-4 w-4" />
          Déconnexion
        </Button>
      </div>
    </header>
  );
}
