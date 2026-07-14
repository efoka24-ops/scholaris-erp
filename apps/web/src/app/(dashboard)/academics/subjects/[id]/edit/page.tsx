"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { resourceClient } from "@/lib/api-client";
import type { Subject } from "@/types/subjects";
import { LoadingSpinner } from "@/components/shared/loading-spinner";
import { SubjectForm } from "../../subject-form";

export default function EditSubjectPage() {
  const params = useParams<{ id: string }>();
  const [subject, setSubject] = useState<Subject | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!params?.id) return;
    resourceClient
      .get<Subject>(`/subjects/${params.id}`)
      .then((response) => setSubject(response.data))
      .catch((requestError: any) =>
        setError(requestError.response?.data?.message ?? "Impossible de charger la matière."),
      );
  }, [params?.id]);

  if (error) {
    return <p className="text-sm font-medium text-destructive">{error}</p>;
  }
  if (!subject) {
    return <LoadingSpinner label="Chargement de la matière…" />;
  }
  return <SubjectForm subject={subject} />;
}
