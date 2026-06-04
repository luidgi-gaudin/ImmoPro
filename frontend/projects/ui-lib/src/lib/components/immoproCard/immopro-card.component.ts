import { Component, input } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'immopro-card',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="card" [class.hoverable]="hoverable()">
      <div class="card-glow" *ngIf="glow()"></div>
      <div class="card-header" *ngIf="hasHeader">
        <ng-content select="[card-title]"></ng-content>
        <ng-content select="[card-subtitle]"></ng-content>
      </div>
      <div class="card-content">
        <ng-content></ng-content>
      </div>
      <div class="card-footer" *ngIf="hasFooter">
        <ng-content select="[card-footer]"></ng-content>
      </div>
    </div>
  `,
  styles: [`
    .card {
      background: #18181b;
      border: 1px solid rgba(39, 39, 42, 0.5);
      border-radius: 12px;
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      transition: all 150ms ease;
      
      &.hoverable:hover {
        border-color: rgba(63, 63, 70, 0.8);
        transform: translateY(-2px);
        box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.5);
      }
    }

    .card-glow {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 1px;
      background: linear-gradient(90deg, transparent, #6366f1, transparent);
      opacity: 0.3;
    }

    .card-header {
      padding: 24px 24px 16px;
    }

    .card-content {
      padding: 24px;
      flex: 1;
    }

    .card-footer {
      padding: 16px 24px;
      border-top: 1px solid rgba(39, 39, 42, 0.5);
      background: rgba(255, 255, 255, 0.01);
    }
  `]
})
export class ImmoproCardComponent {
  hoverable = input<boolean>(false);
  glow = input<boolean>(false);

  get hasHeader() { return true; } // simplified for now
  get hasFooter() { return true; } // simplified for now
}
