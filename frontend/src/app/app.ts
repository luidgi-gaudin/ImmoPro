import { Component, inject, signal, computed, ChangeDetectionStrategy } from '@angular/core';
import { Router, RouterOutlet, NavigationEnd } from '@angular/router';
import { filter } from 'rxjs/operators';
import { NavbarComponent } from './shared/components/navbar/navbar.component';
import { AuthService } from './core/services/auth.service';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, NavbarComponent],
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

  protected shouldShowNavbar = computed(() => {
    const url = this.currentUrl();
    return this.authService.isAuthenticated() && 
           !url.startsWith('/login') && 
           !url.startsWith('/register') &&
           !url.startsWith('/forgot-password') &&
           !url.startsWith('/reset-password');
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
