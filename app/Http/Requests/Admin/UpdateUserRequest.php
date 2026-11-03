<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesStaffAssignment;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    use ValidatesStaffAssignment;

    /**
     * The route carries `can:users.update` and the controller asks the policy
     * whether this account is the actor's to edit.
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
                Rule::unique('users', 'email')->ignoreModel($this->targetUser()),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
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

    private function targetUser(): User
    {
        /** @var User $user */
        $user = $this->route('user');

        return $user;
    }
}
