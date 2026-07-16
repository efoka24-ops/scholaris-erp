"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Plus, Trash2, Pencil } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/shared/form";
import { LoadingSpinner } from "@/components/shared/loading-spinner";
import type { Permission, Role } from "@/types/settings";

const roleSchema = z.object({
  name: z.string().min(1, "Nom requis"),
  description: z.string().optional(),
  permissionIds: z.array(z.string()).default([]),
});
type RoleInput = z.infer<typeof roleSchema>;

export default function RolesSettingsPage() {
  const { hasPermission } = useAuth();
  const [roles, setRoles] = useState<Role[]>([]);
  const [permissionGroups, setPermissionGroups] = useState<Record<string, Permission[]>>({});
  const [isLoading, setIsLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editingRoleId, setEditingRoleId] = useState<string | null>(null);
  const [serverError, setServerError] = useState<string | null>(null);

  const canCreate = hasPermission("roles:create");
  const canUpdate = hasPermission("roles:update");
  const canDelete = hasPermission("roles:delete");

  const loadRoles = async () => {
    setIsLoading(true);
    try {
      const { data } = await resourceClient.get("/roles", { params: { limit: 100 } });
      setRoles(data.data ?? []);
    } finally {
      setIsLoading(false);
    }
  };

  const loadPermissions = async () => {
    try {
      const { data } = await resourceClient.get("/permissions");
      setPermissionGroups(data.data ?? data ?? {});
    } catch {
      setPermissionGroups({});
    }
  };

  useEffect(() => {
    loadRoles();
    loadPermissions();
  }, []);

  const form = useForm<RoleInput>({
    resolver: zodResolver(roleSchema),
    defaultValues: { name: "", description: "", permissionIds: [] },
  });

  function startCreate() {
    setEditingRoleId(null);
    form.reset({ name: "", description: "", permissionIds: [] });
    setShowForm(true);
    setServerError(null);
  }

  function startEdit(role: Role) {
    setEditingRoleId(role.id);
    form.reset({
      name: role.name,
      description: role.description ?? "",
      permissionIds: role.permissions.map((p) => p.id),
    });
    setShowForm(true);
    setServerError(null);
  }

  async function onSubmit(values: RoleInput) {
    setServerError(null);
    try {
      if (editingRoleId) {
        await resourceClient.put(`/roles/${editingRoleId}`, values);
      } else {
        await resourceClient.post("/roles", values);
      }
      setShowForm(false);
      setEditingRoleId(null);
      await loadRoles();
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible d'enregistrer ce rôle.");
    }
  }

  async function onDelete(id: string) {
    setServerError(null);
    try {
      await resourceClient.delete(`/roles/${id}`);
      await loadRoles();
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible de supprimer ce rôle.");
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Rôles & Permissions</h1>
          <p className="text-sm text-muted-foreground">
            Créez des rôles personnalisés et assignez des permissions granulaires par module.
          </p>
        </div>
        {canCreate ? (
          <Button onClick={startCreate}>
            <Plus className="mr-2 h-4 w-4" />
            Nouveau rôle
          </Button>
        ) : null}
      </div>

      {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}

      {showForm ? (
        <Card>
          <CardHeader>
            <CardTitle>{editingRoleId ? "Modifier le rôle" : "Créer un rôle"}</CardTitle>
          </CardHeader>
          <CardContent>
            <Form {...form}>
              <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
                <div className="grid gap-3 md:grid-cols-2">
                  <FormField
                    control={form.control}
                    name="name"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Nom du rôle</FormLabel>
                        <FormControl>
                          <Input placeholder="Censeur" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="description"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Description</FormLabel>
                        <FormControl>
                          <Input {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </div>

                <FormField
                  control={form.control}
                  name="permissionIds"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Permissions par module</FormLabel>
                      <FormControl>
                        <div className="flex flex-col gap-4 rounded-md border border-border p-3">
                          {Object.entries(permissionGroups).map(([resource, permissions]) => (
                            <div key={resource} className="flex flex-col gap-1.5">
                              <span className="text-xs font-semibold uppercase text-muted-foreground">
                                {resource}
                              </span>
                              <div className="flex flex-wrap gap-3">
                                {permissions.map((permission) => {
                                  const checked = (field.value ?? []).includes(permission.id);
                                  return (
                                    <label key={permission.id} className="flex items-center gap-2 text-sm">
                                      <input
                                        type="checkbox"
                                        className="h-4 w-4"
                                        checked={checked}
                                        onChange={(e) => {
                                          const current = field.value ?? [];
                                          field.onChange(
                                            e.target.checked
                                              ? [...current, permission.id]
                                              : current.filter((id) => id !== permission.id),
                                          );
                                        }}
                                      />
                                      {permission.action}
                                    </label>
                                  );
                                })}
                              </div>
                            </div>
                          ))}
                          {Object.keys(permissionGroups).length === 0 ? (
                            <span className="text-sm text-muted-foreground">Aucune permission disponible.</span>
                          ) : null}
                        </div>
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <div className="flex gap-2">
                  <Button type="submit" disabled={form.formState.isSubmitting} className="w-fit">
                    {form.formState.isSubmitting ? "Enregistrement…" : editingRoleId ? "Enregistrer" : "Créer le rôle"}
                  </Button>
                  <Button type="button" variant="outline" className="w-fit" onClick={() => setShowForm(false)}>
                    Annuler
                  </Button>
                </div>
              </form>
            </Form>
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Rôles</CardTitle>
          <CardDescription>Rôles système (non modifiables) et rôles personnalisés de l'établissement</CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <LoadingSpinner label="Chargement des rôles…" />
          ) : roles.length === 0 ? (
            <p className="text-sm text-muted-foreground">Aucun rôle enregistré.</p>
          ) : (
            <div className="flex flex-col gap-3">
              {roles.map((role) => (
                <div key={role.id} className="flex items-center justify-between rounded-md border border-border p-3">
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="font-medium">{role.name}</span>
                      {role.isSystem ? (
                        <span className="rounded-full bg-secondary px-2 py-0.5 text-xs text-secondary-foreground">
                          Système
                        </span>
                      ) : null}
                    </div>
                    <p className="text-sm text-muted-foreground">
                      {role.description ?? "—"} · {role.permissions.length} permission(s)
                    </p>
                  </div>
                  {!role.isSystem ? (
                    <div className="flex gap-1">
                      {canUpdate ? (
                        <Button size="sm" variant="outline" onClick={() => startEdit(role)}>
                          <Pencil className="mr-1 h-3.5 w-3.5" />
                          Modifier
                        </Button>
                      ) : null}
                      {canDelete ? (
                        <Button size="sm" variant="ghost" onClick={() => onDelete(role.id)}>
                          <Trash2 className="mr-1 h-3.5 w-3.5" />
                          Supprimer
                        </Button>
                      ) : null}
                    </div>
                  ) : null}
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
