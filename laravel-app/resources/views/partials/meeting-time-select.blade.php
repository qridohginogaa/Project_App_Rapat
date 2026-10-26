<div>
    <b>Jam {{ $label }}</b>
    <div class="grid grid-cols-2 gap-2">
        <div>
            <label for="{{ $name }}-hour">Jam</label>
            <select id="{{ $name }}-hour" aria-label="Jam {{ strtolower($label) }}" data-time-hour>
                @foreach(range(0, 23) as $hour)
                    @php($formattedHour = sprintf('%02d', $hour))
                    <option value="{{ $formattedHour }}" @selected(substr($selectedTime, 0, 2) === $formattedHour)>{{ $formattedHour }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="{{ $name }}-minute">Menit</label>
            <select id="{{ $name }}-minute" aria-label="Menit {{ strtolower($label) }}" data-time-minute>
                @foreach(range(0, 59) as $minute)
                    @php($formattedMinute = sprintf('%02d', $minute))
                    <option value="{{ $formattedMinute }}" @selected(substr($selectedTime, 3, 2) === $formattedMinute)>{{ $formattedMinute }}</option>
                @endforeach
            </select>
        </div>
    </div>
</div>
<input type="hidden" name="{{ $name }}" value="{{ $selectedTime }}" data-time-value>
<script>
(function () {
    var field = document.currentScript.previousElementSibling;
    var container = field.previousElementSibling;
    var hour = container.querySelector('[data-time-hour]');
    var minute = container.querySelector('[data-time-minute]');
    function update() {
        field.value = hour.value + ':' + minute.value;
    }
    hour.addEventListener('change', update);
    minute.addEventListener('change', update);
    update();
})();
</script>
