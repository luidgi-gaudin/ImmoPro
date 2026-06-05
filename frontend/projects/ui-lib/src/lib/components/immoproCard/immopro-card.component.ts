import { Component, input } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'immopro-card',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './immopro-card.component.html',
  styleUrls: ['./immopro-card.component.scss']
})
export class ImmoproCardComponent {
  hoverable = input<boolean>(false);
  glow = input<boolean>(false);

  get hasHeader() { return true; } // simplified
  get hasFooter() { return true; } // simplified
}
