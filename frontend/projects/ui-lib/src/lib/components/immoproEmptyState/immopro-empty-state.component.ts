import { Component, input } from '@angular/core';

@Component({
  selector: 'immopro-empty-state',
  standalone: true,
  imports: [],
  templateUrl: './immopro-empty-state.component.html',
  styleUrls: ['./immopro-empty-state.component.scss']
})
export class ImmoproEmptyStateComponent {
  title = input<string | null>(null);
  message = input<string>('');
  variant = input<'plain' | 'dashed'>('plain');
}
