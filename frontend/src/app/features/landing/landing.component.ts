import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatCardModule } from '@angular/material/card';

import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-landing',
  imports: [RouterLink, MatToolbarModule, MatButtonModule, MatIconModule, MatCardModule],
  templateUrl: './landing.component.html',
  styleUrl: './landing.component.scss',
})
export class LandingComponent {
  readonly authService = inject(AuthService);
  readonly currentYear = new Date().getFullYear();

  readonly stats = [
    { value: '12 000+', label: 'Biens disponibles' },
    { value: '8 500+', label: 'Clients satisfaits' },
    { value: '98%', label: 'Taux de satisfaction' },
  ];

  readonly features = [
    {
      icon: 'search',
      title: 'Recherche avancée',
      description:
        'Filtres précis par localisation, surface, prix et type de bien pour trouver exactement ce que vous cherchez.',
    },
    {
      icon: 'notifications',
      title: 'Alertes personnalisées',
      description:
        "Recevez des notifications dès qu'un bien correspondant à vos critères est publié sur la plateforme.",
    },
    {
      icon: 'bar_chart',
      title: 'Estimation gratuite',
      description:
        "Obtenez une estimation précise de la valeur de votre bien grâce à notre outil d'analyse du marché.",
    },
  ];
}
