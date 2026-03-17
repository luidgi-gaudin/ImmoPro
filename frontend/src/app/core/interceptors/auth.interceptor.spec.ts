import { TestBed } from '@angular/core/testing';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { HttpClient } from '@angular/common/http';

import { credentialsInterceptor } from './auth.interceptor';

describe('credentialsInterceptor', () => {
  let http: HttpClient;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([credentialsInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    http = TestBed.inject(HttpClient);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('attaches withCredentials: true to GET requests', () => {
    http.get('/api/test').subscribe();
    const req = httpMock.expectOne('/api/test');
    expect(req.request.withCredentials).toBe(true);
    req.flush({});
  });

  it('attaches withCredentials: true to POST requests', () => {
    http.post('/api/auth/login', {}).subscribe();
    const req = httpMock.expectOne('/api/auth/login');
    expect(req.request.withCredentials).toBe(true);
    req.flush({});
  });

  it('attaches withCredentials: true to PUT requests', () => {
    http.put('/api/resource/1', {}).subscribe();
    const req = httpMock.expectOne('/api/resource/1');
    expect(req.request.withCredentials).toBe(true);
    req.flush({});
  });

  it('attaches withCredentials: true to DELETE requests', () => {
    http.delete('/api/resource/1').subscribe();
    const req = httpMock.expectOne('/api/resource/1');
    expect(req.request.withCredentials).toBe(true);
    req.flush({});
  });
});
