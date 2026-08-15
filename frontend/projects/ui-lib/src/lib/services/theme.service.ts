import { Injectable, signal, computed, effect } from '@angular/core';

/** Clé écrite par CookieConsentService côté application. */
const CONSENT_KEY = 'cookie_consent';

@Injectable({
  providedIn: 'root',
})
export class ThemeService {
  private readonly localStorageKey = 'theme';

  // Theme state signal, default to 'light'
  theme = signal<'dark' | 'light'>('light');

  // Computed helpers for quick reference
  isLightTheme = computed(() => this.theme() === 'light');
  isDarkTheme = computed(() => this.theme() === 'dark');

  constructor() {
    this.initializeTheme();

    // Automatically apply theme changes to the DOM whenever the signal changes
    effect(() => {
      this.applyTheme(this.theme());
    });
  }

  toggleTheme(): void {
    const nextTheme = this.theme() === 'dark' ? 'light' : 'dark';
    this.theme.set(nextTheme);

    // Le thème change bien à l'écran, mais n'est mémorisé que si l'utilisateur
    // a accepté la catégorie « préférences ». Sans cette garde, refuser dans le
    // bandeau n'empêcherait rien : le premier clic sur le sélecteur de thème
    // réécrirait aussitôt la clé qui vient d'être effacée.
    if (this.canPersist()) {
      this.write(nextTheme);
    }
  }

  private initializeTheme(): void {
    const storedTheme = localStorage.getItem(this.localStorageKey) as 'dark' | 'light' | null;

    if (storedTheme) {
      this.theme.set(storedTheme);
      return;
    }

    this.theme.set('light');

    if (this.canPersist()) {
      this.write('light');
    }
  }

  /**
   * Lit directement la décision de consentement plutôt que d'injecter le
   * service applicatif : ui-lib est une bibliothèque autonome et ne doit pas
   * dépendre du code de l'application qui la consomme.
   */
  private canPersist(): boolean {
    try {
      const raw = localStorage.getItem(CONSENT_KEY);
      if (!raw) {
        return false;
      }

      return JSON.parse(raw)?.choices?.preferences === true;
    } catch {
      return false;
    }
  }

  private write(value: 'dark' | 'light'): void {
    try {
      localStorage.setItem(this.localStorageKey, value);
    } catch {
      // Navigation privée : le thème reste appliqué pour la session en cours.
    }
  }

  private applyTheme(currentTheme: 'dark' | 'light'): void {
    const docEl = document.documentElement;
    const bodyEl = document.body;

    if (currentTheme === 'light') {
      docEl.classList.add('light-theme');
      docEl.setAttribute('data-theme', 'light');
      bodyEl.classList.add('light-theme');
    } else {
      docEl.classList.remove('light-theme');
      docEl.removeAttribute('data-theme');
      bodyEl.classList.remove('light-theme');
    }
  }
}
