<?php

namespace Splicewire\Beam\Accounts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SecurityPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
