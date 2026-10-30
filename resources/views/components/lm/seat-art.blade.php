@props(['state' => 'available'])

{{-- Small illustrations for a seat card: available (desk and chair), occupied (someone studying), reserved (desk with a tag), blocked (desk out of use). --}}
@switch($state)
    @case('occupied')
        <svg viewBox="0 0 64 56" class="h-14 w-16" aria-hidden="true">
            <circle cx="48" cy="9" r="3" fill="#fbbf24"/><path d="M48 3v-2M54 9h2M42 9h-2M52 5l1.5-1.5M44 5l-1.5-1.5" stroke="#fbbf24" stroke-width="1.4" stroke-linecap="round"/>
            <circle cx="30" cy="14" r="7" fill="#f4b98a"/><path d="M23 12c1-6 13-8 15 0-4-2-10-2-15 0z" fill="#3b2a1f"/>
            <path d="M18 40c0-10 5-17 12-17s12 7 12 17z" fill="#4f9be8"/>
            <rect x="12" y="33" width="36" height="17" rx="2" fill="#2f6fb7"/><rect x="15" y="36" width="30" height="11" rx="1" fill="#9cc9f5"/>
            <circle cx="30" cy="41.5" r="2.4" fill="#fff"/>
            <rect x="6" y="50" width="52" height="4" rx="1.5" fill="#d9a21b"/>
        </svg>
        @break
    @case('reserved')
        <svg viewBox="0 0 64 56" class="h-14 w-16" aria-hidden="true">
            <rect x="22" y="12" width="20" height="16" rx="2" fill="#a16207" opacity=".55"/><rect x="26" y="26" width="12" height="3" fill="#a16207" opacity=".55"/>
            <rect x="8" y="31" width="48" height="6" rx="2" fill="#d9a21b" opacity=".8"/><rect x="12" y="37" width="3" height="14" fill="#a16207" opacity=".7"/><rect x="49" y="37" width="3" height="14" fill="#a16207" opacity=".7"/>
            <path d="M40 4h14a2 2 0 0 1 2 2v20l-9-5-9 5V6a2 2 0 0 1 2-2z" fill="#f59e0b"/><path d="M44 10l3 3 5-5" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        @break
    @case('blocked')
        <svg viewBox="0 0 64 56" class="h-14 w-16" aria-hidden="true">
            <rect x="22" y="12" width="20" height="16" rx="2" fill="#9ca3af"/><rect x="26" y="26" width="12" height="3" fill="#9ca3af"/>
            <rect x="8" y="31" width="48" height="6" rx="2" fill="#b8bdc6"/><rect x="12" y="37" width="3" height="14" fill="#9ca3af"/><rect x="49" y="37" width="3" height="14" fill="#9ca3af"/>
            <circle cx="46" cy="14" r="9" fill="#6b7280"/><path d="M41.5 9.5l9 9M50.5 9.5l-9 9" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
        </svg>
        @break
    @default
        <svg viewBox="0 0 64 56" class="h-14 w-16" aria-hidden="true">
            <rect x="22" y="10" width="20" height="17" rx="2.5" fill="#7c3f12"/><rect x="26" y="25" width="12" height="4" fill="#7c3f12"/>
            <rect x="24" y="29" width="3" height="9" fill="#5b2d0c"/><rect x="37" y="29" width="3" height="9" fill="#5b2d0c"/>
            <rect x="6" y="31" width="52" height="7" rx="2" fill="#d9a21b"/><rect x="6" y="36" width="52" height="2.5" fill="#b9840f"/>
            <rect x="11" y="38" width="3.5" height="14" fill="#9a6a0c"/><rect x="49.5" y="38" width="3.5" height="14" fill="#9a6a0c"/>
        </svg>
@endswitch
