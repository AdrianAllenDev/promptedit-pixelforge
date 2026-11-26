<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerationInput extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['size' => '1024x1024', 'quality' => 'medium', 'reference_images' => []]);
    }

    public function rules(): array
    {
        $rules = [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => ['present', 'array', 'list', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https'],
        ];
        if ($this->routeIs('generations.store')) {
            $rules['submission_key'] = ['required', 'uuid'];
            $rules['quote_token'] = ['required', 'string'];
        }

        return $rules;
    }

    public function payload(): array
    {
        return $this->safe()->only(['prompt', 'size', 'quality', 'reference_images']);
    }
}
