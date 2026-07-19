import { Component, input, output } from '@angular/core';

@Component({
  selector: 'immopro-pagination',
  standalone: true,
  imports: [],
  templateUrl: './immopro-pagination.component.html',
  styleUrls: ['./immopro-pagination.component.scss']
})
export class ImmoproPaginationComponent {
  currentPage = input.required<number>();
  lastPage = input.required<number>();

  pageChange = output<number>();

  goTo(page: number): void {
    if (page >= 1 && page <= this.lastPage() && page !== this.currentPage()) {
      this.pageChange.emit(page);
    }
  }
}
