import { Component, input } from '@angular/core';

@Component({
  selector: 'immopro-page-header',
  standalone: true,
  imports: [],
  templateUrl: './immopro-page-header.component.html',
  styleUrls: ['./immopro-page-header.component.scss']
})
export class ImmoproPageHeaderComponent {
  title = input<string>('');
  subtitle = input<string | null>(null);
}
