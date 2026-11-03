<?php

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ValidatesStaffAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    use PasswordValidationRules, ValidatesStaffAssignment;

    /**
     * The route carries `can:users.create` and the controller asks the policy;
     * what this request adds is the role and branch ceiling.
     */
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
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                // `unique` queries the table directly, so a soft-deleted
                // account still holds its address and a new one cannot reuse
                // it until that row is purged (ADR-010).
                Rule::unique('users', 'email'),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => $this->passwordRules(),
            ...$this->staffAssignmentRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'email' => 'email',
            'phone' => 'nomor telepon',
            'password' => 'kata sandi',
            ...$this->staffAssignmentAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->staffAssignmentMessages();
    }
}
