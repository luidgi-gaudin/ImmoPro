import { Component, input } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'immopro-stat-card',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './immopro-stat-card.component.html',
  styleUrls: ['./immopro-stat-card.component.scss']
})
export class ImmoproStatCardComponent {
  label = input.required<string>();
  value = input.required<string | number>();
  trend = input<string>();
  trendType = input<'success' | 'info'>('info');
}
