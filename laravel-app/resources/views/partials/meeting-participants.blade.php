@php
    $selectedParticipantIds = old('peserta', $participantIds ?? []);
    $selectedParticipantIds = is_array($selectedParticipantIds) ? array_map('strval', $selectedParticipantIds) : [];
    $participantPrefix = $participantPrefix ?? 'meeting';
    $allEmployees = old('semua_pegawai', $allEmployees ?? false);
@endphp
<div class="box mt-3" data-participant-picker>
    <label class="flex items-center gap-2">
        <input type="hidden" name="semua_pegawai" value="0">
        <input type="checkbox" name="semua_pegawai" value="1" style="width:auto" data-all-employees @checked((bool) $allEmployees)>
        Rapat untuk semua pegawai
    </label>
    <div data-participant-options>
        <b>Pegawai yang wajib mengikuti</b>
        <p class="mu">Atau cari dan centang satu atau beberapa pegawai tertentu.</p>
        <label for="{{ $participantPrefix }}-search">Cari pegawai</label>
        <input id="{{ $participantPrefix }}-search" type="search" placeholder="Ketik nama, NIP, atau jabatan" autocomplete="off" data-participant-search>
        <p class="mu" aria-live="polite"><span data-selected-count>{{ count($selectedParticipantIds) }}</span> pegawai dipilih</p>
        <div class="box" style="max-height:15rem;overflow:auto" data-participant-list>
            @foreach($employees as $person)
                <label class="participant-row flex items-center gap-2" data-participant-row>
                    <input type="checkbox" name="peserta[]" value="{{ $person->id }}" style="width:auto" @checked(in_array((string) $person->id, $selectedParticipantIds, true))>
                    <span>{{ $person->nama }} · {{ $person->nip }} · {{ $person->jabatan }}</span>
                </label>
            @endforeach
        </div>
    </div>
</div>
<script>
(function () {
    var picker = document.currentScript.previousElementSibling;
    var search = picker.querySelector('[data-participant-search]');
    var rows = Array.from(picker.querySelectorAll('[data-participant-row]'));
    var count = picker.querySelector('[data-selected-count]');
    var allEmployees = picker.querySelector('[data-all-employees]');
    var participantOptions = picker.querySelector('[data-participant-options]');
    function update() {
        rows.forEach(function (row) {
            var checkbox = row.querySelector('input[type="checkbox"]');
            row.hidden = !row.textContent.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase());
            checkbox.disabled = allEmployees.checked;
        });
        participantOptions.hidden = allEmployees.checked;
        count.textContent = rows.filter(function (row) {
            return row.querySelector('input[type="checkbox"]').checked;
        }).length;
    }
    allEmployees.addEventListener('change', update);
    search.addEventListener('input', update);
    rows.forEach(function (row) {
        row.querySelector('input[type="checkbox"]').addEventListener('change', update);
    });
    update();
})();
</script>
