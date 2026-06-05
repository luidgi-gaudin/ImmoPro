import { Component, input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'immopro-button',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './immopro-button.component.html',
  styleUrls: ['./immopro-button.component.scss']
})
export class ImmoproButtonComponent {
  variant = input<'solid' | 'outline' | 'ghost'>('solid');
  color = input<'primary' | 'secondary' | 'error'>('primary');
  disabled = input<boolean>(false);
  loading = input<boolean>(false);
  type = input<'button' | 'submit'>('button');

  @Output() onClick = new EventEmitter<MouseEvent>();

  handleOnClick(event: MouseEvent) {
    if (!this.disabled() && !this.loading()) {
      this.onClick.emit(event);
    }
  }
}
