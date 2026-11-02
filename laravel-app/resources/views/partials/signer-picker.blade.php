@php
    $selectedEmployee = $employees->firstWhere('id', (int) $employeeId);
@endphp
<div class="signer-picker" data-signer-picker>
    <input type="hidden" name="{{ $name }}" value="{{ $selectedEmployee?->id ?? '' }}" data-signer-value>
    <button
        id="{{ $pickerId }}"
        class="signer-picker-trigger"
        type="button"
        aria-haspopup="listbox"
        aria-expanded="false"
        aria-controls="{{ $pickerId }}-list"
        data-signer-trigger
    >
        <span data-signer-label>{{ $selectedEmployee ? $selectedEmployee->nama.' · '.$selectedEmployee->nip : 'Pilih akun pegawai' }}</span>
        <span class="signer-picker-chevron" aria-hidden="true">⌄</span>
    </button>
    <div class="signer-picker-menu" id="{{ $pickerId }}-list" hidden data-signer-menu>
        <label class="sr-only" for="{{ $pickerId }}-search">Cari {{ strtolower($label) }} berdasarkan nama atau NIP</label>
        <input
            id="{{ $pickerId }}-search"
            class="signer-picker-search"
            type="search"
            placeholder="Cari nama atau NIP..."
            autocomplete="off"
            role="combobox"
            aria-autocomplete="list"
            aria-expanded="true"
            aria-controls="{{ $pickerId }}-options"
            data-signer-search
        >
        <div class="signer-picker-options" id="{{ $pickerId }}-options" role="listbox" aria-label="Daftar {{ strtolower($label) }}" data-signer-options>
            @foreach($employees as $person)
                <button
                    class="signer-picker-option"
                    type="button"
                    role="option"
                    aria-selected="{{ (int) $person->id === (int) $selectedEmployee?->id ? 'true' : 'false' }}"
                    data-signer-option
                    data-value="{{ $person->id }}"
                    data-search-text="{{ $person->nama }} {{ $person->nip }} {{ $person->jabatan }}"
                >{{ $person->nama }} · {{ $person->nip }}</button>
            @endforeach
            <p class="signer-picker-empty" hidden data-signer-empty>Tidak ada pegawai yang cocok.</p>
        </div>
    </div>
</div>
