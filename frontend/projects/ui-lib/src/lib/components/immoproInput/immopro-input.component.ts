import { Component, input, forwardRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NG_VALUE_ACCESSOR, ControlValueAccessor, ReactiveFormsModule } from '@angular/forms';

@Component({
  selector: 'immopro-input',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div class="input-wrapper">
      <label *ngIf="label()" [for]="id()">{{ label() }}</label>
      <div class="input-container">
        <input
          [id]="id()"
          [type]="type()"
          [placeholder]="placeholder()"
          [value]="value"
          [disabled]="disabled"
          (input)="onInput($event)"
          (blur)="onBlur()"
          [class.error]="error()"
        />
      </div>
      <span class="error-message" *ngIf="error()">{{ error() }}</span>
    </div>
  `,
  styles: [`
    .input-wrapper {
      display: flex;
      flex-direction: column;
      gap: 8px;
      width: 100%;
    }

    label {
      font-size: 0.75rem;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #a1a1aa;
    }

    input {
      width: 100%;
      background: #18181b;
      border: 1px solid rgba(39, 39, 42, 0.5);
      color: white;
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 0.875rem;
      transition: all 150ms ease;
      font-family: inherit;

      &:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
      }

      &::placeholder {
        color: #71717a;
      }

      &.error {
        border-color: #ef4444;
        &:focus {
          box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.15);
        }
      }

      &:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }
    }

    .error-message {
      font-size: 0.75rem;
      color: #ef4444;
    }
  `],
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => ImmoproInputComponent),
      multi: true
    }
  ]
})
export class ImmoproInputComponent implements ControlValueAccessor {
  id = input<string>('');
  label = input<string>('');
  type = input<string>('text');
  placeholder = input<string>('');
  error = input<string | null>(null);

  value: any = '';
  disabled = false;

  onChange: any = () => {};
  onTouched: any = () => {};

  writeValue(value: any): void {
    this.value = value;
  }

  registerOnChange(fn: any): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: any): void {
    this.onTouched = fn;
  }

  setDisabledState(isDisabled: boolean): void {
    this.disabled = isDisabled;
  }

  onInput(event: any): void {
    this.value = event.target.value;
    this.onChange(this.value);
  }

  onBlur(): void {
    this.onTouched();
  }
}
