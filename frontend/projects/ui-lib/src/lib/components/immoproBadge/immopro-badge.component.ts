import { Component, input } from '@angular/core';

@Component({
  selector: 'immopro-badge',
  standalone: true,
  imports: [],
  templateUrl: './immopro-badge.component.html',
  styleUrls: ['./immopro-badge.component.scss']
})
export class ImmoproBadgeComponent {
  tone = input<'success' | 'warning' | 'danger' | 'info' | 'neutral'>('neutral');
}
