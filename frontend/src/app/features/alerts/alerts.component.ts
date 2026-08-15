import { Component, inject, computed, ChangeDetectionStrategy } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { DatePipe } from '@angular/common';
import {
  ImmoproPageHeaderComponent,
  ImmoproButtonComponent,
  ImmoproEmptyStateComponent,
  ImmoproBadgeComponent,
  ImmoproFilterBarComponent,
  ImmoproSelectComponent,
  ImmoproPaginationComponent,
  FilterChip,
} from 'ui-lib';
import { AlertService, AppAlert, AlertSeverity } from '../../core/services/alert.service';
import { createListQuery } from '../../core/list/list-query';

@Component({
  selector: 'app-alerts',
  standalone: true,
  imports: [
    DatePipe,
    ImmoproPageHeaderComponent,
    ImmoproButtonComponent,
    ImmoproEmptyStateComponent,
    ImmoproBadgeComponent,
    ImmoproFilterBarComponent,
    ImmoproSelectComponent,
    ImmoproPaginationComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="alerts-page">
      <immopro-page-header
        title="Alertes"
        subtitle="Les échéances à surveiller sur vos biens et vos baux : impayés, révision de loyer, fin de bail et diagnostics."
      >
        @if (svc.unreadCount() > 0) {
          <immopro-button actions variant="ghost" (onClick)="markAllRead()"
            >Tout marquer comme lu</immopro-button
          >
        }
      </immopro-page-header>

      <immopro-filter-bar
        searchPlaceholder="Rechercher dans les alertes…"
        [searchValue]="list.search()"
        [canReset]="list.isFiltered()"
        [total]="svc.page()?.total ?? null"
        [activeChips]="activeChips()"
        itemLabel="alerte"
        [loading]="svc.loading()"
        (searchChange)="list.setSearch($event)"
        (removeChip)="list.removeFilter($event)"
        (reset)="list.reset()"
      >
        <immopro-select label="Type" inputId="alert-filter-type">
          <select
            id="alert-filter-type"
            [value]="list.filterValue('type')"
            (change)="list.setFilter('type', $any($event.target).value)"
          >
            <option value="">Tous</option>
            @for (type of typeOptions; track type.value) {
              <option [value]="type.value">{{ type.label }}</option>
            }
          </select>
        </immopro-select>

        <immopro-select label="Gravité" inputId="alert-filter-severity">
          <select
            id="alert-filter-severity"
            [value]="list.filterValue('severity')"
            (change)="list.setFilter('severity', $any($event.target).value)"
          >
            <option value="">Toutes</option>
            @for (severity of severityOptions; track severity.value) {
              <option [value]="severity.value">{{ severity.label }}</option>
            }
          </select>
        </immopro-select>

        <immopro-select label="Affichage" inputId="alert-filter-resolved">
          <select
            id="alert-filter-resolved"
            [value]="list.filterValue('resolved')"
            (change)="list.setFilter('resolved', $any($event.target).value)"
          >
            <option value="">À traiter</option>
            <option value="1">Historique complet</option>
          </select>
        </immopro-select>
      </immopro-filter-bar>

      @if (svc.loading()) {
        <div class="skeleton-list">
          <div class="skeleton-card"></div>
          <div class="skeleton-card"></div>
          <div class="skeleton-card"></div>
        </div>
      } @else if (svc.alerts().length === 0) {
        <immopro-empty-state [title]="emptyTitle()" [message]="emptyMessage()">
          <svg
            icon
            xmlns="http://www.w3.org/2000/svg"
            width="40"
            height="40"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path d="M9 12l2 2 4-4" />
            <circle cx="12" cy="12" r="9" />
          </svg>
        </immopro-empty-state>
      } @else {
        <ul class="alerts-list">
          @for (alert of svc.alerts(); track alert.id) {
            <li
              class="alert-card"
              [class]="'severity-' + alert.severity"
              [class.is-unread]="!alert.is_read"
            >
              <span class="severity-bar" aria-hidden="true"></span>

              <div class="alert-icon" [attr.aria-label]="typeLabel(alert.type)">
                @switch (alert.type) {
                  @case ('loyer_impaye') {
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <line x1="12" y1="2" x2="12" y2="6" />
                      <path d="M17 8a5 5 0 0 0-10 0c0 5-2 6-2 6h14s-2-1-2-6" />
                      <path d="M9 18a3 3 0 0 0 6 0" />
                    </svg>
                  }
                  @case ('revision_irl') {
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <polyline points="23 6 13.5 15.5 8.5 10.5 1 18" />
                      <polyline points="17 6 23 6 23 12" />
                    </svg>
                  }
                  @case ('fin_bail') {
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <rect x="3" y="4" width="18" height="18" rx="2" />
                      <line x1="16" y1="2" x2="16" y2="6" />
                      <line x1="8" y1="2" x2="8" y2="6" />
                      <line x1="3" y1="10" x2="21" y2="10" />
                    </svg>
                  }
                  @case ('dpe_expiration') {
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                      <polyline points="14 2 14 8 20 8" />
                      <line x1="12" y1="18" x2="12" y2="12" />
                      <line x1="9" y1="15" x2="15" y2="15" />
                    </svg>
                  }
                }
              </div>

              <div class="alert-body">
                <div class="alert-top">
                  <span class="type-tag">{{ typeLabel(alert.type) }}</span>
                  <immopro-badge [tone]="severityTone(alert.severity)">{{
                    severityLabel(alert.severity)
                  }}</immopro-badge>
                  @if (!alert.is_read) {
                    <span class="unread-dot" title="Non lue" aria-label="Non lue"></span>
                  }
                </div>

                <h3 class="alert-title">{{ alert.title }}</h3>
                <p class="alert-message">{{ alert.message }}</p>

                @if (alert.due_date) {
                  <p class="alert-meta text-muted">
                    Échéance : {{ alert.due_date | date: 'dd/MM/yyyy' }}
                  </p>
                }
                @if (alert.reminded_at) {
                  <p class="alert-meta text-muted">
                    Locataire relancé le {{ alert.reminded_at | date: 'dd/MM/yyyy' }}
                  </p>
                }
              </div>

              <div class="alert-actions">
                @if (alert.type === 'loyer_impaye') {
                  <button class="btn btn--primary btn--block" (click)="remind(alert)">
                    {{ alert.reminded_at ? 'Relancer à nouveau' : 'Relancer le locataire' }}
                  </button>
                }
                @if (!alert.is_read) {
                  <button class="btn btn--outline btn--block" (click)="markRead(alert)">
                    Marquer comme lu
                  </button>
                }
                <button class="btn btn--outline btn--block" (click)="resolve(alert)">
                  Résoudre
                </button>
              </div>
            </li>
          }
        </ul>

        @if (svc.page(); as pag) {
          <immopro-pagination
            [currentPage]="pag.current_page"
            [lastPage]="pag.last_page"
            [from]="pag.from"
            [to]="pag.to"
            [total]="pag.total"
            [perPage]="pag.per_page"
            [perPageChoices]="list.perPageChoices"
            (pageChange)="list.setPage($event)"
            (perPageChange)="list.setPerPage($event)"
          ></immopro-pagination>
        }
      }
    </div>
  `,
  styleUrl: './alerts.component.scss',
})
export class AlertsComponent {
  protected svc = inject(AlertService);

  protected readonly list = createListQuery({
    defaultSort: 'severity',
    defaultDirection: 'asc',
    perPage: 10,
    filterKeys: ['type', 'severity', 'resolved'],
  });

  protected readonly typeOptions = [
    { value: 'loyer_impaye', label: 'Loyer impayé' },
    { value: 'revision_irl', label: 'Révision du loyer' },
    { value: 'fin_bail', label: 'Fin de bail' },
    { value: 'dpe_expiration', label: 'Diagnostic DPE' },
  ];

  protected readonly severityOptions = [
    { value: 'critical', label: 'Urgent' },
    { value: 'warning', label: 'À prévoir' },
    { value: 'info', label: 'Information' },
  ];

  readonly activeChips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];
    const filters = this.list.filters();
    if (filters['type']) {
      const label =
        this.typeOptions.find((t) => t.value === filters['type'])?.label ?? filters['type'];
      chips.push({ key: 'type', label: 'Type', value: label });
    }
    if (filters['severity']) {
      const label =
        this.severityOptions.find((s) => s.value === filters['severity'])?.label ??
        filters['severity'];
      chips.push({ key: 'severity', label: 'Gravité', value: label });
    }
    if (filters['resolved'] === '1') {
      chips.push({ key: 'resolved', label: 'Statut', value: 'Historique complet' });
    }
    return chips;
  });

  constructor() {
    toObservable(this.list.trigger)
      .pipe(takeUntilDestroyed())
      .subscribe(({ params }) => this.svc.load(params));

    this.svc.loadUnreadCount();
  }

  protected readonly hasUnread = computed(() => this.svc.unreadCount() > 0);

  /**
   * Le message d'état vide distingue « rien à traiter » de « rien qui
   * corresponde au filtre » : sans cela, filtrer sur un type absent laisse
   * croire qu'il n'y a aucune alerte du tout.
   */
  protected readonly emptyTitle = computed(() =>
    this.list.isFiltered() ? 'Aucun résultat' : 'Tout est à jour',
  );

  protected readonly emptyMessage = computed(() =>
    this.list.isFiltered()
      ? 'Aucune alerte ne correspond à ces critères.'
      : "Aucune alerte en attente. Nous vous préviendrons dès qu'une échéance approche.",
  );

  markRead(alert: AppAlert): void {
    this.svc.markRead(alert.id).subscribe();
  }

  markAllRead(): void {
    this.svc.markAllRead().subscribe();
  }

  resolve(alert: AppAlert): void {
    // Recharge la page une fois l'alerte résolue : elle en sort, et la place
    // libérée est reprise par la suivante plutôt que de laisser un trou.
    this.svc.resolve(alert.id).subscribe(() => this.list.refresh());
  }

  remind(alert: AppAlert): void {
    this.svc.remind(alert.id).subscribe();
  }

  typeLabel(type: AppAlert['type']): string {
    return {
      loyer_impaye: 'Loyer impayé',
      revision_irl: 'Révision du loyer',
      fin_bail: 'Fin de bail',
      dpe_expiration: 'Diagnostic DPE',
    }[type];
  }

  severityLabel(severity: AlertSeverity): string {
    return { critical: 'Urgent', warning: 'À prévoir', info: 'Information' }[severity];
  }

  severityTone(severity: AlertSeverity): 'danger' | 'warning' | 'info' {
    return { critical: 'danger' as const, warning: 'warning' as const, info: 'info' as const }[
      severity
    ];
  }
}
