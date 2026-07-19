import { Injectable, signal, computed, effect } from '@angular/core';

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
    localStorage.setItem(this.localStorageKey, nextTheme);
  }

  private initializeTheme(): void {
    const storedTheme = localStorage.getItem(this.localStorageKey) as 'dark' | 'light' | null;
    if (storedTheme) {
      this.theme.set(storedTheme);
    } else {
      this.theme.set('light');
      localStorage.setItem(this.localStorageKey, 'light');
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
