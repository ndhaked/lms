<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\Present;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fingerprint / face / card machines (same as the web Biometric devices page). Viewing needs attendance.view;
 * adding, changing and removing devices needs attendance.correct. A device's access token is shown only once.
 */
class BiometricDeviceController extends Controller
{
    protected function own(int $id): BiometricDevice
    {
        return $this->library()->biometricDevices()->findOrFail($id);
    }

    public function index(): JsonResponse
    {
        $this->requireModule('attendance');
        $this->authorizePermission('attendance.view');

        return $this->ok([
            'devices' => $this->library()->biometricDevices()->withCount(['punches as today_count' => fn ($q) => $q->whereDate('punched_at', today())])->orderBy('name')->get()->map(fn ($d) => $this->device($d))->all(),
            'setup' => ['server' => request()->getHost(), 'port' => request()->getPort(), 'https' => request()->isSecure(), 'json_url' => url('/api/biometric/punches')],
            'can_manage' => $this->can('attendance.correct'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireModule('attendance');
        $this->authorizePermission('attendance.correct');
        $this->writable();
        $device = $this->library()->biometricDevices()->create($this->data($request, null));
        $token = $device->issueToken();

        return $this->message('Device saved. Copy the access token now; it is not shown again.', $this->device($device) + ['access_token' => $token], 201);
    }

    public function update(Request $request, int $device): JsonResponse
    {
        $this->requireModule('attendance');
        $this->authorizePermission('attendance.correct');
        $this->writable();
        $d = $this->own($device);
        $d->update($this->data($request, $d->id) + ($request->has('is_active') ? ['is_active' => $request->boolean('is_active')] : []));

        return $this->message('Device saved.', $this->device($d));
    }

    public function token(int $device): JsonResponse
    {
        $this->requireModule('attendance');
        $this->authorizePermission('attendance.correct');
        $this->writable();
        $d = $this->own($device);

        return $this->message('New access token made. The old one no longer works.', ['access_token' => $d->issueToken()]);
    }

    public function destroy(int $device): JsonResponse
    {
        $this->requireModule('attendance');
        $this->authorizePermission('attendance.correct');
        $this->writable();
        $this->own($device)->delete();

        return $this->message('Device removed.');
    }

    public function punches(Request $request): JsonResponse
    {
        $this->requireModule('attendance');
        $this->authorizePermission('attendance.view');
        $result = $request->query('result');
        $page = BiometricPunch::with(['device:id,name', 'student:id,name,student_code'])
            ->when(isset(BiometricPunch::RESULTS[$result]), fn ($q) => $q->where('result', $result))
            ->when($request->integer('device_id'), fn ($q, $id) => $q->where('biometric_device_id', $id))
            ->latest('punched_at')->latest('id')->paginate($this->perPage());

        return $this->paged($page, fn ($p) => [
            'id' => $p->id, 'punched_at' => Present::t($p->punched_at), 'device' => $p->device?->name, 'pin' => $p->pin,
            'student' => $p->student ? ['id' => $p->student->id, 'name' => $p->student->name, 'code' => $p->student->student_code] : null,
            'result' => $p->result, 'result_label' => BiometricPunch::RESULTS[$p->result] ?? $p->result, 'message' => $p->message,
        ], ['unknown_today' => BiometricPunch::where('result', 'unknown')->whereDate('punched_at', today())->count()]);
    }

    protected function data(Request $request, ?int $id): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'serial' => ['required', 'string', 'max:80', Rule::unique('biometric_devices', 'serial')->ignore($id)],
            'location' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ], ['serial.unique' => 'This serial number is already registered.']);

        return ['name' => $data['name'], 'serial' => trim($data['serial']), 'location' => $data['location'] ?? null];
    }

    protected function device(BiometricDevice $d): array
    {
        return [
            'id' => $d->id, 'name' => $d->name, 'serial' => $d->serial, 'location' => $d->location, 'is_active' => (bool) $d->is_active,
            'online' => $d->isOnline(), 'last_seen_at' => Present::t($d->last_seen_at), 'punches_today' => (int) ($d->today_count ?? 0),
        ];
    }
}
