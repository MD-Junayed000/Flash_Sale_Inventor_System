<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filter + pagination parameters for the product catalogue endpoint.
 *
 * Kept as a FormRequest (not a plain array) so we get validation, typed
 * accessors and consistent error envelopes for free.  Defaults match what
 * ProductController would otherwise hard-code.
 */
final class ListProductsRequest extends FormRequest
{
    /** Always allow read-only list endpoints; no auth check needed. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>|string> */
    public function rules(): array
    {
        return [
            'page'     => ['nullable', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status'   => ['nullable', 'string', 'in:active,inactive'],
            'search'   => ['nullable', 'string', 'max:120'],
        ];
    }

    /** Page number, clamped to [1, 1000]. Default 1. */
    public function page(): int
    {
        return (int) ($this->validated()['page'] ?? 1);
    }

    /** Items per page, clamped to [1, 100]. Default 15. */
    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? 15);
    }

    /** Optional status filter; null when not provided. */
    public function statusFilter(): ?string
    {
        return $this->validated()['status'] ?? null;
    }

    /** Optional text search; null when empty. */
    public function searchTerm(): ?string
    {
        $term = trim((string) ($this->validated()['search'] ?? ''));
        return $term === '' ? null : $term;
    }
}
