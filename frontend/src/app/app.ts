import { Component, inject, signal } from '@angular/core';
import { Router, RouterOutlet } from '@angular/router';
import { NavbarComponent } from './components/navbar/navbar.component';
import { AuthService } from './services/auth.service';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, NavbarComponent],
  templateUrl: './app.html',
  styleUrl: './app.scss'
})
export class App {
  private authService = inject(AuthService);
  private router = inject(Router);

  protected readonly title = signal('frontend');

  protected shouldShowNavbar(): boolean {
    const url = this.router.url;
    return this.authService.isAuthenticated() && !url.startsWith('/login') && !url.startsWith('/register');
  }
}
