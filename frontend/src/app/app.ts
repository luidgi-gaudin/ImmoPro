import { Component, inject, signal, computed, ChangeDetectionStrategy } from '@angular/core';
import { Router, RouterOutlet, NavigationEnd } from '@angular/router';
import { filter } from 'rxjs/operators';
import { SidebarComponent } from './shared/components/sidebar/sidebar.component';
import { TopbarComponent } from './shared/components/topbar/topbar.component';
import { AuthService } from './core/services/auth.service';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, SidebarComponent, TopbarComponent],
  templateUrl: './app.html',
  styleUrl: './app.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class App {
  private authService = inject(AuthService);
  private router = inject(Router);

  protected readonly title = signal('frontend');

  // Track current URL as a Signal for Zoneless change detection compatibility
  private currentUrl = signal<string>('');

  // Le shell (sidebar + topbar) n'apparaît que sur l'espace applicatif :
  // pas sur la landing publique ni sur les écrans d'authentification.
  private readonly publicPrefixes = ['/login', '/register', '/forgot-password', '/reset-password'];

  protected showChrome = computed(() => {
    const url = this.currentUrl();
    return (
      this.authService.isAuthenticated() &&
      url !== '/' &&
      !this.publicPrefixes.some((p) => url.startsWith(p))
    );
  });

  constructor() {
    // Set initial URL
    this.currentUrl.set(this.router.url);

    // Track route changes
    this.router.events.pipe(
      filter(event => event instanceof NavigationEnd)
    ).subscribe((event: any) => {
      this.currentUrl.set(event.urlAfterRedirects || event.url);
    });
  }
}
