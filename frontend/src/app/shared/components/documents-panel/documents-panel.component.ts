import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core';
import { DatePipe } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { FormsModule } from '@angular/forms';
import {
  ImmoproBadgeComponent,
  ImmoproButtonComponent,
  ImmoproEmptyStateComponent,
  ImmoproModalComponent,
  ImmoproSkeletonComponent,
} from 'ui-lib';
import {
  AppDocument,
  DocumentService,
  DocumentableType,
} from '../../../core/services/document.service';
import { ConfirmService } from '../../../core/services/confirm.service';
import { NotificationService } from '../../../core/services/notification.service';

/**
 * Pièces jointes d'un élément — bail, bien, locataire ou portefeuille.
 *
 * Le même panneau sert les quatre : les règles de dépôt, les catégories
 * autorisées et les limites de taille viennent du serveur, si bien qu'un écran
 * n'a rien à savoir de ce qu'il affiche au-delà du type et de l'identifiant.
 *
 * Le dépôt accepte le glisser-déposer comme le sélecteur de fichier. Ce n'est
 * pas de la coquetterie : un gestionnaire qui archive un bail signé arrive
 * presque toujours avec le fichier sous la main, et l'obliger à traverser une
 * arborescence pour le retrouver est le genre de friction qui fait renoncer à
 * classer.
 */
@Component({
  selector: 'app-documents-panel',
  standalone: true,
  imports: [
    DatePipe,
    FormsModule,
    ImmoproBadgeComponent,
    ImmoproButtonComponent,
    ImmoproEmptyStateComponent,
    ImmoproModalComponent,
    ImmoproSkeletonComponent,
  ],
  templateUrl: './documents-panel.component.html',
  styleUrl: './documents-panel.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DocumentsPanelComponent {
  private readonly documents = inject(DocumentService);
  private readonly notifications = inject(NotificationService);
  private readonly confirm = inject(ConfirmService);
  private readonly sanitizer = inject(DomSanitizer);

  readonly type = input.required<DocumentableType>();
  readonly entityId = input.required<number>();

  /** Rappelé dans le titre du dépôt : « Bail signé — 12, rue des Lilas ». */
  readonly entityLabel = input<string>('');

  /** Replie le panneau par défaut sur les écrans déjà denses. */
  readonly collapsible = input<boolean>(false);

  protected readonly items = signal<AppDocument[]>([]);
  protected readonly loading = signal(false);
  protected readonly expanded = signal(true);

  protected readonly uploadOpen = signal(false);
  protected readonly uploading = signal(false);
  protected readonly dragging = signal(false);

  protected readonly pendingFile = signal<File | null>(null);
  protected readonly form = signal({ category: '', name: '', issued_on: '', notes: '' });

  protected readonly preview = signal<AppDocument | null>(null);

  /**
   * Angular refuse par défaut une URL dans `iframe.src`, et il a raison : c'est
   * la voie royale pour injecter une page tierce. Celle-ci vient de notre propre
   * API, signée et à durée limitée, jamais d'une saisie — la lever ici est donc
   * une décision, pas un contournement.
   */
  protected readonly previewUrl = computed<SafeResourceUrl | null>(() => {
    const url = this.preview()?.preview_url;

    return url ? this.sanitizer.bypassSecurityTrustResourceUrl(url) : null;
  });

  protected readonly categories = computed(() => this.documents.categoriesFor(this.type()));
  protected readonly maxSizeLabel = computed(() => this.documents.maxSizeLabel());

  /** Pièces périmées ou sur le point de l'être : ce sont celles qui appellent une action. */
  protected readonly attention = computed(
    () => this.items().filter((item) => item.is_expired || item.expires_soon).length,
  );

  protected readonly skeletons = [0, 1];

  constructor() {
    this.documents.loadCatalogue().subscribe({ error: () => undefined });

    effect(() => {
      this.expanded.set(!this.collapsible());
    });

    // Recharge dès que l'élément affiché change — passer d'un bail à l'autre
    // sans cela laisserait les pièces du précédent à l'écran.
    effect(() => {
      const type = this.type();
      const id = this.entityId();

      if (!id) {
        return;
      }

      this.load(type, id);
    });
  }

  private load(type: DocumentableType, id: number): void {
    this.loading.set(true);

    this.documents.listFor(type, id, { per_page: 50 }).subscribe({
      next: (page) => {
        this.items.set(page.data);
        this.loading.set(false);
      },
      error: (error) => {
        this.loading.set(false);
        this.notifications.fromHttp(error, 'Impossible de charger les documents.');
      },
    });
  }

  protected toggle(): void {
    this.expanded.update((open) => !open);
  }

  // ── Dépôt ────────────────────────────────────────────────────────────────

  protected onDragOver(event: DragEvent): void {
    event.preventDefault();
    this.dragging.set(true);
  }

  protected onDragLeave(): void {
    this.dragging.set(false);
  }

  protected onDrop(event: DragEvent): void {
    event.preventDefault();
    this.dragging.set(false);

    const file = event.dataTransfer?.files?.[0];

    if (file) {
      this.startUpload(file);
    }
  }

  protected onFileChosen(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (file) {
      this.startUpload(file);
    }

    // Sans cette remise à zéro, redéposer deux fois le même fichier
    // n'émettrait pas de second événement.
    input.value = '';
  }

  private startUpload(file: File): void {
    const defaultCategory = this.categories()[0]?.value ?? '';

    this.pendingFile.set(file);
    this.form.set({
      category: defaultCategory,
      // Le nom du fichier sans son extension : c'est presque toujours le bon
      // libellé, et il reste modifiable.
      name: file.name.replace(/\.[^.]+$/, ''),
      issued_on: '',
      notes: '',
    });
    this.uploadOpen.set(true);
  }

  protected cancelUpload(): void {
    this.uploadOpen.set(false);
    this.pendingFile.set(null);
  }

  protected submitUpload(): void {
    const file = this.pendingFile();
    const values = this.form();

    if (!file || !values.category) {
      return;
    }

    this.uploading.set(true);

    this.documents
      .upload({
        documentable_type: this.type(),
        documentable_id: this.entityId(),
        category: values.category,
        file,
        name: values.name || null,
        issued_on: values.issued_on || null,
        notes: values.notes || null,
      })
      .subscribe({
        next: (created) => {
          this.items.update((items) => [created, ...items]);
          this.uploading.set(false);
          this.uploadOpen.set(false);
          this.pendingFile.set(null);
          this.notifications.success(`« ${created.name} » a été ajouté.`);
        },
        error: (error) => {
          this.uploading.set(false);
          this.notifications.fromHttp(error, 'Le dépôt du document a échoué.');
        },
      });
  }

  // ── Actions sur une pièce ────────────────────────────────────────────────

  protected download(item: AppDocument): void {
    this.documents.download(item).subscribe({
      next: (blob) => this.saveBlob(blob, item.original_name),
      error: (error) => this.notifications.fromHttp(error, 'Le téléchargement a échoué.'),
    });
  }

  /**
   * Déclenche l'enregistrement du contenu déjà récupéré.
   *
   * L'URL objet doit être libérée : chaque appel réserve de la mémoire jusqu'au
   * déchargement de la page, et un utilisateur qui télécharge vingt pièces d'un
   * dossier les cumulerait toutes.
   */
  private saveBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    link.click();

    URL.revokeObjectURL(url);
  }

  protected openPreview(item: AppDocument): void {
    this.preview.set(item);
  }

  protected closePreview(): void {
    this.preview.set(null);
  }

  protected async remove(item: AppDocument): Promise<void> {
    const confirmed = await this.confirm.ask({
      title: 'Supprimer ce document ?',
      message: `« ${item.name} » sera retiré du dossier. La suppression est réversible : le fichier reste conservé.`,
      confirmLabel: 'Supprimer',
      danger: true,
    });

    if (!confirmed) {
      return;
    }

    this.documents.remove(item.id).subscribe({
      next: () => {
        this.items.update((items) => items.filter((candidate) => candidate.id !== item.id));
        this.notifications.success('Document supprimé.');
      },
      error: (error) => this.notifications.fromHttp(error, 'La suppression a échoué.'),
    });
  }

  protected isImage(item: AppDocument): boolean {
    return this.documents.isImage(item);
  }
}
