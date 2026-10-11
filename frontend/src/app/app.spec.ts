import { provideHttpClient } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { App } from './app';

describe('App', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [provideRouter([]), provideHttpClient()],
    }).compileComponents();
  });

  it('renders the navigation', async () => {
    const fixture = TestBed.createComponent(App);
    await fixture.whenStable();

    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('Campaign Manager');
    expect(text).toContain('New campaign');
  });
});
