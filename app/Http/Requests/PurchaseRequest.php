<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest for POST /api/v1/purchase.
 *
 * Routes mounting this must be behind `auth:sanctum` — the user identity
 * comes from the bearer token, never from a header.
 */
final class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'sku'      => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }

    public function email(): string
    {
        $user = $this->user();
        if ($user && $user->email) {
            return strtolower(trim((string) $user->email));
        }
        return (string) $this->input('email', 'anonymous@example.com');
    }
}
