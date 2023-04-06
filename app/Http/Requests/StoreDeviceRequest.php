<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class StoreDeviceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'device_mac_address' => [
                'required',
                'mac_address',
                'DeviceMacAddressNotExists'
            ],
            'device_profile_uuid' => [
                'required',
                Rule::exists('App\Models\DeviceProfile', 'device_profile_uuid')
                    ->where('domain_uuid', Session::get('domain_uuid'))
            ],
            'device_template' => [
                'required',
                'string',
            ],
            'extension_uuid' => [
                'required',
                Rule::exists('App\Models\Extensions', 'extension_uuid')
                    ->where('domain_uuid', Session::get('domain_uuid'))
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'device_mac_address_not_exists' => 'This mac address is already used'
        ];
    }
}
