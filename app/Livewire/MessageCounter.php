<?php

namespace App\Livewire;

use App\Models\CreditPurchase;
use App\Models\Library;
use App\Support\Tenancy;
use Livewire\Component;

/** The SMS and WhatsApp icons in the panel header, with a pop-up showing each channel's credit, left and used counts. */
class MessageCounter extends Component
{
    /** @return array<string, array{label: string, credit: int, left: int, used: int, pct: int}> */
    protected function counters(): array
    {
        $library = Library::find(Tenancy::currentId());
        $out = [];

        foreach (CreditPurchase::CHANNELS as $key => $label) {
            $left = (int) ($library?->{$key.'_credits'} ?? 0);
            $bought = (int) CreditPurchase::where('library_id', Tenancy::currentId())->where('channel', $key)->where('status', 'approved')->sum('credits');
            $credit = max($bought, $left);
            $used = max(0, $credit - $left);

            $out[$key] = ['label' => $label, 'credit' => $credit, 'left' => $left, 'used' => $used, 'pct' => $credit > 0 ? (int) round($used / $credit * 100) : 0];
        }

        return $out;
    }

    public function render()
    {
        return view('livewire.message-counter', ['counters' => $this->counters(), 'slug' => Tenancy::slug()]);
    }
}
