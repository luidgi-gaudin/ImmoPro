import { Injectable, signal } from '@angular/core';

/**
 * État de mise en page partagé par le shell applicatif.
 * Pilote l'ouverture du menu latéral sur mobile (drawer).
 */
@Injectable({ providedIn: 'root' })
export class LayoutService {
  /** Menu latéral ouvert (uniquement pertinent en vue mobile). */
  readonly sidebarOpen = signal(false);

  toggleSidebar(): void {
    this.sidebarOpen.update((open) => !open);
  }

  closeSidebar(): void {
    this.sidebarOpen.set(false);
  }
}
