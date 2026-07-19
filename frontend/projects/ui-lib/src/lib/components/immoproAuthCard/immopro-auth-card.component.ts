import { Component, input } from '@angular/core';
import { ImmoproThemeToggleComponent } from '../immoproThemeToggle/immopro-theme-toggle.component';

@Component({
  selector: 'immopro-auth-card',
  standalone: true,
  imports: [ImmoproThemeToggleComponent],
  templateUrl: './immopro-auth-card.component.html',
  styleUrls: ['./immopro-auth-card.component.scss']
})
export class ImmoproAuthCardComponent {
  title = input.required<string>();
  subtitle = input<string>();
}
