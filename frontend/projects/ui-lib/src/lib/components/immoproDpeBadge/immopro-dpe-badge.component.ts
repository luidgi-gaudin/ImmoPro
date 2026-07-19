import { Component, input } from '@angular/core';

@Component({
  selector: 'immopro-dpe-badge',
  standalone: true,
  imports: [],
  templateUrl: './immopro-dpe-badge.component.html',
  styleUrls: ['./immopro-dpe-badge.component.scss']
})
export class ImmoproDpeBadgeComponent {
  value = input<string>('');
}
