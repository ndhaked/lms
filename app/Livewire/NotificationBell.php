<?php

namespace App\Livewire;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * In-app notifications for the signed-in user. In a library only that library's messages show;
 * on the platform side only platform-level ones do. No polling: it updates when the page is used.
 */
class NotificationBell extends Component
{
    public bool $open = false;

    protected function query(): Builder
    {
        $libraryId = Tenancy::currentId();

        return DatabaseNotification::query()
            ->where('notifiable_type', auth()->user()->getMorphClass())
            ->where('notifiable_id', auth()->id())
            ->when(
                $libraryId,
                fn ($q) => $q->where('data->library_id', $libraryId),
                fn ($q) => $q->where(fn ($q) => $q->whereNull('data->library_id')->orWhere('data->library_id', null)),
            );
    }

    #[Computed]
    public function unread(): int
    {
        return $this->query()->whereNull('read_at')->count();
    }

    #[Computed]
    public function items()
    {
        return $this->query()->latest()->limit(10)->get();
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function read(string $id): void
    {
        $n = $this->query()->whereKey($id)->first();   // only the user's own, current-library notifications
        $n?->markAsRead();
        unset($this->unread, $this->items);

        if ($n && ($url = $n->data['url'] ?? null) && str_starts_with($url, url('/'))) {
            $this->redirect($url, navigate: true);
        }
    }

    public function markAllRead(): void
    {
        $this->query()->whereNull('read_at')->update(['read_at' => now()]);
        unset($this->unread, $this->items);
    }

    public function render()
    {
        return view('livewire.notification-bell');
    }
}
