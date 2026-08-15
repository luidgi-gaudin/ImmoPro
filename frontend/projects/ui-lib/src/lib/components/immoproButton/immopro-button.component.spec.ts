import { TestBed } from '@angular/core/testing';
import { ImmoproButtonComponent } from './immopro-button.component';

describe('ImmoproButtonComponent', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ImmoproButtonComponent],
    }).compileComponents();
  });

  it('should create the button component', () => {
    const fixture = TestBed.createComponent(ImmoproButtonComponent);
    const component = fixture.componentInstance;
    expect(component).toBeTruthy();
  });
});
