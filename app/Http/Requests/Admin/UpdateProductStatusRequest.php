<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The quick actions on the product listing.
 *
 * Exactly one of them is sent at a time, so a row can be switched off, marked
 * featured or published without reopening the whole form. Publishing is here
 * and not only on the form because a catalogue is normally written first and
 * released afterwards, often in a batch — hunting through each product's form
 * for a date field is not that workflow.
 */
class UpdateProductStatusRequest extends FormRequest
{
    /** Publication actions a row may trigger. */
    public const PUBLICATIONS = ['published', 'draft'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required_without_all:is_featured,publication', 'boolean'],
            'is_featured' => ['required_without_all:is_active,publication', 'boolean'],
            'publication' => [
                'required_without_all:is_active,is_featured',
                Rule::in(self::PUBLICATIONS),
            ],
        ];
    }

    /**
     * Only what was actually sent, so a missing flag is left as it is instead
     * of being read as false.
     *
     * Publishing also switches the product on: "Terbitkan" means "let buyers
     * see this", and a published-but-inactive product is still invisible —
     * setting the date alone would look like the action did nothing.
     *
     * The publish moment is the **server's** clock, so a browser in another
     * timezone cannot publish a product into the future and leave it silently
     * scheduled.
     *
     * @return array<string, mixed>
     */
    public function flags(): array
    {
        $flags = [];

        foreach (['is_active', 'is_featured'] as $flag) {
            if ($this->has($flag)) {
                $flags[$flag] = $this->boolean($flag);
            }
        }

        if ($this->has('publication')) {
            $isPublishing = $this->input('publication') === 'published';

            $flags['published_at'] = $isPublishing ? now() : null;

            if ($isPublishing) {
                $flags['is_active'] = true;
            }
        }

        return $flags;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'is_active' => 'status aktif',
            'is_featured' => 'produk unggulan',
            'publication' => 'status terbit',
        ];
    }
}
