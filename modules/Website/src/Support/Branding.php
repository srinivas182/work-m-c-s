<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Branding inputs (FR-WB-03). Only what the owner chose is stored; the accessible
 * palette is computed from these colours by the Sprint 0 engine wherever the site
 * renders (editor and public engine), so there is one implementation of it.
 */
final class Branding
{
    /** @return array{primary: string, secondary: string|null, font_pairing: string, corners: string, logo: string|null, favicon: string|null} */
    public function defaults(): array
    {
        return ['primary' => '#6D4AFF', 'secondary' => null, 'font_pairing' => 'modern', 'corners' => 'soft', 'logo' => null, 'favicon' => null];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{primary: string, secondary: string|null, font_pairing: string, corners: string}
     *
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        $hex = 'regex:/^#[0-9A-Fa-f]{6}$/';
        /** @var array{primary: string, secondary?: string|null, font_pairing: string, corners: string} $data */
        $data = Validator::make($input, [
            'primary' => ['required', $hex],
            'secondary' => ['nullable', $hex],
            'font_pairing' => ['required', Rule::in(array_keys((array) config('website.font_pairings')))],
            'corners' => ['required', Rule::in((array) config('website.corner_styles'))],
        ], ['primary.regex' => 'Choose a colour like #6D4AFF.', 'secondary.regex' => 'Choose a colour like #6D4AFF.'])->validate();

        return [
            'primary' => strtoupper($data['primary']),
            'secondary' => isset($data['secondary']) ? strtoupper((string) $data['secondary']) : null,
            'font_pairing' => $data['font_pairing'],
            'corners' => $data['corners'],
        ];
    }
}
