import { Component, input } from '@angular/core';

@Component({
  selector: 'immopro-select',
  standalone: true,
  imports: [],
  templateUrl: './immopro-select.component.html',
  styleUrls: ['./immopro-select.component.scss']
})
export class ImmoproSelectComponent {
  inputId = input<string>('');
  label = input<string>('');
  error = input<string | null>(null);
}
