import { Component, input, Output, EventEmitter } from '@angular/core';

@Component({
  selector: 'immopro-button',
  templateUrl: './immopro-button.component.html',
  styleUrls: ['./immopro-button.component.scss']
})
export class ImmoproButtonComponent {
  label = input<string>('');
  color = input<'primary' | 'accent' | 'warn'>('primary');
  disabled = input<boolean>(false);

  @Output() onClick = new EventEmitter<MouseEvent>();
}