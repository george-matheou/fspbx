<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDeviceRequest;
use App\Http\Requests\UpdateBulkDeviceRequest;
use App\Http\Requests\UpdateDeviceRequest;
use App\Models\DeviceLines;
use App\Models\Devices;
use App\Models\Extensions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The DeviceController class is responsible for handling device-related operations, such as listing, creating, and storing devices.
 *
 * @package App\Http\Controllers
 */
class DeviceController extends Controller
{

    public $model;
    public $filters = [];
    public $sortField;
    public $sortOrder;
    protected $viewName = 'Devices';
    protected $searchable = ['destination', 'carrier', 'description', 'chatplan_detail_data', 'email'];

    public function __construct()
    {
        $this->model = new Devices();
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (!userCheckPermission("device_view")) {
            return redirect('/');
        }

        return Inertia::render(
            'Devices',
            [
                'data' => function () {
                    return $this->getData();
                },
                'deviceGlobalView' => (isset($this->filters['showGlobal']) && $this->filters['showGlobal']),
                'routeSendEventNotifyAll' => route('extensions.send-event-notify-all'),
                'routes' => [
                    'current_page' => route('devices.index'),
                    'store' => route('devices.store'),
                    'select_all' => route('messages.settings.select.all'),
                    'bulk_delete' => route('messages.settings.bulk.delete'),
                    'bulk_update' => route('devices.bulk.update'),
                ],
            ]
        );
    }

    /**
     *  Get device data
     */
    public function getData($paginate = 50)
    {

        // Check if search parameter is present and not empty
        if (!empty(request('filterData.search'))) {
            $this->filters['search'] = request('filterData.search');
        }

        // Check if search parameter is present and not empty
        if (!empty(request('filterData.showGlobal'))) {
            $this->filters['showGlobal'] = request('filterData.showGlobal') === 'true';
        } else {
            $this->filters['showGlobal'] = null;
        }

        // Add sorting criteria
        $this->sortField = request()->get('sortField', 'device_label'); // Default to 'destination'
        $this->sortOrder = request()->get('sortOrder', 'asc'); // Default to ascending

        $data = $this->builder($this->filters);

        // Apply pagination if requested
        if ($paginate) {
            $data = $data->paginate($paginate);
        } else {
            $data = $data->get(); // This will return a collection
        }

        if (isset($this->filters['showGlobal']) and $this->filters['showGlobal']) {
            // Access domains through the session and filter extensions by those domains
            $domainUuids = Session::get('domains')->pluck('domain_uuid');
            $extensions = Extensions::whereIn('domain_uuid', $domainUuids)
                ->get(['domain_uuid', 'extension', 'effective_caller_id_name']);
        } else {
            // get extensions for session domain
            $extensions = Extensions::where('domain_uuid', session('domain_uuid'))
                ->get(['domain_uuid', 'extension', 'effective_caller_id_name']);
        }

        foreach ($data as $device) {
            // Check each line in the device if it exists
            $device->lines->each(function ($line) use ($extensions, $device) {
                // Find the first matching extension
                $firstMatch = $extensions->first(function ($extension) use ($line, $device) {
                    return $extension->domain_uuid === $device->domain_uuid && $extension->extension === $line->label;
                });
        
                // Assign the first matching extension to the line
                $line->extension = $firstMatch;
            });
            // logger($device->lines);
        }


        // logger($data);


        // foreach ($data as $device) {


        //     if ($device->lines()->first() && $device->lines()->first()->extension()) {
        //         $device->extension = $device->lines()->first()->extension()->extension;
        //         $device->extension_description = ($device->lines()->first()->extension()->effective_caller_id_name) ? '(' . trim($device->lines()->first()->extension()->effective_caller_id_name) . ')' : '';
        //         $device->extension_uuid = $device->lines()->first()->extension()->extension_uuid;
        //         $device->extension_edit_path = route('extensions.edit', $device->lines()->first()->extension());
        //         $device->send_notify_path = route(
        //             'extensions.send-event-notify',
        //             $device->lines()->first()->extension()
        //         );
        //     }
        //     $device->edit_path = route('devices.edit', $device);
        //     $device->destroy_path = route('devices.destroy', $device);
        // }
        return $data;
    }

    /**
     * @param  array  $filters
     * @return Builder
     */
    public function builder(array $filters = []): Builder
    {
        $data =  $this->model::query();

        if (isset($filters['showGlobal']) and $filters['showGlobal']) {
            $data->with(['domain' => function ($query) {
                $query->select('domain_uuid', 'domain_name', 'domain_description'); // Specify the fields you need
            }]);
            // Access domains through the session and filter devices by those domains
            $domainUuids = Session::get('domains')->pluck('domain_uuid');
            $data->whereHas('domain', function ($query) use ($domainUuids) {
                $query->whereIn($this->model->getTable() . '.domain_uuid', $domainUuids);
            });
        } else {
            // Directly filter devices by the session's domain_uuid
            $domainUuid = Session::get('domain_uuid');
            $data = $data->where($this->model->getTable() . '.domain_uuid', $domainUuid);
        }

        $data->with(['profile' => function ($query) {
            $query->select('device_profile_uuid', 'device_profile_name', 'device_profile_description');
        }]);

        $data->with(['lines' => function ($query) {
            $query->select('domain_uuid', 'device_line_uuid', 'device_uuid', 'line_number', 'label');
        }]);

        $data->select(
            'device_uuid',
            'device_profile_uuid',
            'device_address',
            'device_label',
            'device_template',
            'domain_uuid',
        );

        if (is_array($filters)) {
            foreach ($filters as $field => $value) {
                if (method_exists($this, $method = "filter" . ucfirst($field))) {
                    $this->$method($data, $value);
                }
            }
        }

        return $data;
    }

    /**
     * @param $query
     * @param $value
     * @return void
     */
    protected function filterSearch($query, $value): void
    {
        if ($value !== null) {
            // Case-insensitive partial string search in the specified fields
            $query->where(function ($query) use ($value) {
                $macAddress = tokenizeMacAddress($value);
                $query->where('device_address', 'ilike', '%' . $macAddress . '%')
                    ->orWhere('device_label', 'ilike', '%' . $value . '%')
                    ->orWhere('device_vendor', 'ilike', '%' . $value . '%')
                    ->orWhere('device_profile_name', 'ilike', '%' . $value . '%')
                    ->orWhere('device_template', 'ilike', '%' . $value . '%');
            });
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return void
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreDeviceRequest  $request
     * @return JsonResponse
     */
    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $inputs = $request->validated();

        if ($inputs['extension_uuid']) {
            $extension = Extensions::find($inputs['extension_uuid']);
        } else {
            $extension = null;
        }

        $device = new Devices();
        $device->fill([
            'device_address' => tokenizeMacAddress($inputs['device_address']),
            'device_label' => $extension->extension ?? null,
            'device_vendor' => explode("/", $inputs['device_template'])[0],
            'device_enabled' => 'true',
            'device_enabled_date' => date('Y-m-d H:i:s'),
            'device_template' => $inputs['device_template'],
            'device_profile_uuid' => $inputs['device_profile_uuid'],
            'device_description' => '',
        ]);
        $device->save();

        if ($extension) {
            // Create device lines
            $device->lines = new DeviceLines();
            $device->lines->fill([
                'device_uuid' => $device->device_uuid,
                'line_number' => '1',
                'server_address' => Session::get('domain_name'),
                'outbound_proxy_primary' => get_domain_setting('outbound_proxy_primary'),
                'outbound_proxy_secondary' => get_domain_setting('outbound_proxy_secondary'),
                'server_address_primary' => get_domain_setting('server_address_primary'),
                'server_address_secondary' => get_domain_setting('server_address_secondary'),
                'display_name' => $extension->extension,
                'user_id' => $extension->extension,
                'auth_id' => $extension->extension,
                'label' => $extension->extension,
                'password' => $extension->password,
                'sip_port' => get_domain_setting('line_sip_port'),
                'sip_transport' => get_domain_setting('line_sip_transport'),
                'register_expires' => get_domain_setting('line_register_expires'),
                'enabled' => 'true',
            ]);
            $device->lines->save();
        }


        return response()->json([
            'status' => 'success',
            'device' => $device,
            'message' => 'Device has been created and assigned.'
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  Devices  $device
     * @return void
     */
    public function show(Devices $device)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  Request  $request
     * @param  Devices  $device
     * @return JsonResponse
     */
    public function edit(Request $request, Devices $device): JsonResponse
    {
        if (!$request->ajax()) {
            return response()->json([
                'message' => 'XHR request expected'
            ], 405);
        }

        if ($device->extension()) {
            $device->extension_uuid = $device->extension()->extension_uuid;
        }

        $device->device_address = formatMacAddress($device->device_address);
        $device->update_path = route('devices.update', $device);
        $device->options = [
            'templates' => getVendorTemplateCollection(),
            'profiles' => getProfileCollection($device->domain_uuid),
            'extensions' => getExtensionCollection($device->domain_uuid)
        ];

        return response()->json([
            'status' => 'success',
            'device' => $device
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  UpdateDeviceRequest  $request
     * @param  Devices  $device
     * @return JsonResponse
     */
    public function update(UpdateDeviceRequest $request, Devices $device): JsonResponse
    {
        $inputs = $request->validated();
        $inputs['device_vendor'] = explode("/", $inputs['device_template'])[0];
        $device->update($inputs);

        if ($request['extension_uuid']) {
            $extension = Extensions::find($request['extension_uuid']);
            if (($device->extension() && $device->extension()->extension_uuid != $request['extension_uuid']) or !$device->extension()) {
                $deviceLinesExist = DeviceLines::query()->where(['device_uuid' => $device->device_uuid])->first();
                if ($deviceLinesExist) {
                    $deviceLinesExist->delete();
                }

                // Create device lines
                $deviceLines = new DeviceLines();
                $deviceLines->fill([
                    'device_uuid' => $device->device_uuid,
                    'line_number' => '1',
                    'server_address' => Session::get('domain_name'),
                    'outbound_proxy_primary' => get_domain_setting('outbound_proxy_primary'),
                    'outbound_proxy_secondary' => get_domain_setting('outbound_proxy_secondary'),
                    'server_address_primary' => get_domain_setting('server_address_primary'),
                    'server_address_secondary' => get_domain_setting('server_address_secondary'),
                    'display_name' => $extension->extension,
                    'user_id' => $extension->extension,
                    'auth_id' => $extension->extension,
                    'label' => $extension->extension,
                    'password' => $extension->password,
                    'sip_port' => get_domain_setting('line_sip_port'),
                    'sip_transport' => get_domain_setting('line_sip_transport'),
                    'register_expires' => get_domain_setting('line_register_expires'),
                    'enabled' => 'true',
                    'domain_uuid' => $device->domain_uuid
                ]);
                $deviceLines->save();
                $device->device_label = $extension->extension;
                $device->save();
            }
        }

        return response()->json([
            'status' => 'success',
            'device' => $device,
            'message' => 'Device has been updated.'
        ]);
    }

    public function bulkUpdate(UpdateBulkDeviceRequest $request): JsonResponse
    {
        $inputs = $request->validated();
        if (empty($inputs['device_profile_uuid']) && empty($inputs['device_template'])) {
            return response()->json([
                'message' =>  'No option selected to update.',
                'errors' => [
                    'no_option' => [
                        'No option selected to update.'
                    ]
                ]
            ], 422);
        }
        foreach ($inputs['devices'] as $deviceUuid) {
            $device = Devices::find($deviceUuid);
            if (!empty($inputs['device_profile_uuid'])) {
                $device->device_profile_uuid = $inputs['device_profile_uuid'];
            }
            if (!empty($inputs['device_template'])) {
                $device->device_template = $inputs['device_template'];
            }
            $device->save();
        }
        return response()->json([
            'status' => 'success',
            'message' => 'Devices has been updated.'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  Devices  $device
     * @return Response
     */
    public function destroy(Devices $device): Response
    {
        if ($device->lines()) {
            $device->lines()->delete();
        }
        $device->delete();

        return Inertia::render('Devices', [
            'data' => function () {
                return $this->getDevices();
            },
            'status' => 'success',
            'device' => $device,
            'message' => 'Device has been deleted'
        ]);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'templates' => getVendorTemplateCollection(),
            'profiles' => getProfileCollection(Session::get('domain_uuid')),
            'extensions' => getExtensionCollection(Session::get('domain_uuid'))
        ]);
    }
}
