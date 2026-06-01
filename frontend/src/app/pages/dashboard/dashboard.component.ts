import { Component } from '@angular/core';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  template: `
    <main class="dashboard-shell">
      <section class="dashboard-card">
        <p class="eyebrow">ImmoPro</p>
        <h1>Dashboard</h1>
        <p>Cette page sera complétée plus tard.</p>
      </section>
    </main>
  `,
  styles: [
    `
      :host {
        display: block;
        min-height: 100vh;
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        color: #e2e8f0;
      }

      .dashboard-shell {
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 24px;
      }

      .dashboard-card {
        width: min(560px, 100%);
        padding: 32px;
        border-radius: 24px;
        background: rgba(15, 23, 42, 0.72);
        border: 1px solid rgba(148, 163, 184, 0.22);
        box-shadow: 0 20px 60px rgba(15, 23, 42, 0.35);
        backdrop-filter: blur(14px);
      }

      .eyebrow {
        margin: 0 0 12px;
        text-transform: uppercase;
        letter-spacing: 0.18em;
        font-size: 0.75rem;
        color: #93c5fd;
      }

      h1 {
        margin: 0 0 12px;
        font-size: clamp(2rem, 4vw, 3rem);
      }

      p {
        margin: 0;
        color: #cbd5e1;
        line-height: 1.6;
      }
    `,
  ],
})
export class DashboardComponent {}