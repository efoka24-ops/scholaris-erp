"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { resourceClient } from "@/lib/api-client";
import type { CommunicationTemplate } from "@/types/communication-templates";
import { LoadingSpinner } from "@/components/shared/loading-spinner";
import { TemplateForm } from "../../template-form";

export default function EditTemplatePage() {
  const params = useParams<{ id: string }>();
  const [template, setTemplate] = useState<CommunicationTemplate | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    resourceClient
      .get<CommunicationTemplate[]>("/communication-templates")
      .then((response) => {
        const found = response.data.find((item) => item.id === params.id) ?? null;
        setTemplate(found);
        if (!found) setError("Template introuvable.");
      })
      .catch((requestError: any) =>
        setError(requestError.response?.data?.message ?? "Impossible de charger le template."),
      );
  }, [params.id]);

  if (error) return <p className="text-sm font-medium text-destructive">{error}</p>;
  if (!template) return <LoadingSpinner label="Chargement…" />;

  return <TemplateForm template={template} />;
}
