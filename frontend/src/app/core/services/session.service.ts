import { Injectable, computed, effect, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { apiUrl } from '../config/api.config';

/**
 * Échéances de la session, telles que l'API les annonce.
 *
 * Il y en a deux, et c'est voulu : l'une absolue depuis la connexion, l'autre
 * repoussée par l'activité. `ends_at` est la plus proche des deux, et
 * `ends_because` dit laquelle — ce qui change le message à afficher.
 */
export interface SessionState {
  expires_at: string | null;
  idle_expires_at: string | null;
  ends_at: string | null;
  ends_because: 'inactivite' | 'duree_maximale' | null;
  idle_minutes: number;
  ttl_minutes: number;
  warn_seconds: number;
}

/**
 * Fenêtre pendant laquelle le serveur n'a pas réécrit l'horodatage d'usage.
 *
 * Le modèle PersonalAccessToken ne l'écrit qu'au-delà de cinq minutes, pour ne
 * pas payer une écriture distante sur chaque page. La conséquence ici : la
 * valeur enregistrée peut être en retard de cinq minutes sur la réalité, donc
 * le serveur peut couper jusqu'à cinq minutes plus tôt que ne le croirait un
 * calcul naïf. On retranche cette marge, pour prévenir avant la coupure plutôt
 * qu'après.
 */
const SERVER_FRESHNESS_MARGIN_MS = 5 * 60_000;

/**
 * Surveille la fin de session et prévient avant la coupure.
 *
 * Sans cela, l'expiration se découvre en recevant un 401 — c'est-à-dire au
 * moment où l'on valide un formulaire, et où la saisie est perdue. Le compte à
 * rebours n'est donc pas décoratif : il existe pour laisser le temps de cliquer
 * sur « rester connecté », ou d'enregistrer.
 */
@Injectable({ providedIn: 'root' })
export class SessionService {
  private readonly http = inject(HttpClient);

  private readonly state = signal<SessionState | null>(null);

  /** Instant estimé de fermeture, réévalué à chaque signe d'activité. */
  private readonly endsAt = signal<number | null>(null);

  /** Recalculé chaque seconde : c'est ce qui pilote l'affichage du compte à rebours. */
  private readonly now = signal(Date.now());

  readonly secondsLeft = computed(() => {
    const end = this.endsAt();
    return end === null ? null : Math.max(0, Math.round((end - this.now()) / 1000));
  });

  /** Vrai pendant le préavis : l'écran doit alors proposer de prolonger. */
  readonly isWarning = computed(() => {
    const left = this.secondsLeft();
    const warn = this.state()?.warn_seconds ?? 120;

    return left !== null && left > 0 && left <= warn;
  });

  /** Vrai quand la session est terminée côté client : il faut se reconnecter. */
  readonly hasEnded = computed(() => this.secondsLeft() === 0);

  /**
   * Faux quand seule la durée maximale reste : proposer « rester connecté » y
   * serait mensonger, puisque rien ne peut la repousser.
   */
  readonly canExtend = computed(() => this.state()?.ends_because !== 'duree_maximale');

  private ticker?: ReturnType<typeof setInterval>;

  constructor() {
    // L'horloge ne tourne que lorsqu'une session est suivie : un intervalle qui
    // s'exécute sur l'écran de connexion ne sert à rien et empêche le navigateur
    // de mettre l'onglet en veille.
    effect(() => {
      const tracked = this.endsAt() !== null;

      if (tracked && this.ticker === undefined) {
        this.ticker = setInterval(() => this.now.set(Date.now()), 1000);
      }

      if (!tracked && this.ticker !== undefined) {
        clearInterval(this.ticker);
        this.ticker = undefined;
      }
    });
  }

  /** Prend en compte les échéances renvoyées par la connexion ou par /auth/user. */
  adopt(state: SessionState | null | undefined): void {
    if (!state?.ends_at) {
      return;
    }

    this.state.set(state);
    this.endsAt.set(new Date(state.ends_at).getTime());
  }

  /**
   * Signale une activité : toute requête réussie repousse l'échéance
   * d'inactivité côté serveur, la vue locale doit suivre.
   *
   * L'échéance absolue, elle, n'est jamais repoussée — sinon elle ne serait pas
   * absolue. On garde donc la plus proche des deux.
   */
  noteActivity(): void {
    const current = this.state();

    if (current === null || current.ends_because === 'duree_maximale') {
      return;
    }

    const idleDeadline = Date.now() + current.idle_minutes * 60_000 - SERVER_FRESHNESS_MARGIN_MS;
    const absolute = current.expires_at ? new Date(current.expires_at).getTime() : Infinity;

    this.endsAt.set(Math.min(idleDeadline, absolute));
  }

  /** « Rester connecté » : demande au serveur de repousser l'échéance. */
  extend(): void {
    this.http.post<{ session: SessionState }>(apiUrl('auth/session/extend'), {}).subscribe({
      next: (response) => this.adopt(response.session),
      // Un échec signifie que la session est déjà close : l'intercepteur se
      // charge alors de la redirection, inutile d'en rajouter ici.
      error: () => undefined,
    });
  }

  clear(): void {
    this.state.set(null);
    this.endsAt.set(null);
  }

  /** « 1 min 42 s » plutôt que « 102 ». */
  readonly countdownLabel = computed(() => {
    const left = this.secondsLeft();

    if (left === null) {
      return '';
    }

    const minutes = Math.floor(left / 60);
    const seconds = left % 60;

    return minutes > 0 ? `${minutes} min ${String(seconds).padStart(2, '0')} s` : `${seconds} s`;
  });
}
