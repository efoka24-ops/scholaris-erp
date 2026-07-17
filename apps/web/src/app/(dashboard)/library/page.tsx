"use client";

import { useCallback, useEffect, useState } from "react";
import { Library, Plus, Search } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { Student } from "@/types/students";
import type { PaginatedResult } from "@scholaris/shared";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface LibraryBook {
  id: string;
  title: string;
  author: string | null;
  isbn: string | null;
  category: string | null;
  quantity: number;
  available: number;
}

interface LibraryBorrow {
  id: string;
  bookId: string;
  studentId: string;
  borrowDate: string;
  dueDate: string;
  returnDate: string | null;
}

function inTwoWeeksIso() {
  const d = new Date();
  d.setDate(d.getDate() + 14);
  return d.toISOString().slice(0, 10);
}

export default function LibraryPage() {
  const [books, setBooks] = useState<LibraryBook[]>([]);
  const [isLoadingBooks, setIsLoadingBooks] = useState(true);
  const [search, setSearch] = useState("");
  const [error, setError] = useState<string | null>(null);

  const [showBookForm, setShowBookForm] = useState(false);
  const [title, setTitle] = useState("");
  const [author, setAuthor] = useState("");
  const [isbn, setIsbn] = useState("");
  const [category, setCategory] = useState("");
  const [quantity, setQuantity] = useState(1);
  const [isSubmittingBook, setIsSubmittingBook] = useState(false);

  const [students, setStudents] = useState<Student[]>([]);
  const [borrows, setBorrows] = useState<LibraryBorrow[]>([]);
  const [showBorrowForm, setShowBorrowForm] = useState(false);
  const [borrowBookId, setBorrowBookId] = useState("");
  const [borrowStudentId, setBorrowStudentId] = useState("");
  const [dueDate, setDueDate] = useState(inTwoWeeksIso());
  const [isSubmittingBorrow, setIsSubmittingBorrow] = useState(false);

  const loadBooks = useCallback(() => {
    setIsLoadingBooks(true);
    resourceClient
      .get<{ items: LibraryBook[]; total: number; page: number; limit: number; totalPages: number }>(
        "/library/books",
        { params: { limit: 50, ...(search ? { search } : {}) } },
      )
      .then((response) => setBooks(response.data.items))
      .catch((requestError: any) => setError(requestError.response?.data?.message ?? "Impossible de charger le catalogue."))
      .finally(() => setIsLoadingBooks(false));
  }, [search]);

  useEffect(() => {
    loadBooks();
  }, [loadBooks]);

  useEffect(() => {
    resourceClient
      .get<PaginatedResult<Student>>("/students", { params: { limit: 100 } })
      .then((response) => setStudents(response.data.data));
  }, []);

  async function handleCreateBook() {
    setError(null);
    setIsSubmittingBook(true);
    try {
      await resourceClient.post("/library/books", {
        title,
        ...(author ? { author } : {}),
        ...(isbn ? { isbn } : {}),
        ...(category ? { category } : {}),
        quantity,
        available: quantity,
      });
      setTitle("");
      setAuthor("");
      setIsbn("");
      setCategory("");
      setQuantity(1);
      setShowBookForm(false);
      loadBooks();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible d'ajouter le livre.");
    } finally {
      setIsSubmittingBook(false);
    }
  }

  async function handleCreateBorrow() {
    setError(null);
    setIsSubmittingBorrow(true);
    try {
      const { data } = await resourceClient.post<LibraryBorrow>("/library/borrow", {
        bookId: borrowBookId,
        studentId: borrowStudentId,
        dueDate,
      });
      setBorrows((prev) => [data, ...prev]);
      setBorrowBookId("");
      setBorrowStudentId("");
      setShowBorrowForm(false);
      loadBooks();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible d'enregistrer l'emprunt.");
    } finally {
      setIsSubmittingBorrow(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Bibliothèque</h1>
          <p className="text-sm text-muted-foreground">Catalogue de livres, emprunts et retours</p>
        </div>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Catalogue</CardTitle>
              <CardDescription>Recherche et gestion des ouvrages</CardDescription>
            </div>
            <Button size="sm" onClick={() => setShowBookForm((v) => !v)}>
              <Plus className="mr-2 h-4 w-4" />
              Ajouter un livre
            </Button>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="flex items-center gap-2">
              <Search className="h-4 w-4 text-muted-foreground" />
              <Input placeholder="Titre, auteur ou ISBN…" value={search} onChange={(e) => setSearch(e.target.value)} />
            </div>

            {showBookForm ? (
              <div className="flex flex-col gap-2 rounded-md border border-border p-3">
                <Input placeholder="Titre" value={title} onChange={(e) => setTitle(e.target.value)} />
                <Input placeholder="Auteur (optionnel)" value={author} onChange={(e) => setAuthor(e.target.value)} />
                <Input placeholder="ISBN (optionnel)" value={isbn} onChange={(e) => setIsbn(e.target.value)} />
                <Input placeholder="Catégorie (optionnel)" value={category} onChange={(e) => setCategory(e.target.value)} />
                <Input type="number" min={1} placeholder="Quantité" value={quantity} onChange={(e) => setQuantity(Number(e.target.value))} />
                <Button size="sm" disabled={!title || isSubmittingBook} onClick={handleCreateBook}>
                  {isSubmittingBook ? "Ajout…" : "Ajouter"}
                </Button>
              </div>
            ) : null}

            {isLoadingBooks ? (
              <LoadingSpinner label="Chargement…" />
            ) : books.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <Search className="h-10 w-10 text-muted-foreground mb-3" />
                <p className="text-sm text-muted-foreground">Aucun livre pour le moment</p>
              </div>
            ) : (
              <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
                {books.map((book) => (
                  <li key={book.id} className="flex items-center justify-between px-4 py-2 text-sm">
                    <div>
                      <p className="font-medium">{book.title}</p>
                      <p className="text-muted-foreground">{book.author ?? "Auteur inconnu"}</p>
                    </div>
                    <span className="text-xs text-muted-foreground">{book.available}/{book.quantity} disponible(s)</span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Emprunts en cours</CardTitle>
              <CardDescription>Suivi des emprunts et retours</CardDescription>
            </div>
            <Button size="sm" onClick={() => setShowBorrowForm((v) => !v)}>
              <Plus className="mr-2 h-4 w-4" />
              Nouvel emprunt
            </Button>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {showBorrowForm ? (
              <div className="flex flex-col gap-2 rounded-md border border-border p-3">
                <select className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={borrowBookId} onChange={(e) => setBorrowBookId(e.target.value)}>
                  <option value="">Livre…</option>
                  {books.map((b) => (
                    <option key={b.id} value={b.id}>{b.title}</option>
                  ))}
                </select>
                <select className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={borrowStudentId} onChange={(e) => setBorrowStudentId(e.target.value)}>
                  <option value="">Élève…</option>
                  {students.map((s) => (
                    <option key={s.id} value={s.id}>{s.lastName} {s.firstName}</option>
                  ))}
                </select>
                <input type="date" className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
                <Button size="sm" disabled={!borrowBookId || !borrowStudentId || isSubmittingBorrow} onClick={handleCreateBorrow}>
                  {isSubmittingBorrow ? "Enregistrement…" : "Enregistrer l'emprunt"}
                </Button>
              </div>
            ) : null}

            {borrows.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center">
                <Library className="h-10 w-10 text-muted-foreground mb-3" />
                <p className="text-sm text-muted-foreground">Aucun emprunt enregistré dans cette session</p>
              </div>
            ) : (
              <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
                {borrows.map((borrow) => (
                  <li key={borrow.id} className="flex items-center justify-between px-4 py-2 text-sm">
                    <span>Emprunt #{borrow.id.slice(0, 8)}</span>
                    <span className="text-xs text-muted-foreground">
                      Échéance {new Date(borrow.dueDate).toLocaleDateString("fr-FR")}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
