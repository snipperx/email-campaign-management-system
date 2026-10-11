import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { vi } from 'vitest';

import { CampaignApiService } from '../../../core/services/campaign-api.service';
import { CampaignCreate } from './campaign-create';

describe('CampaignCreate', () => {
  let fixture: ComponentFixture<CampaignCreate>;
  let el: HTMLElement;
  const createCampaign = vi.fn();

  function setValue(selector: string, value: string): void {
    const input = el.querySelector<HTMLInputElement | HTMLTextAreaElement>(selector)!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  function fillValidForm(recipients = 'a@test.com\nb@test.com'): void {
    setValue('#name', 'Spring Sale');
    setValue('#subject', '50% off');
    setValue('#body', 'Great deals');
    setValue('#recipients', recipients);
  }

  async function submit(): Promise<void> {
    el.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    createCampaign.mockReset();
    await TestBed.configureTestingModule({
      imports: [CampaignCreate],
      providers: [provideRouter([]), { provide: CampaignApiService, useValue: { createCampaign } }],
    }).compileComponents();

    fixture = TestBed.createComponent(CampaignCreate);
    el = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
  });

  it('does not call the API and shows errors when the form is empty', async () => {
    await submit();

    expect(createCampaign).not.toHaveBeenCalled();
    expect(el.querySelector('[data-testid="name-error"]')?.textContent).toContain('required');
    expect(el.querySelector('[data-testid="recipients-error"]')?.textContent).toContain('at least one');
  });

  it('shows an error for an invalid email', async () => {
    fillValidForm('good@test.com\nnot-an-email');
    await submit();

    expect(createCampaign).not.toHaveBeenCalled();
    expect(el.querySelector('[data-testid="recipients-error"]')?.textContent).toContain('not-an-email');
  });

  it('shows an error for duplicate emails', async () => {
    fillValidForm('a@test.com\nA@test.com');
    await submit();

    expect(createCampaign).not.toHaveBeenCalled();
    expect(el.querySelector('[data-testid="recipients-error"]')?.textContent).toContain('Duplicate');
  });

  it('submits a normalised payload and shows the campaign id and count', async () => {
    createCampaign.mockReturnValue(of({ campaign_id: 12, recipient_count: 2, status: 'queued' }));
    fillValidForm('  A@Test.com \n\nb@test.com');
    await submit();

    expect(createCampaign).toHaveBeenCalledWith({
      name: 'Spring Sale',
      subject: '50% off',
      body: 'Great deals',
      recipient_emails: ['a@test.com', 'b@test.com'],
    });
    const success = el.querySelector('[data-testid="success"]')?.textContent ?? '';
    expect(success).toContain('#12');
    expect(success).toContain('2 recipients');
  });

  it('maps API validation errors onto the fields', async () => {
    createCampaign.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 422,
            error: { error: 'Invalid input', details: { subject: 'Subject is required' } },
          }),
      ),
    );
    fillValidForm();
    await submit();

    expect(el.querySelector('[role="alert"]')?.textContent).toContain('Invalid input');
    expect(el.querySelector('[data-testid="subject-error"]')?.textContent).toContain('Subject is required');
  });
});
