/**
 * Adresse de l'API.
 *
 * Elle était écrite en dur dans chacun des sept services, ce qui obligeait à
 * sept modifications concordantes pour changer de serveur — et à en oublier au
 * moins une. Le front interroge l'API en absolu et non par un proxy : les
 * échanges sont donc croisés, et cette valeur doit correspondre exactement à ce
 * que `FRONTEND_URL` autorise côté Laravel.
 *
 * `apiUrl` évite l'autre piège classique : un slash de trop entre la base et le
 * chemin donne `//api/leases`, que le routeur de Laravel ne reconnaît pas.
 */
export const API_BASE_URL = 'http://127.0.0.1:8000/api';

export function apiUrl(...segments: (string | number)[]): string {
  const path = segments
    .map((segment) => String(segment).replace(/^\/+|\/+$/g, ''))
    .filter((segment) => segment !== '')
    .join('/');

  return path === '' ? API_BASE_URL : `${API_BASE_URL}/${path}`;
}
