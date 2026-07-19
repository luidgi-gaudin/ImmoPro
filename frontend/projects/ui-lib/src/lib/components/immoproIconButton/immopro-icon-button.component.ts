import { Component, input, output } from '@angular/core';

@Component({
  selector: 'immopro-icon-button',
  standalone: true,
  imports: [],
  templateUrl: './immopro-icon-button.component.html',
  styleUrls: ['./immopro-icon-button.component.scss']
})
export class ImmoproIconButtonComponent {
  tone = input<'default' | 'danger'>('default');
  size = input<'sm' | 'md'>('md');
  active = input<boolean>(false);
  disabled = input<boolean>(false);
  label = input<string>('');
  type = input<'button' | 'submit'>('button');

  onClick = output<MouseEvent>();

  handleClick(event: MouseEvent): void {
    if (!this.disabled()) {
      this.onClick.emit(event);
    }
  }
}
