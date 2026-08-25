"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { apiClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/** Chantier 1 : écran de changement de mot de passe obligatoire (première connexion / réinitialisation). */
export default function ChangePasswordRequiredPage() {
  const router = useRouter();
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (newPassword.length < 8) {
      setError("Le nouveau mot de passe doit contenir au moins 8 caractères.");
      return;
    }
    if (newPassword !== confirmPassword) {
      setError("Les deux mots de passe ne correspondent pas.");
      return;
    }

    setIsSubmitting(true);
    try {
      await apiClient.post("/auth/change-password", { currentPassword, newPassword });
      // Le token restreint n'accorde aucune permission : on repasse par /login
      // pour obtenir une session complète avec le nouveau mot de passe.
      router.push("/login?passwordChanged=1");
    } catch (err: any) {
      setError(err.response?.data?.error ?? "Impossible de changer le mot de passe.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Changement de mot de passe requis</CardTitle>
        <CardDescription>
          Pour des raisons de sécurité, vous devez choisir un nouveau mot de passe avant de continuer.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Mot de passe temporaire / actuel</label>
            <Input
              type="password"
              autoComplete="current-password"
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
              required
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Nouveau mot de passe</label>
            <Input
              type="password"
              autoComplete="new-password"
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              required
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Confirmer le nouveau mot de passe</label>
            <Input
              type="password"
              autoComplete="new-password"
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
              required
            />
          </div>
          {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? "Enregistrement…" : "Changer le mot de passe"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
