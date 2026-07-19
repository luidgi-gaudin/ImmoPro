import { Component, ViewEncapsulation, input } from '@angular/core';

@Component({
  selector: 'immopro-table',
  standalone: true,
  imports: [],
  encapsulation: ViewEncapsulation.None,
  templateUrl: './immopro-table.component.html',
  styleUrls: ['./immopro-table.component.scss']
})
export class ImmoproTableComponent {
  loading = input<boolean>(false);
  loadingText = input<string>('Chargement...');
}
