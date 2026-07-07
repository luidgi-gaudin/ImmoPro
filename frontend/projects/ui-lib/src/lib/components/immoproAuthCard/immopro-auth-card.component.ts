import { Component, input } from '@angular/core';

@Component({
  selector: 'immopro-auth-card',
  standalone: true,
  imports: [],
  templateUrl: './immopro-auth-card.component.html',
  styleUrls: ['./immopro-auth-card.component.scss']
})
export class ImmoproAuthCardComponent {
  title = input.required<string>();
  subtitle = input<string>();
}
