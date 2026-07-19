import { Component, inject } from '@angular/core';
import { ImmoproIconButtonComponent } from '../immoproIconButton/immopro-icon-button.component';
import { ThemeService } from '../../services/theme.service';

@Component({
  selector: 'immopro-theme-toggle',
  standalone: true,
  imports: [ImmoproIconButtonComponent],
  templateUrl: './immopro-theme-toggle.component.html',
})
export class ImmoproThemeToggleComponent {
  protected theme = inject(ThemeService);
}
