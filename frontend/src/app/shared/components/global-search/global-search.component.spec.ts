import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { GlobalSearchComponent } from './global-search.component';
import { GlobalSearchService } from '../../../core/services/global-search.service';

describe('GlobalSearchComponent', () => {
  let component: GlobalSearchComponent;
  let searchService: GlobalSearchService;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [GlobalSearchComponent],
      providers: [provideHttpClient(), provideRouter([])],
    }).compileComponents();

    const fixture = TestBed.createComponent(GlobalSearchComponent);
    component = fixture.componentInstance;
    searchService = TestBed.inject(GlobalSearchService);
  });

  it('should create the search component', () => {
    expect(component).toBeTruthy();
  });

  it('should respond to open and close commands', () => {
    expect(searchService.isOpen()).toBe(false);
    searchService.open();
    expect(searchService.isOpen()).toBe(true);
    searchService.close();
    expect(searchService.isOpen()).toBe(false);
  });
});
