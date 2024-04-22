<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CreateMessageSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        // logger('validation');
        // logger(request()->all());
        return [
            'destination' => [
                'required',
            ],
            'carrier' => [
                'nullable',
            ],
            'chatplan_detail_data' => [
                'nullable',
            ],
            'email' => [
                'nullable',
                'email:rfc,dns'
            ],
            'description' => [
                'nullable',
                'string'
            ],
            'domain_uuid' => [
                'required',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            // 'device_profile_uuid.required' => 'Profile is required',
            // 'device_template.required' => 'Template is required'
        ];
    }

    protected function prepareForValidation()
    {
        $merge = [];

        if (!$this->has('domain_uuid')) {
            $merge['domain_uuid'] = session('domain_uuid');
        }

        $this->merge($merge);
    }
}
