import {
  Component,
  DestroyRef,
  ElementRef,
  HostListener,
  ViewChild,
  computed,
  effect,
  inject,
  signal,
  ChangeDetectionStrategy,
} from '@angular/core';
import { Router } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject } from 'rxjs';
import { debounceTime, distinctUntilChanged, switchMap, tap } from 'rxjs/operators';
import {
  GlobalSearchService,
  GlobalSearchResponse,
  SearchItem,
} from '../../../core/services/global-search.service';
import { ImmoproBadgeComponent } from 'ui-lib';

@Component({
  selector: 'app-global-search',
  standalone: true,
  imports: [ImmoproBadgeComponent],
  templateUrl: './global-search.component.html',
  styleUrls: ['./global-search.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class GlobalSearchComponent {
  protected readonly searchService = inject(GlobalSearchService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  @ViewChild('searchInput') searchInput?: ElementRef<HTMLInputElement>;

  readonly query = signal('');
  readonly loading = signal(false);
  readonly response = signal<GlobalSearchResponse | null>(null);
  readonly selectedIndex = signal(0);

  private readonly search$ = new Subject<string>();

  // Flatten all items for keyboard navigation (up/down/enter)
  readonly allItems = computed<SearchItem[]>(() => {
    const res = this.response()?.results;
    if (!res) return [];
    return [
      ...(res.portfolios || []),
      ...(res.properties || []),
      ...(res.tenants || []),
      ...(res.leases || []),
      ...(res.alerts || []),
    ];
  });

  readonly hasResults = computed(() => (this.response()?.total ?? 0) > 0);

  constructor() {
    this.search$
      .pipe(
        debounceTime(200),
        distinctUntilChanged(),
        tap(() => this.loading.set(true)),
        switchMap((q) => this.searchService.search(q)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((res) => {
        this.response.set(res);
        this.loading.set(false);
        this.selectedIndex.set(0);
      });

    // Auto-focus input when modal opens
    effect(() => {
      if (this.searchService.isOpen()) {
        this.query.set('');
        this.response.set(null);
        this.selectedIndex.set(0);
        setTimeout(() => {
          this.searchInput?.nativeElement.focus();
        }, 50);
      }
    });
  }

  @HostListener('document:keydown', ['$event'])
  handleGlobalKeydown(event: KeyboardEvent): void {
    // Open on Cmd+K or Ctrl+K
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      this.searchService.toggle();
      return;
    }

    if (!this.searchService.isOpen()) return;

    if (event.key === 'Escape') {
      event.preventDefault();
      this.searchService.close();
      return;
    }

    const items = this.allItems();
    if (items.length === 0) return;

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      this.selectedIndex.update((i) => (i + 1) % items.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      this.selectedIndex.update((i) => (i - 1 + items.length) % items.length);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const current = items[this.selectedIndex()];
      if (current) {
        this.selectItem(current);
      }
    }
  }

  onInput(event: Event): void {
    const val = (event.target as HTMLInputElement).value;
    this.query.set(val);
    this.search$.next(val);
  }

  selectItem(item: SearchItem): void {
    this.searchService.close();
    this.router.navigateByUrl(item.url);
  }

  close(): void {
    this.searchService.close();
  }

  isItemSelected(item: SearchItem): boolean {
    const items = this.allItems();
    return (
      items[this.selectedIndex()]?.id === item.id && items[this.selectedIndex()]?.type === item.type
    );
  }
}
