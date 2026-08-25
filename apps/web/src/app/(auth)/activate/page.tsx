"use client";

import { useState, Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { apiClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

function ActivateForm() {
  const router = useRouter();
  const params = useSearchParams();
  const token = params.get("token") ?? "";

  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [activated, setActivated] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (!token) {
      setError("Lien d'activation invalide (token manquant).");
      return;
    }
    if (newPassword.length < 8) {
      setError("Le mot de passe doit contenir au moins 8 caractères.");
      return;
    }
    if (newPassword !== confirmPassword) {
      setError("Les deux mots de passe ne correspondent pas.");
      return;
    }

    setIsSubmitting(true);
    try {
      // Étape 1 : consomme le lien, pose un token restreint.
      await apiClient.post("/auth/activate", { token });
      // Étape 2 : ce token restreint n'a pas de mot de passe "actuel" connu de
      // l'utilisateur ; l'API change-password exige le mot de passe actuel — on
      // redirige donc vers l'écran de changement forcé qui saisit les deux.
      setActivated(true);
    } catch (err: any) {
      setError(err.response?.data?.error ?? "Ce lien d'activation est invalide ou a expiré.");
    } finally {
      setIsSubmitting(false);
    }
  }

  if (activated) {
    router.push("/change-password-required");
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Activation du compte</CardTitle>
        <CardDescription>
          Confirmez l&apos;activation de votre compte. Vous choisirez votre mot de passe définitif à l&apos;étape suivante.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}
          {!token ? (
            <p className="text-sm text-muted-foreground">
              Aucun token trouvé dans le lien. Vérifiez que vous avez bien copié l&apos;URL complète reçue par email.
            </p>
          ) : null}
          <Button type="submit" disabled={isSubmitting || !token}>
            {isSubmitting ? "Activation…" : "Activer mon compte"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

export default function ActivatePage() {
  return (
    <Suspense fallback={null}>
      <ActivateForm />
    </Suspense>
  );
}
