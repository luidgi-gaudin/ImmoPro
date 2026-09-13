import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import {
  TenantSpaceDocument,
  TenantSpaceLease,
  TenantSpaceService,
  TenantUploadableCategory,
} from '../../core/services/tenant-space.service';
import { NotificationService } from '../../core/services/notification.service';
import {
  ImmoproCardComponent,
  ImmoproPageHeaderComponent,
  ImmoproBadgeComponent,
  ImmoproButtonComponent,
  ImmoproInputComponent,
  ImmoproSelectComponent,
} from 'ui-lib';

/**
 * Documents du locataire : ceux que son bailleur dépose, et les siens.
 *
 * Le formulaire de dépôt ne propose que les catégories que le serveur autorise,
 * et il les lui demande. Les écrire ici ferait apparaître « quittance » dans le
 * menu jusqu'à ce que quelqu'un essaie de la déposer et se voie refuser sans
 * comprendre pourquoi.
 */
@Component({
  selector: 'app-tenant-space-documents',
  standalone: true,
  imports: [
    DatePipe,
    ReactiveFormsModule,
    RouterLink,
    ImmoproCardComponent,
    ImmoproPageHeaderComponent,
    ImmoproBadgeComponent,
    ImmoproButtonComponent,
    ImmoproInputComponent,
    ImmoproSelectComponent,
  ],
  templateUrl: './tenant-space-documents.component.html',
  styleUrls: ['./tenant-space-documents.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TenantSpaceDocumentsComponent {
  private service = inject(TenantSpaceService);
  private notifications = inject(NotificationService);
  private fb = inject(FormBuilder);

  readonly loading = signal(true);
  readonly uploading = signal(false);
  readonly error = signal<string | null>(null);

  readonly documents = signal<TenantSpaceDocument[]>([]);
  readonly leases = signal<TenantSpaceLease[]>([]);
  readonly categories = signal<TenantUploadableCategory[]>([]);
  readonly maxSizeMb = signal(20);

  readonly form: FormGroup;
  readonly file = signal<File | null>(null);

  /**
   * Cibles proposées : les baux du locataire, plus son dossier personnel.
   *
   * Un locataire n'a jamais trente lignes ici — un bail, deux au plus. Une
   * liste déroulante suffit, là où le bailleur a besoin d'une recherche.
   */
  readonly targets = computed(() => [
    ...this.leases().map((lease) => ({
      value: `lease:${lease.id}`,
      label: `Bail — ${lease.property?.title ?? 'logement'}`,
    })),
    { value: 'tenant:self', label: 'Mon dossier personnel' },
  ]);

  /**
   * Cible choisie, suivie dans un signal.
   *
   * La valeur d'un contrôle de formulaire n'est pas un signal : la lire depuis
   * un `computed` ne crée aucune dépendance, et la liste des catégories restait
   * figée sur son premier calcul — celui fait alors qu'aucune cible n'était
   * encore sélectionnée. Le menu proposait donc les pièces du dossier
   * personnel même après avoir choisi un bail.
   */
  private readonly selectedTarget = signal('');

  /** Catégories applicables à la cible choisie. */
  readonly applicableCategories = computed(() => {
    const kind = this.selectedTarget().startsWith('lease:') ? 'lease' : 'tenant';

    return this.categories().filter((category) => category.attachable_to.includes(kind));
  });

  private profileId = signal<number | null>(null);

  constructor() {
    this.form = this.fb.group({
      target: ['', [Validators.required]],
      category: ['', [Validators.required]],
      name: [''],
      issued_on: [''],
      expires_on: [''],
    });

    this.form.get('target')?.valueChanges.subscribe((value: string) => {
      this.selectedTarget.set(value ?? '');

      // La catégorie retenue peut ne plus s'appliquer à la nouvelle cible :
      // la laisser en place ferait échouer le dépôt sur une valeur que le
      // menu n'affiche même plus.
      const stillValid = this.applicableCategories().some(
        (category) => category.value === this.form.get('category')?.value,
      );

      if (!stillValid) {
        this.form.get('category')?.setValue('');
      }
    });

    this.load();
  }

  private load(): void {
    this.loading.set(true);

    this.service.uploadableCategories().subscribe({
      next: (response) => {
        this.categories.set(response.categories);
        this.maxSizeMb.set(Math.round(response.max_size_kb / 1024));
      },
      error: () => this.categories.set([]),
    });

    this.service.overview().subscribe({
      next: (response) => {
        this.leases.set(response.data.leases);
        this.profileId.set(response.data.profiles[0]?.id ?? null);

        const firstLease = response.data.leases[0];

        if (firstLease) {
          this.form.get('target')?.setValue(`lease:${firstLease.id}`);
        } else {
          this.form.get('target')?.setValue('tenant:self');
        }
      },
      error: () => this.leases.set([]),
    });

    this.reloadDocuments();
  }

  reloadDocuments(): void {
    this.loading.set(true);

    this.service.documents({ per_page: '50' }).subscribe({
      next: (response) => {
        this.documents.set(response.data);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set("Vos documents n'ont pas pu être chargés.");
      },
    });
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;

    this.file.set(input.files?.[0] ?? null);
  }

  submit(): void {
    this.error.set(null);

    const chosen = this.file();

    if (this.form.invalid || !chosen) {
      this.error.set('Choisissez un fichier, une destination et une catégorie.');

      return;
    }

    const [kind, rawId] = String(this.form.get('target')?.value).split(':');
    const targetId = kind === 'tenant' ? this.profileId() : Number(rawId);

    if (!targetId) {
      this.error.set("Aucun dossier n'est rattaché à votre compte.");

      return;
    }

    this.uploading.set(true);

    this.service
      .upload({
        documentable_type: kind === 'tenant' ? 'tenant' : 'lease',
        documentable_id: targetId,
        category: this.form.get('category')?.value,
        file: chosen,
        name: this.form.get('name')?.value || undefined,
        issued_on: this.form.get('issued_on')?.value || undefined,
        expires_on: this.form.get('expires_on')?.value || undefined,
      })
      .subscribe({
        next: () => {
          this.uploading.set(false);
          this.file.set(null);
          this.form.patchValue({ name: '', issued_on: '', expires_on: '' });
          this.notifications.success('Votre pièce a été transmise à votre bailleur.');
          this.reloadDocuments();
        },
        error: (error: unknown) => {
          this.uploading.set(false);

          const errors = (error as HttpErrorResponse)?.error?.errors as
            | Record<string, string[]>
            | undefined;

          this.error.set(
            errors
              ? Object.values(errors).flat().join(' ')
              : ((error as HttpErrorResponse)?.error?.message ?? 'Le dépôt a échoué.'),
          );
        },
      });
  }

  expiryTone(document: TenantSpaceDocument): 'danger' | 'warning' | 'neutral' {
    return document.is_expired ? 'danger' : document.expires_soon ? 'warning' : 'neutral';
  }

  expiryLabel(document: TenantSpaceDocument): string {
    return document.is_expired ? 'Périmé' : 'Expire bientôt';
  }

  download(item: TenantSpaceDocument): void {
    this.service.download(item.id).subscribe({
      next: (blob) => this.saveBlob(blob, item.original_name),
      error: () => this.notifications.error('Le téléchargement a échoué.'),
    });
  }

  /**
   * Enregistre le contenu déjà récupéré.
   *
   * L'URL objet est libérée aussitôt : chaque appel réserve de la mémoire
   * jusqu'au déchargement de la page, et un dossier de vingt pièces les
   * cumulerait toutes.
   */
  private saveBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    link.click();

    URL.revokeObjectURL(url);
  }
}
