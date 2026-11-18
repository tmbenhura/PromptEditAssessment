export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

export type ImageSize = '1024x1024' | '1536x1024' | '1024x1536';

export type ImageQuality = 'low' | 'medium' | 'high';

export interface TokenUsage {
    input_text_tokens?: number;
    input_image_tokens?: number;
    output_tokens?: number;
    reasoning_tokens?: number;
    total_tokens?: number;
}

export interface Generation {
    id: string;
    pixelforge_id: string | null;
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    reference_images: string[] | null;
    status: GenerationStatus;
    /** Set while the generation waits for Pixelforge's daily quota to reset (UTC midnight). */
    submit_after: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    usage: TokenUsage | null;
    estimated_user_cost: string;
    actual_user_cost: string;
    error_code: string | null;
    error_message: string | null;
    pixelforge_created_at: string | null;
    pixelforge_started_at: string | null;
    pixelforge_completed_at: string | null;
    created_at: string;
}

export interface PaginatedGenerations {
    data: Generation[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface SizeOption {
    value: ImageSize;
    label: string;
    aspectRatio: string;
}

export interface QualityOption {
    value: ImageQuality;
    label: string;
}

/**
 * What a generation is expected to cost the user, worked out by the server before it
 * is submitted. Amounts are USD decimal strings: exactly what the user will be charged,
 * with no provider cost, rate or markup.
 */
export interface CostEstimate {
    tokens: {
        text: number;
        image: number;
        output: number;
        reasoning_min: number;
        reasoning_max: number;
    };
    costs: {
        text: string;
        image: string;
        output: string;
        reasoning_min: string;
        reasoning_max: string;
        min: string;
        typical: string;
        max: string;
    };
}
