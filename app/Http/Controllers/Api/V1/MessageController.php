<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\Present;
use App\Models\CreditPurchase;
use App\Models\MessageLog;
use App\Models\WhatsappTemplate;
use App\Support\LibrarySettings;
use App\Support\MessageTemplates;
use App\Support\Messaging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SMS and WhatsApp: credit counters (the header icons), the message history with Sent / Not sent,
 * the automatic messages switches and the templates (SMS templates are set by the platform; WhatsApp ones are the library's).
 */
class MessageController extends Controller
{
    public function counters(): JsonResponse
    {
        $this->authorizePermission('billing.view');
        $l = $this->library();

        return $this->ok(collect(CreditPurchase::CHANNELS)->map(fn ($label, $ch) => [
            'channel' => $ch, 'label' => $label, 'credits' => (int) $l->{$ch.'_credits'}, 'connected' => Messaging::connected($ch),
            'sent_today' => MessageLog::where('channel', $ch)->where('status', 'sent')->whereDate('created_at', today())->count(),
        ])->values()->all());
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorizePermission('billing.view');
        $f = $request->validate(['channel' => ['nullable', Rule::in(array_keys(CreditPurchase::CHANNELS))], 'status' => ['nullable', 'in:sent,failed'], 'student_id' => ['nullable', 'integer']]);
        $base = fn () => MessageLog::query()->when($f['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))->when($f['student_id'] ?? null, fn ($q, $s) => $q->where('student_id', $s));

        $page = $base()->with('student:id,name')->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate($this->perPage());

        return $this->paged($page, fn ($m) => [
            'id' => $m->id, 'channel' => $m->channel, 'mobile' => $m->mobile, 'student' => $m->student ? ['id' => $m->student->id, 'name' => $m->student->name] : null,
            'message' => $m->message, 'credits' => (int) $m->count, 'status' => $m->status, 'status_label' => $m->status === 'sent' ? 'Sent' : 'Not sent',
            'reason' => $m->status === 'sent' ? null : $m->error, 'event' => $m->event, 'event_label' => Messaging::EVENTS[$m->event][0] ?? null,
            'sent_at' => Present::t($m->created_at),
        ], ['totals' => ['sent' => $base()->where('status', 'sent')->count(), 'failed' => $base()->where('status', 'failed')->count()]]);
    }

    /** Automatic messages (on/off per channel), the SMS templates and the WhatsApp templates. */
    public function templates(): JsonResponse
    {
        $this->authorizePermission('billing.view');

        return $this->ok([
            'can_edit' => $this->can('settings.manage'),
            'placeholders' => collect(MessageTemplates::PLACEHOLDERS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'automatic' => collect(Messaging::EVENTS)->map(fn ($e, $key) => [
                'event' => $key, 'label' => $e[0], 'description' => $e[1],
                'sms' => Messaging::enabled($key, 'sms'), 'whatsapp' => Messaging::enabled($key, 'whatsapp'),
                'sms_template' => $e[2], 'whatsapp_template' => $e[3],
            ])->values()->all(),
            'sms' => collect(MessageTemplates::sms())->values()->map(fn ($t, $i) => ['name' => $t['name'], 'message' => $t['message']])->all(),
            'whatsapp' => WhatsappTemplate::orderBy('sort')->orderBy('id')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'message' => $t->message])->all(),
        ]);
    }

    /** Switch automatic messages, e.g. {"event":"payment","channel":"sms","enabled":true}. */
    public function toggle(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $this->writable();
        $d = $request->validate(['event' => ['required', Rule::in(array_keys(Messaging::EVENTS))], 'channel' => ['required', 'in:sms,whatsapp'], 'enabled' => ['required', 'boolean']]);
        $triggers = [];
        foreach (array_keys(Messaging::EVENTS) as $e) {
            $triggers[$e] = ['sms' => Messaging::enabled($e, 'sms'), 'whatsapp' => Messaging::enabled($e, 'whatsapp')];
        }
        $triggers[$d['event']][$d['channel']] = (bool) $d['enabled'];
        LibrarySettings::update(['message_triggers' => $triggers]);

        return $this->message('Saved.', ['event' => $d['event'], 'channel' => $d['channel'], 'enabled' => (bool) $d['enabled']]);
    }

    public function storeWhatsapp(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $this->writable();
        abort_if(WhatsappTemplate::count() >= 30, 422, 'You can keep up to 30 WhatsApp templates.');
        $d = $this->templateData($request);
        $t = WhatsappTemplate::create($d + ['sort' => (int) WhatsappTemplate::max('sort') + 1]);

        return $this->message('Template saved.', ['id' => $t->id, 'name' => $t->name, 'message' => $t->message], 201);
    }

    public function updateWhatsapp(Request $request, int $template): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $this->writable();
        $t = WhatsappTemplate::findOrFail($template);
        $t->update($this->templateData($request));

        return $this->message('Template saved.', ['id' => $t->id, 'name' => $t->name, 'message' => $t->message]);
    }

    public function destroyWhatsapp(int $template): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $this->writable();
        WhatsappTemplate::findOrFail($template)->delete();

        return $this->message('Template deleted.');
    }

    protected function templateData(Request $request): array
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'message' => ['required', 'string', 'max:1000']], ['name.required' => 'Give the template a name.', 'message.required' => 'Write the message.']);

        return ['name' => trim($d['name']), 'message' => trim($d['message'])];
    }
}
