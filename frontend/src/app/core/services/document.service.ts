import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, of, shareReplay, tap } from 'rxjs';
import { apiUrl } from '../config/api.config';
import { ListParams, PaginatedResponse, toHttpParams } from '../list/pagination.model';

/** Entités auxquelles une pièce peut se rattacher. Le serveur fait foi. */
export type DocumentableType = 'lease' | 'property' | 'tenant' | 'portfolio';

export interface AppDocument {
  id: number;
  category: string;
  category_label: string;
  name: string;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  size_label: string;
  issued_on: string | null;
  expires_on: string | null;
  is_expired: boolean;
  expires_soon: boolean;
  notes: string | null;
  attached_to: { type: DocumentableType | null; id: number | null; label: string | null };
  download_url: string;
  preview_url: string | null;
  created_at: string | null;
}

export interface DocumentCategoryOption {
  value: string;
  label: string;
  attachable_to: DocumentableType[];
  validity_months: number | null;
}

export interface DocumentCatalogue {
  categories: DocumentCategoryOption[];
  max_size_kb: number;
  accepted_mimes: string[];
}

export interface DocumentUpload {
  documentable_type: DocumentableType;
  documentable_id: number;
  category: string;
  file: File;
  name?: string | null;
  issued_on?: string | null;
  expires_on?: string | null;
  notes?: string | null;
}

@Injectable({ providedIn: 'root' })
export class DocumentService {
  private readonly http = inject(HttpClient);
  private readonly url = apiUrl('documents');

  /**
   * Catalogue des catégories, demandé une seule fois par session.
   *
   * Il ne change qu'avec une mise en production, et le serveur le sert sans
   * toucher la base. `shareReplay` évite que chaque panneau de documents ouvert
   * dans l'application ne le redemande — sur une page qui en affiche quatre,
   * cela ferait quatre allers-retours pour la même liste figée.
   */
  private catalogue$?: Observable<DocumentCatalogue>;

  private readonly _catalogue = signal<DocumentCatalogue | null>(null);
  readonly catalogue = this._catalogue.asReadonly();

  readonly categories = computed(() => this._catalogue()?.categories ?? []);

  /** Catégories qui ont un sens pour une entité donnée. */
  categoriesFor(type: DocumentableType): DocumentCategoryOption[] {
    return this.categories().filter((category) => category.attachable_to.includes(type));
  }

  loadCatalogue(): Observable<DocumentCatalogue> {
    this.catalogue$ ??= this.http.get<DocumentCatalogue>(`${this.url}/categories`).pipe(
      tap((catalogue) => this._catalogue.set(catalogue)),
      shareReplay({ bufferSize: 1, refCount: false }),
    );

    return this.catalogue$;
  }

  list(params: Partial<ListParams> = {}): Observable<PaginatedResponse<AppDocument>> {
    return this.http.get<PaginatedResponse<AppDocument>>(this.url, {
      params: toHttpParams(params),
    });
  }

  /** Pièces rattachées à une entité précise. */
  listFor(
    type: DocumentableType,
    id: number,
    params: Partial<ListParams> = {},
  ): Observable<PaginatedResponse<AppDocument>> {
    return this.list({
      ...params,
      filters: { ...(params.filters ?? {}), documentable_type: type, documentable_id: String(id) },
    });
  }

  upload(payload: DocumentUpload): Observable<AppDocument> {
    const form = new FormData();

    form.append('documentable_type', payload.documentable_type);
    form.append('documentable_id', String(payload.documentable_id));
    form.append('category', payload.category);
    form.append('file', payload.file);

    for (const field of ['name', 'issued_on', 'expires_on', 'notes'] as const) {
      const value = payload[field];
      if (value) {
        form.append(field, value);
      }
    }

    return this.http.post<AppDocument>(this.url, form);
  }

  update(id: number, changes: Partial<Omit<AppDocument, 'id'>>): Observable<AppDocument> {
    return this.http.put<AppDocument>(`${this.url}/${id}`, changes);
  }

  remove(id: number): Observable<void> {
    return this.http.delete<void>(`${this.url}/${id}`);
  }

  /**
   * Télécharge la pièce en passant par le jeton.
   *
   * Un `<a href>` classique ne transporterait pas l'en-tête d'autorisation :
   * le serveur répondrait 401 et le navigateur enregistrerait la page d'erreur
   * sous le nom du document. On récupère donc le contenu en mémoire avant de
   * déclencher l'enregistrement.
   */
  download(document: AppDocument): Observable<Blob> {
    return this.http.get(document.download_url, { responseType: 'blob' });
  }

  /** Vrai si l'aperçu peut s'afficher dans le navigateur. */
  isPreviewable(document: AppDocument): boolean {
    return document.preview_url !== null;
  }

  isImage(document: AppDocument): boolean {
    return document.mime_type.startsWith('image/');
  }

  /** Catalogue déjà chargé, sinon rien : évite un appel dans un getter. */
  maxSizeLabel(): string {
    const kb = this._catalogue()?.max_size_kb ?? 0;

    return kb === 0 ? '' : `${Math.round(kb / 1024)} Mo`;
  }

  /** Petit utilitaire partagé par les panneaux : `of` garde le type homogène. */
  none(): Observable<AppDocument[]> {
    return of([]);
  }
}
