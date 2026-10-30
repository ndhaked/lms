<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The person's notifications (the same ones as the bell on the web). Optional `library_id` shows only that library's
 * messages, as the web does inside a library. Only the person's own notifications can be read or marked.
 */
class NotificationController extends Controller
{
    protected function query(Request $request): Builder
    {
        $user = $request->user();
        $libraryIds = $user->accessibleLibraries()->pluck('id')->all();
        $library = $request->integer('library_id') ?: null;
        abort_if($library && ! in_array($library, $libraryIds, true), 404);

        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id)
            ->when($library, fn ($q) => $q->where('data->library_id', $library));
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->query($request)->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))->latest()->paginate($this->perPage());

        return $this->paged($page, fn (DatabaseNotification $n) => $this->row($n), ['unread' => $this->query($request)->whereNull('read_at')->count()]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->ok(['unread' => $this->query($request)->whereNull('read_at')->count()]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $n = $this->query($request)->whereKey($id)->firstOrFail();
        $n->markAsRead();

        return $this->ok($this->row($n));
    }

    public function readAll(Request $request): JsonResponse
    {
        $count = $this->query($request)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->message('All caught up.', ['marked' => $count]);
    }

    protected function row(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id, 'kind' => $n->data['kind'] ?? null, 'title' => $n->data['title'] ?? '', 'body' => $n->data['body'] ?? '',
            'library_id' => $n->data['library_id'] ?? null, 'url' => $n->data['url'] ?? null,
            'read' => $n->read_at !== null, 'created_at' => $this->time($n->created_at),
        ];
    }
}
