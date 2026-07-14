"use client";

import { useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { loginSchema, type LoginInput } from "@scholaris/shared";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/shared/form";

export default function LoginPage() {
  const { login } = useAuth();
  const [serverError, setServerError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  // Affiché quand le backend répond 401 { mfaRequired: true } : le compte exige un code TOTP.
  const [mfaRequired, setMfaRequired] = useState(false);

  const form = useForm<LoginInput>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "", mfaCode: "" },
  });

  async function onSubmit(values: LoginInput) {
    setServerError(null);
    setIsSubmitting(true);
    try {
      await login(values.email, values.password, values.mfaCode || undefined);
    } catch (error: any) {
      if (error.response?.data?.mfaRequired) {
        setMfaRequired(true);
        setServerError("Ce compte est protégé par MFA : saisissez le code de votre application d'authentification.");
      } else if (mfaRequired) {
        setServerError("Code MFA invalide ou identifiants incorrects.");
      } else {
        setServerError("Identifiants invalides. Vérifiez votre email et votre mot de passe.");
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>SCHOLARIS ERP</CardTitle>
        <CardDescription>Connectez-vous à votre espace établissement</CardDescription>
      </CardHeader>
      <CardContent>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
            <FormField
              control={form.control}
              name="email"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Email</FormLabel>
                  <FormControl>
                    <Input type="email" placeholder="admin@etablissement.cm" autoComplete="username" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="password"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Mot de passe</FormLabel>
                  <FormControl>
                    <Input type="password" autoComplete="current-password" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            {mfaRequired ? (
              <FormField
                control={form.control}
                name="mfaCode"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Code MFA</FormLabel>
                    <FormControl>
                      <Input
                        inputMode="numeric"
                        maxLength={6}
                        placeholder="123456"
                        autoComplete="one-time-code"
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            ) : null}
            {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}
            <Button type="submit" disabled={isSubmitting} className="mt-2">
              {isSubmitting ? "Connexion…" : "Se connecter"}
            </Button>
          </form>
        </Form>
      </CardContent>
    </Card>
  );
}
