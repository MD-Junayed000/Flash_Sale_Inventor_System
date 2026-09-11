<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'sku'      => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function email(): string
    {
        $email = (string) $this->header('X-User-Email', '');
        if ($email === '') {
            $email = (string) $this->input('email', 'anonymous@example.com');
        }

        return strtolower(trim($email));
    }
}
