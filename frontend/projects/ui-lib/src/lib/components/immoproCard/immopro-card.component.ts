import { Component, input } from '@angular/core';

@Component({
  selector: 'immopro-card',
  standalone: true,
  imports: [],
  templateUrl: './immopro-card.component.html',
  styleUrls: ['./immopro-card.component.scss']
})
export class ImmoproCardComponent {
  hoverable = input<boolean>(false);
  glow = input<boolean>(false);

  get hasHeader() { return true; }
  get hasFooter() { return true; }
}
