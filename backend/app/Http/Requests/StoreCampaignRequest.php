<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreCampaignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation by trimming surrounding whitespace.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->name)) {
            $merge['name'] = trim($this->name);
        }

        if (is_string($this->subject)) {
            $merge['subject'] = trim($this->subject);
        }

        if (is_array($this->recipient_emails)) {
            $merge['recipient_emails'] = array_map(function ($item) {
                return is_string($item) ? trim($item) : $item;
            }, $this->recipient_emails);
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'recipient_emails' => ['required', 'array', 'min:1', 'max:500'],
            'recipient_emails.*' => ['required', 'string', 'email:filter', 'distinct:ignore_case'],
        ];
    }

    /**
     * Custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient_emails.*.distinct' => 'Duplicate recipient email addresses are not allowed.',
            'recipient_emails.*.email' => 'The recipient email must be a valid email address.',
        ];
    }

    /**
     * Handle a failed validation attempt according to assessment specifications.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => 'Invalid input',
            'details' => $validator->errors()->messages(),
        ], 422));
    }
}
