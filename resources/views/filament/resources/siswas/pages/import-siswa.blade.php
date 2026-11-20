<x-filament-panels::page>
    <div @if ($this->sedangBerjalan()) wire:poll.3s="segarkan" @endif>
        {{ $this->content }}
    </div>
</x-filament-panels::page>
