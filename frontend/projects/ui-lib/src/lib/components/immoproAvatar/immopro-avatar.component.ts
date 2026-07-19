import { Component, input } from '@angular/core';

@Component({
  selector: 'immopro-avatar',
  standalone: true,
  imports: [],
  templateUrl: './immopro-avatar.component.html',
  styleUrls: ['./immopro-avatar.component.scss']
})
export class ImmoproAvatarComponent {
  initials = input<string>('');
  size = input<'sm' | 'md' | 'lg' | 'xl'>('md');
  tone = input<'primary' | 'neutral' | 'gradient'>('primary');
}
