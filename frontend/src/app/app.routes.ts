import { Routes } from '@angular/router';
import { authGuard } from './core/guards/auth.guard';
import { roleGuard } from './core/guards/role.guard';

/**
 * Chaque route déclare son `title` (lu par la `TitleStrategy`) et, dans ses
 * `data`, sa meta description, son libellé de fil d'Ariane et son indexation.
 */
export const routes: Routes = [
  {
    path: '',
    loadComponent: () =>
      import('./features/welcome/welcome.component').then((m) => m.WelcomeComponent),
    pathMatch: 'full',
    title: 'ImmoPro — Logiciel de gestion locative pour professionnels',
    data: {
      description:
        'Centralisez vos biens, vos baux réglementaires et vos quittances de loyer. ' +
        'ImmoPro est la plateforme de gestion locative des professionnels de l’immobilier.',
    },
  },
  {
    path: '',
    canActivateChild: [authGuard],
    children: [
      {
        path: 'dashboard',
        loadComponent: () =>
          import('./features/dashboard/dashboard.component').then((m) => m.DashboardComponent),
        title: 'Tableau de bord',
        data: {
          description:
            'Vue consolidée de votre parc : échéances de loyers, taux d’occupation et alertes en cours.',
          breadcrumb: 'Tableau de bord',
          noindex: true,
        },
      },
      {
        path: 'portfolios',
        loadComponent: () =>
          import('./features/portfolios/portfolios.component').then((m) => m.PortfoliosComponent),
        title: 'Portefeuilles',
        data: {
          description:
            'Vos portefeuilles de biens immobiliers, regroupés par entité ou par mandant.',
          breadcrumb: 'Portefeuilles',
          noindex: true,
        },
      },
      {
        path: 'portfolios/:id',
        loadComponent: () =>
          import('./features/portfolios/portfolio-shell.component').then(
            (m) => m.PortfolioShellComponent,
          ),
        data: {
          breadcrumb: 'Portefeuille',
          breadcrumbParents: [{ label: 'Portefeuilles', url: '/portfolios' }],
          noindex: true,
        },
        children: [
          { path: '', pathMatch: 'full', redirectTo: 'overview' },
          {
            path: 'overview',
            loadComponent: () =>
              import('./features/portfolios/portfolio-overview.component').then(
                (m) => m.PortfolioOverviewComponent,
              ),
            title: 'Vue d’ensemble du portefeuille',
            data: {
              description:
                'Statistiques d’occupation, loyer cumulé et actifs du portefeuille sélectionné.',
              breadcrumb: 'Vue d’ensemble',
              noindex: true,
            },
          },
          {
            path: 'properties',
            loadComponent: () =>
              import('./features/portfolios/portfolio-properties.component').then(
                (m) => m.PortfolioPropertiesComponent,
              ),
            title: 'Actifs du portefeuille',
            data: {
              description:
                'Inventaire des biens du portefeuille : surface, pièces, DPE et statut d’occupation.',
              breadcrumb: 'Actifs',
              noindex: true,
            },
          },
          {
            path: 'properties/:propertyId',
            loadComponent: () =>
              import('./features/portfolios/portfolio-property-detail.component').then(
                (m) => m.PortfolioPropertyDetailComponent,
              ),
            title: 'Détail de l’actif',
            data: {
              description: 'Fiche détaillée d’un bien : caractéristiques, bail en cours et loyer.',
              breadcrumb: 'Détail de l’actif',
              breadcrumbParents: [{ label: 'Actifs', url: '/portfolios/:id/properties' }],
              noindex: true,
            },
          },
        ],
      },
      {
        path: 'tenants',
        loadComponent: () =>
          import('./features/tenants/tenants.component').then((m) => m.TenantsComponent),
        title: 'Locataires',
        data: {
          description: 'Annuaire de vos locataires, avec leurs baux et leurs coordonnées.',
          breadcrumb: 'Locataires',
          noindex: true,
        },
      },
      {
        path: 'tenants/:id',
        loadComponent: () =>
          import('./features/tenants/tenant-detail.component').then((m) => m.TenantDetailComponent),
        title: 'Fiche locataire',
        data: {
          description: 'Coordonnées, baux en cours et historique de paiement d’un locataire.',
          breadcrumb: 'Fiche locataire',
          breadcrumbParents: [{ label: 'Locataires', url: '/tenants' }],
          noindex: true,
        },
      },
      {
        path: 'leases',
        loadComponent: () =>
          import('./features/leases/leases.component').then((m) => m.LeasesComponent),
        title: 'Baux',
        data: {
          description:
            'Vos baux d’habitation : location nue, meublée, bail étudiant et bail mobilité.',
          breadcrumb: 'Baux',
          noindex: true,
        },
      },
      {
        path: 'alerts',
        loadComponent: () =>
          import('./features/alerts/alerts.component').then((m) => m.AlertsComponent),
        title: 'Alertes',
        data: {
          description: 'Retards de paiement, baux arrivant à échéance et points à traiter.',
          breadcrumb: 'Alertes',
          noindex: true,
        },
      },
      {
        path: 'documents',
        loadComponent: () =>
          import('./features/documents/documents.component').then((m) => m.DocumentsComponent),
        title: 'Documents',
        data: {
          description:
            'Baux signés, états des lieux, diagnostics et attestations, classés et consultables au même endroit.',
          breadcrumb: 'Documents',
          noindex: true,
        },
      },
      {
        path: 'reports',
        loadComponent: () =>
          import('./features/reports/reports.component').then((m) => m.ReportsComponent),
        title: 'Rapports',
        data: {
          description: 'Quittances de loyer et états de votre parc, prêts à être imprimés.',
          breadcrumb: 'Rapports',
          noindex: true,
        },
      },
      /*
       * Espace locataire.
       *
       * Sous le même garde d'authentification que le reste, plus un garde de
       * profil : un bailleur qui atterrirait ici ne verrait rien, et
       * l'expliquer coûte moins qu'un écran vide inexpliqué.
       */
      {
        path: 'espace-locataire',
        canActivateChild: [roleGuard('locataire')],
        data: { breadcrumb: 'Mon espace', noindex: true },
        children: [
          {
            path: '',
            pathMatch: 'full',
            loadComponent: () =>
              import('./features/tenant-space/tenant-space.component').then(
                (m) => m.TenantSpaceComponent,
              ),
            title: 'Mon espace locataire',
            data: {
              description:
                'Votre logement, votre bail et vos échéances de loyer, réunis au même endroit.',
              breadcrumb: 'Mon espace',
              noindex: true,
            },
          },
          {
            path: 'baux/:id',
            loadComponent: () =>
              import('./features/tenant-space/tenant-space-lease.component').then(
                (m) => m.TenantSpaceLeaseComponent,
              ),
            title: 'Mon bail',
            data: {
              description: 'Détail de votre bail et historique de vos paiements.',
              breadcrumb: 'Mon bail',
              breadcrumbParents: [{ label: 'Mon espace', url: '/espace-locataire' }],
              noindex: true,
            },
          },
          {
            path: 'documents',
            loadComponent: () =>
              import('./features/tenant-space/tenant-space-documents.component').then(
                (m) => m.TenantSpaceDocumentsComponent,
              ),
            title: 'Mes documents',
            data: {
              description:
                'Consultez les pièces de votre dossier et transmettez vos justificatifs.',
              breadcrumb: 'Mes documents',
              breadcrumbParents: [{ label: 'Mon espace', url: '/espace-locataire' }],
              noindex: true,
            },
          },
        ],
      },
      {
        path: 'profile',
        loadComponent: () =>
          import('./features/profile/profile.component').then((m) => m.ProfileComponent),
        title: 'Mon profil',
        data: {
          description: 'Vos informations de compte, double authentification et préférences.',
          breadcrumb: 'Mon profil',
          noindex: true,
        },
      },
    ],
  },
  {
    path: 'faq',
    loadComponent: () => import('./features/faq/faq.component').then((m) => m.FaqComponent),
    title: 'Questions fréquentes sur la gestion locative',
    data: {
      description:
        'Types de baux couverts, validité des quittances, suivi des impayés, sécurité et RGPD : ' +
        'les réponses aux questions les plus posées sur ImmoPro.',
      breadcrumb: 'Questions fréquentes',
    },
  },
  // Pages légales : accessibles sans compte, comme l'exige leur raison d'être.
  {
    path: 'confidentialite',
    loadComponent: () =>
      import('./features/legal/privacy.component').then((m) => m.PrivacyComponent),
    title: 'Politique de confidentialité',
    data: {
      description:
        'Quelles données ImmoPro traite, pour quelles finalités, combien de temps, et comment ' +
        'exercer vos droits d’accès, de rectification et d’effacement.',
      breadcrumb: 'Confidentialité',
    },
  },
  {
    path: 'cookies',
    loadComponent: () =>
      import('./features/legal/cookies.component').then((m) => m.CookiesComponent),
    title: 'Politique de cookies',
    data: {
      description:
        'Les traceurs déposés par ImmoPro, leur finalité, leur durée, et comment revenir à tout ' +
        'moment sur votre consentement.',
      breadcrumb: 'Cookies',
    },
  },
  {
    path: 'login',
    loadComponent: () =>
      import('./features/auth/login/login.component').then((m) => m.LoginComponent),
    title: 'Connexion',
    data: {
      description: 'Accédez à votre espace ImmoPro.',
      // Un écran de connexion n'apporte rien dans une page de résultats.
      noindex: true,
    },
  },
  {
    path: 'register',
    loadComponent: () =>
      import('./features/auth/register/register.component').then((m) => m.RegisterComponent),
    title: 'Créer un compte',
    data: {
      description:
        'Ouvrez votre compte ImmoPro et commencez à gérer vos biens, vos baux et vos quittances.',
      breadcrumb: 'Créer un compte',
    },
  },
  {
    path: 'forgot-password',
    loadComponent: () =>
      import('./features/auth/login/forgot-password.component').then(
        (m) => m.ForgotPasswordComponent,
      ),
    title: 'Mot de passe oublié',
    data: {
      description: 'Recevez un lien de réinitialisation de votre mot de passe ImmoPro.',
      noindex: true,
    },
  },
  {
    path: 'reset-password',
    loadComponent: () =>
      import('./features/auth/login/reset-password.component').then(
        (m) => m.ResetPasswordComponent,
      ),
    title: 'Réinitialiser le mot de passe',
    data: {
      description: 'Choisissez un nouveau mot de passe pour votre compte ImmoPro.',
      noindex: true,
    },
  },
  // Dernier recours : toute URL inconnue tombe ici.
  {
    path: '**',
    loadComponent: () =>
      import('./features/not-found/not-found.component').then((m) => m.NotFoundComponent),
    title: 'Page introuvable',
    data: {
      description: 'Cette page n’existe pas ou a été déplacée.',
      noindex: true,
    },
  },
];
