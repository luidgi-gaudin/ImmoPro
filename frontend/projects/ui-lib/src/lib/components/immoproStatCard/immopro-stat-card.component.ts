import { Component, input } from '@angular/core';

@Component({
  selector: 'immopro-stat-card',
  standalone: true,
  imports: [],
  templateUrl: './immopro-stat-card.component.html',
  styleUrls: ['./immopro-stat-card.component.scss']
})
export class ImmoproStatCardComponent {
  label = input.required<string>();
  value = input.required<string | number>();
  trend = input<string>();
  trendType = input<'success' | 'info'>('info');
}
