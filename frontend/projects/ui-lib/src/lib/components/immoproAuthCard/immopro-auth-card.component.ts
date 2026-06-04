import { Component, input } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'immopro-auth-card',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './immopro-auth-card.component.html',
  styleUrls: ['./immopro-auth-card.component.scss']
})
export class ImmoproAuthCardComponent {
  title = input.required<string>();
  subtitle = input<string>();
}
