<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving an order along its state machine (P1-E7-T8).
 *
 * Only the shape is checked here: that `status` names a real status. Whether
 * *this* order may reach it is `OrderStatus::allowedTransitions()`' answer,
 * given by `TransitionOrderStatus` while it holds the order's row lock — a
 * rule here would be checking against a status that could change between this
 * line and that one.
 */
class UpdateOrderStatusRequest extends FormRequest
{
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
            'status' => ['required', 'string', Rule::enum(OrderStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function status(): OrderStatus
    {
        return OrderStatus::from((string) $this->validated('status'));
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return $note === null || trim((string) $note) === '' ? null : trim((string) $note);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['status' => 'status', 'note' => 'catatan'];
    }
}
