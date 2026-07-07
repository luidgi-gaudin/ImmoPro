import { Component, input, output } from '@angular/core';

@Component({
  selector: 'immopro-button',
  standalone: true,
  imports: [],
  templateUrl: './immopro-button.component.html',
  styleUrls: ['./immopro-button.component.scss']
})
export class ImmoproButtonComponent {
  variant = input<'solid' | 'outline' | 'ghost'>('solid');
  color = input<'primary' | 'secondary' | 'error'>('primary');
  disabled = input<boolean>(false);
  loading = input<boolean>(false);
  type = input<'button' | 'submit'>('button');

  onClick = output<MouseEvent>();

  handleOnClick(event: MouseEvent) {
    if (!this.disabled() && !this.loading()) {
      this.onClick.emit(event);
    }
  }
}
