@php
    $selectedParticipantIds = old('peserta', $participantIds ?? []);
    $selectedParticipantIds = is_array($selectedParticipantIds) ? array_map('strval', $selectedParticipantIds) : [];
    $participantPrefix = $participantPrefix ?? 'meeting';
    $allEmployees = old('semua_pegawai', $allEmployees ?? false);
@endphp
<div class="participant-picker" data-participant-picker>
    <label class="participant-all-toggle">
        <input type="hidden" name="semua_pegawai" value="0">
        <input type="checkbox" name="semua_pegawai" value="1" data-all-employees @checked((bool) $allEmployees)>
        <span class="participant-all-toggle-copy">
            <strong>Rapat untuk semua pegawai</strong>
            <small>Semua pegawai dapat mengikuti rapat ini tanpa memilih satu per satu.</small>
        </span>
        <span class="participant-all-toggle-badge" aria-hidden="true">Semua</span>
    </label>
    <div class="participant-options" data-participant-options>
        <div class="participant-picker-heading">
            <div>
                <span class="participant-picker-eyebrow">DAFTAR UNDANGAN</span>
                <h4>Pegawai yang wajib mengikuti</h4>
                <p>Pilih berdasarkan jurusan atau unit, atau cari nama pegawai secara langsung.</p>
            </div>
            <div class="participant-selection-count" role="status" aria-live="polite" aria-atomic="true">
                <strong data-selected-count>{{ count($selectedParticipantIds) }}</strong>
                <span>dipilih</span>
            </div>
        </div>
        <div class="participant-search-field">
            <label for="{{ $participantPrefix }}-search">Cari pegawai</label>
            <div class="participant-search-control">
                <span aria-hidden="true">⌕</span>
                <input id="{{ $participantPrefix }}-search" type="search" placeholder="Ketik nama, NIP, atau jabatan" autocomplete="off" data-participant-search>
            </div>
        </div>
        <div class="participant-groups">
            @foreach($inviteGroups as $inviteGroup)
                @php
                    $groupName = $inviteGroup->group_name;
                    $groupEmployees = $employees->where('invite_group', $groupName);
                @endphp
                <details class="participant-group" data-participant-group>
                    <summary>
                        <span class="participant-group-chevron" aria-hidden="true">⌄</span>
                        <span>{{ $groupName }}</span>
                        <span class="participant-group-count">{{ $groupEmployees->count() }} pegawai</span>
                        <span class="participant-group-selected" data-group-selected-count>0 dipilih</span>
                    </summary>
                    <div class="participant-group-actions">
                        <button class="btn sm" type="button" data-select-group>Pilih semua pegawai dalam grup</button>
                        <button class="btn sm" type="button" data-clear-group>Kosongkan pilihan grup</button>
                    </div>
                    <div class="participant-list">
                        @foreach($groupEmployees as $person)
                            <label class="participant-row flex items-center gap-2" data-participant-row>
                                <input type="checkbox" name="peserta[]" value="{{ $person->id }}" style="width:auto" @checked(in_array((string) $person->id, $selectedParticipantIds, true))>
                                <span class="participant-row-copy">
                                    <strong>{{ $person->nama }}</strong>
                                    <small>{{ $person->nip }} · {{ $person->jabatan }}</small>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </details>
                @endforeach
        </div>
        <p class="participant-empty-state" data-participant-empty hidden>Tidak ada pegawai yang cocok dengan pencarian.</p>
    </div>
</div>
<script>
(function () {
    var picker = document.currentScript.previousElementSibling;
    var search = picker.querySelector('[data-participant-search]');
    var groups = Array.from(picker.querySelectorAll('[data-participant-group]'));
    var rows = Array.from(picker.querySelectorAll('[data-participant-row]'));
    var count = picker.querySelector('[data-selected-count]');
    var allEmployees = picker.querySelector('[data-all-employees]');
    var participantOptions = picker.querySelector('[data-participant-options]');
    var searchWasActive = false;
    var openGroupsBeforeSearch = [];
    function update() {
        var query = search.value.trim().toLocaleLowerCase();
        if (query && !searchWasActive) {
            openGroupsBeforeSearch = groups.map(function (group) { return group.open; });
            searchWasActive = true;
        }
        groups.forEach(function (group) {
            var groupRows = Array.from(group.querySelectorAll('[data-participant-row]'));
            var matchingRows = 0;
            var selectedRows = 0;
            groupRows.forEach(function (row) {
                var checkbox = row.querySelector('input[type="checkbox"]');
                var matches = !query || row.textContent.toLocaleLowerCase().includes(query);
                row.hidden = !matches;
                checkbox.disabled = allEmployees.checked;
                if (matches) matchingRows++;
                if (checkbox.checked) selectedRows++;
            });
            group.hidden = matchingRows === 0;
            if (query) group.open = matchingRows > 0;
            group.querySelector('[data-group-selected-count]').textContent = selectedRows + ' dipilih';
            group.querySelectorAll('[data-select-group], [data-clear-group]').forEach(function (button) {
                button.disabled = allEmployees.checked;
            });
        });
        if (!query && searchWasActive) {
            groups.forEach(function (group, index) {
                group.open = openGroupsBeforeSearch[index];
            });
            searchWasActive = false;
        }
        picker.querySelector('[data-participant-empty]').hidden = groups.some(function (group) {
            return !group.hidden;
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
    groups.forEach(function (group) {
        group.querySelector('[data-select-group]').addEventListener('click', function () {
            group.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) { checkbox.checked = true; });
            update();
        });
        group.querySelector('[data-clear-group]').addEventListener('click', function () {
            group.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) { checkbox.checked = false; });
            update();
        });
    });
    update();
})();
</script>
