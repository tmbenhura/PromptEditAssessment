<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseCreditRequest extends FormRequest
{
    public const MAX_AMOUNT_USD = 1000;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Capped so a typo (or abuse) can't overflow the decimal(18,9) balance column.
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT_USD],
        ];
    }
}
