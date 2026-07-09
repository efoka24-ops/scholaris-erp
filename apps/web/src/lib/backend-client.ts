import axios from "axios";

/**
 * Client server-side (Route Handlers uniquement) vers l'API NestJS.
 * Ne jamais importer ceci dans un composant client : le JWT ne doit
 * jamais transiter par le JS du navigateur (cookies httpOnly, §1.4 du guide).
 */
export const backendClient = axios.create({
  baseURL: process.env.NEST_API_URL ?? "http://localhost:3001/api",
  timeout: 10_000,
});
