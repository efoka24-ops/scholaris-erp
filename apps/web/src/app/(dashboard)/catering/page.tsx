"use client";

import { useCallback, useEffect, useState } from "react";
import { UtensilsCrossed, Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface CateringMenu {
  id: string;
  date: string;
  meal: string;
  items: string | null;
}

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

export default function CateringPage() {
  const [menus, setMenus] = useState<CateringMenu[]>([]);
  const [subscriptions, setSubscriptions] = useState<unknown[]>([]);
  const [dorms, setDorms] = useState<unknown[]>([]);
  const [isLoadingMenus, setIsLoadingMenus] = useState(true);
  const [isLoadingSubscriptions, setIsLoadingSubscriptions] = useState(true);
  const [isLoadingDorms, setIsLoadingDorms] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [showMenuForm, setShowMenuForm] = useState(false);
  const [menuDate, setMenuDate] = useState(todayIso());
  const [menuMeal, setMenuMeal] = useState("Déjeuner");
  const [menuItems, setMenuItems] = useState("");
  const [isSubmittingMenu, setIsSubmittingMenu] = useState(false);

  const loadMenus = useCallback(() => {
    setIsLoadingMenus(true);
    resourceClient
      .get<CateringMenu[]>("/catering/menus")
      .then((response) => setMenus(response.data))
      .catch((requestError: any) => setError(requestError.response?.data?.message ?? "Impossible de charger les menus."))
      .finally(() => setIsLoadingMenus(false));
  }, []);

  useEffect(() => {
    loadMenus();
    resourceClient
      .get("/catering/subscriptions")
      .then((response) => setSubscriptions(Array.isArray(response.data) ? response.data : []))
      .finally(() => setIsLoadingSubscriptions(false));
    resourceClient
      .get("/catering/dorms")
      .then((response) => setDorms(Array.isArray(response.data) ? response.data : []))
      .finally(() => setIsLoadingDorms(false));
  }, [loadMenus]);

  async function handleCreateMenu() {
    setError(null);
    setIsSubmittingMenu(true);
    try {
      await resourceClient.post("/catering/menus", {
        date: menuDate,
        meal: menuMeal,
        ...(menuItems ? { items: menuItems } : {}),
      });
      setMenuItems("");
      setShowMenuForm(false);
      loadMenus();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible de créer le menu.");
    } finally {
      setIsSubmittingMenu(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Cantine & Internat</h1>
          <p className="text-sm text-muted-foreground">Gestion des menus, abonnements cantine et chambres d'internat</p>
        </div>
        <Button onClick={() => setShowMenuForm((v) => !v)}>
          <Plus className="mr-2 h-4 w-4" />
          Nouveau menu
        </Button>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      {showMenuForm ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Créer un menu</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-wrap items-end gap-3">
            <input type="date" className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={menuDate} onChange={(e) => setMenuDate(e.target.value)} />
            <select className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={menuMeal} onChange={(e) => setMenuMeal(e.target.value)}>
              <option value="Petit-déjeuner">Petit-déjeuner</option>
              <option value="Déjeuner">Déjeuner</option>
              <option value="Dîner">Dîner</option>
            </select>
            <Input className="w-64" placeholder="Entrée, plat, dessert…" value={menuItems} onChange={(e) => setMenuItems(e.target.value)} />
            <Button disabled={!menuDate || !menuMeal || isSubmittingMenu} onClick={handleCreateMenu}>
              {isSubmittingMenu ? "Création…" : "Créer le menu"}
            </Button>
          </CardContent>
        </Card>
      ) : null}

      <div className="grid gap-6 md:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle>Menus</CardTitle>
            <CardDescription>Planification hebdomadaire</CardDescription>
          </CardHeader>
          <CardContent>
            {isLoadingMenus ? (
              <LoadingSpinner label="Chargement…" />
            ) : menus.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <UtensilsCrossed className="h-10 w-10 text-muted-foreground mb-3" />
                <p className="text-sm text-muted-foreground">Aucun menu pour le moment</p>
              </div>
            ) : (
              <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
                {menus.map((menu) => (
                  <li key={menu.id} className="flex flex-col gap-0.5 px-4 py-2 text-sm">
                    <span className="font-medium">{new Date(menu.date).toLocaleDateString("fr-FR")} — {menu.meal}</span>
                    <span className="text-muted-foreground">{menu.items ?? "—"}</span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Abonnements cantine</CardTitle>
            <CardDescription>Élèves inscrits</CardDescription>
          </CardHeader>
          <CardContent>
            {isLoadingSubscriptions ? (
              <LoadingSpinner label="Chargement…" />
            ) : subscriptions.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <UtensilsCrossed className="h-10 w-10 text-muted-foreground mb-3" />
                <p className="text-sm text-muted-foreground">
                  Aucun abonnement pour le moment (module en cours de finalisation côté serveur)
                </p>
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">{subscriptions.length} abonnement(s)</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Dortoirs</CardTitle>
            <CardDescription>Chambres et lits</CardDescription>
          </CardHeader>
          <CardContent>
            {isLoadingDorms ? (
              <LoadingSpinner label="Chargement…" />
            ) : dorms.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <UtensilsCrossed className="h-10 w-10 text-muted-foreground mb-3" />
                <p className="text-sm text-muted-foreground">
                  Aucun dortoir pour le moment (module en cours de finalisation côté serveur)
                </p>
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">{dorms.length} dortoir(s)</p>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
