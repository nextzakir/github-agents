<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OpenAiChatCompletionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'model' => ['nullable', 'string'],
            'provider' => ['nullable', 'string'],
            'conversation_id' => ['nullable', 'string'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:system,user,assistant,tool'],
            'messages.*.content' => ['required', 'string'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'messages.required' => 'The messages field is required.',
            'messages.array' => 'The messages field must be an array.',
            'messages.min' => 'At least one message is required.',
            'messages.*.role.in' => 'Each message role must be one of: system, user, assistant, tool.',
            'messages.*.content.string' => 'Each message content must be plain text.',
        ];
    }
}
