<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class AdminReportRequest extends FormRequest
{
    /** Pixelforge's GET /v1/usage refuses ranges longer than this many days. */
    public const MAX_RANGE_DAYS = 92;

    private const DEFAULT_RANGE_DAYS = 14;

    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => array_filter([
                'nullable',
                'date_format:Y-m-d',
                $this->filled('start_date') ? 'after_or_equal:start_date' : null,
            ]),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (Carbon::parse($this->startDate())->diffInDays(Carbon::parse($this->endDate())) >= self::MAX_RANGE_DAYS) {
                    $validator->errors()->add('end_date', 'Choose a range of at most '.self::MAX_RANGE_DAYS.' days.');
                }
            },
        ];
    }

    public function endDate(): string
    {
        return (string) ($this->validated('end_date') ?? now('UTC')->format('Y-m-d'));
    }

    public function startDate(): string
    {
        return (string) ($this->validated('start_date')
            ?? Carbon::parse($this->endDate(), 'UTC')->subDays(self::DEFAULT_RANGE_DAYS - 1)->format('Y-m-d'));
    }
}
