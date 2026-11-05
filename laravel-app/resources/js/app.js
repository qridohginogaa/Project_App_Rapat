const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const themeToggle = document.querySelector('[data-theme-toggle]');

if (themeToggle) {
    const themeLabel = themeToggle.querySelector('[data-theme-label]');
    const themeIcon = themeToggle.querySelector('[data-theme-icon]');

    const applyTheme = (theme) => {
        const isDark = theme === 'dark';
        document.documentElement.dataset.theme = isDark ? 'dark' : 'light';
        themeToggle.setAttribute('aria-pressed', String(isDark));
        themeToggle.setAttribute('aria-label', isDark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap');
        themeLabel.textContent = isDark ? 'Terang' : 'Gelap';
        themeIcon.textContent = isDark ? '☀' : '☾';

        try {
            localStorage.setItem('rapat-poltekkes-theme', isDark ? 'dark' : 'light');
        } catch {
            themeToggle.setAttribute('title', 'Preferensi tema hanya berlaku selama halaman ini terbuka.');
        }
    };

    applyTheme(document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light');
    themeToggle.addEventListener('click', () => {
        applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
    });
}

document.querySelectorAll('[data-copy-attendance-code]').forEach((button) => {
    button.addEventListener('click', async () => {
        const container = button.closest('[data-attendance-code-copy]');
        const input = container?.querySelector('input');
        const status = container?.querySelector('[data-copy-attendance-status]');

        if (!input || !status) return;

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(input.value);
            } else {
                input.focus();
                input.select();
                if (!document.execCommand('copy')) {
                    throw new Error('Penyalinan tidak didukung browser.');
                }
            }
            status.textContent = 'Kode berhasil disalin.';
        } catch {
            input.focus();
            input.select();
            status.textContent = 'Kode belum tersalin. Salin teks yang sudah dipilih.';
        }
    });
});

document.querySelectorAll('[data-signer-picker]').forEach((picker) => {
    const trigger = picker.querySelector('[data-signer-trigger]');
    const menu = picker.querySelector('[data-signer-menu]');
    const search = picker.querySelector('[data-signer-search]');
    const value = picker.querySelector('[data-signer-value]');
    const label = picker.querySelector('[data-signer-label]');
    const options = Array.from(picker.querySelectorAll('[data-signer-option]'));
    const empty = picker.querySelector('[data-signer-empty]');
    let activeIndex = -1;

    const visibleOptions = () => options.filter((option) => !option.hidden);
    const setActiveOption = (index) => {
        const visible = visibleOptions();
        if (!visible.length) {
            activeIndex = -1;
            return;
        }

        activeIndex = (index + visible.length) % visible.length;
        visible.forEach((option, optionIndex) => {
            option.classList.toggle('is-active', optionIndex === activeIndex);
        });
        visible[activeIndex].scrollIntoView({ block: 'nearest' });
    };

    const close = (returnFocus = false) => {
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        search.setAttribute('aria-expanded', 'false');
        trigger.classList.remove('is-open');
        activeIndex = -1;
        options.forEach((option) => option.classList.remove('is-active'));
        if (returnFocus) trigger.focus();
    };

    const open = () => {
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        search.setAttribute('aria-expanded', 'true');
        trigger.classList.add('is-open');
        search.focus();
    };

    const filter = () => {
        const query = search.value.trim().toLocaleLowerCase();
        let visibleCount = 0;
        options.forEach((option) => {
            const matches = option.dataset.searchText.toLocaleLowerCase().includes(query);
            option.hidden = !matches;
            option.setAttribute('aria-selected', String(option.dataset.value === value.value));
            if (matches) visibleCount += 1;
        });
        empty.hidden = visibleCount > 0;
        activeIndex = -1;
        options.forEach((option) => option.classList.remove('is-active'));
    };

    const selectOption = (option) => {
        value.value = option.dataset.value;
        label.textContent = option.textContent.trim();
        options.forEach((item) => {
            item.setAttribute('aria-selected', String(item === option));
        });
        search.value = '';
        filter();
        close();
        trigger.focus();
    };

    trigger.addEventListener('click', () => {
        if (menu.hidden) open();
        else close();
    });

    search.addEventListener('input', filter);
    search.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveOption(activeIndex + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveOption(activeIndex < 0 ? visibleOptions().length - 1 : activeIndex - 1);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const visible = visibleOptions();
            const option = activeIndex >= 0 ? visible[activeIndex] : visible[0];
            if (option) selectOption(option);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            close(true);
        } else if (event.key === 'Tab') {
            close();
        }
    });

    options.forEach((option) => {
        option.addEventListener('click', () => selectOption(option));
        option.addEventListener('mouseenter', () => {
            const visible = visibleOptions();
            setActiveOption(visible.indexOf(option));
        });
    });

    document.addEventListener('click', (event) => {
        if (!picker.contains(event.target)) close();
    });

    filter();
});

document.querySelectorAll('[data-upcoming-slider]').forEach((slider) => {
    const track = slider.querySelector('[data-slider-track]');
    const slides = Array.from(slider.querySelectorAll('[data-upcoming-slide]'));
    const dots = Array.from(slider.querySelectorAll('[data-slider-dot]'));
    const previous = slider.querySelector('[data-slider-prev]');
    const next = slider.querySelector('[data-slider-next]');
    if (!track || slides.length < 2) return;

    let activeIndex = 0;
    let timer;

    const showSlide = (index) => {
        activeIndex = (index + slides.length) % slides.length;
        track.style.transform = `translateX(-${activeIndex * 100}%)`;
        slides.forEach((slide, slideIndex) => {
            const isActive = slideIndex === activeIndex;
            slide.setAttribute('aria-hidden', String(!isActive));
            slide.inert = !isActive;
        });
        dots.forEach((dot, dotIndex) => {
            dot.setAttribute('aria-pressed', String(dotIndex === activeIndex));
        });
    };

    const stopAutoplay = () => window.clearInterval(timer);
    const startAutoplay = () => {
        stopAutoplay();
        if (!reducedMotion && !document.hidden && !slider.matches(':hover') && !slider.contains(document.activeElement)) {
            timer = window.setInterval(() => showSlide(activeIndex + 1), 6000);
        }
    };

    previous?.addEventListener('click', () => {
        showSlide(activeIndex - 1);
        startAutoplay();
    });
    next?.addEventListener('click', () => {
        showSlide(activeIndex + 1);
        startAutoplay();
    });
    dots.forEach((dot) => {
        dot.addEventListener('click', () => {
            showSlide(Number(dot.dataset.sliderDot));
            startAutoplay();
        });
    });

    slider.addEventListener('mouseenter', stopAutoplay);
    slider.addEventListener('mouseleave', startAutoplay);
    slider.addEventListener('focusin', stopAutoplay);
    slider.addEventListener('focusout', (event) => {
        if (!slider.contains(event.relatedTarget)) startAutoplay();
    });
    document.addEventListener('visibilitychange', startAutoplay);

    showSlide(0);
    startAutoplay();
});

if (!reducedMotion && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries, currentObserver) => {
        entries.forEach((entry) => {
            entry.target.classList.toggle('is-visible', entry.isIntersecting);
        });
    }, {
        threshold: 0.12,
    });

    document.querySelectorAll('main.wrap > section, main.wrap > .grid > .stat, main.wrap > .mt, .completed-meetings-grid > .mt').forEach((element) => {
        if (element.hidden || element.hasAttribute('hidden')) return;

        element.classList.add('reveal-on-scroll');
        observer.observe(element);
    });
}

const liveBoard = document.querySelector('[data-live-board]');

if (liveBoard) {
    const endpoint = liveBoard.dataset.endpoint;
    const meetingContainer = document.getElementById('live-board-meetings');
    const updateLabel = document.getElementById('live-board-updated');
    const indicator = document.querySelector('.live-board-indicator');
    const clock = document.getElementById('live-board-clock');
    const fullscreenButton = document.getElementById('live-board-fullscreen');
    const updateFullscreenControls = () => {
        const isFullscreen = Boolean(document.fullscreenElement);
        document.body.classList.toggle('is-fullscreen', isFullscreen);
        fullscreenButton.textContent = isFullscreen ? 'Keluar layar penuh' : 'Tampilkan layar penuh';
        fullscreenButton.setAttribute('aria-label', fullscreenButton.textContent);
    };
    let isUpdating = false;

    const updateClock = () => {
        clock.textContent = new Intl.DateTimeFormat('id-ID', {
            timeZone: 'Asia/Makassar',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hourCycle: 'h23',
        }).format(new Date()) + ' WITA';
    };

    const element = (tagName, className, text) => {
        const node = document.createElement(tagName);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };

    const updateElapsedTimes = () => {
        const now = Date.now();
        meetingContainer.querySelectorAll('[data-meeting-start]').forEach((duration) => {
            const start = Date.parse(duration.dataset.meetingStart);
            const seconds = Math.max(0, Math.floor((now - start) / 1000));
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const remainingSeconds = seconds % 60;
            const elapsed = hours > 0
                ? `${hours} jam ${minutes} menit ${remainingSeconds} detik`
                : `${minutes} menit ${remainingSeconds} detik`;
            duration.textContent = `Berlangsung ${elapsed}`;

            const overtime = duration.parentElement.querySelector('[data-meeting-overtime]');
            const scheduledEnd = Date.parse(duration.dataset.scheduledEnd);
            const overtimeSeconds = Math.max(0, Math.floor((now - scheduledEnd) / 1000));
            const overtimeHours = Math.floor(overtimeSeconds / 3600);
            const overtimeMinutes = Math.floor((overtimeSeconds % 3600) / 60);
            const overtimeRemainingSeconds = overtimeSeconds % 60;
            overtime.hidden = overtimeSeconds === 0;
            overtime.textContent = overtimeHours > 0
                ? `Overtime ${overtimeHours} jam ${overtimeMinutes} menit ${overtimeRemainingSeconds} detik`
                : `Overtime ${overtimeMinutes} menit ${overtimeRemainingSeconds} detik`;
        });
    };

    const renderMeetings = (meetings) => {
        const fragment = document.createDocumentFragment();

        if (meetings.length === 0) {
            const emptyState = element('section', 'live-board-empty');
            emptyState.setAttribute('aria-label', 'Tidak ada rapat aktif');
            const icon = element('span', 'live-board-empty-icon', '✓');
            icon.setAttribute('aria-hidden', 'true');
            const copy = element('div', 'live-board-empty-copy');
            copy.append(
                element('span', 'live-board-empty-kicker', 'SEMUA TERKINI'),
                element('h2', '', 'Tidak ada rapat yang sedang berlangsung'),
                element('p', '', 'Informasi rapat aktif akan muncul otomatis di sini saat rapat dimulai.')
            );
            emptyState.append(icon, copy);
            fragment.appendChild(emptyState);
            meetingContainer.replaceChildren(fragment);
            return;
        }

        meetings.forEach((meeting) => {
            const card = element('article', 'live-board-card');
            const title = element('h2', '', meeting.title);
            const schedule = element('p', 'live-board-schedule', `${meeting.start} – ${meeting.end}`);
            const details = element('p', 'live-board-meta', `${meeting.place} · ${meeting.mode}`);
            const duration = element('p', 'live-board-duration');
            duration.dataset.meetingStart = meeting.started_at;
            duration.dataset.scheduledEnd = meeting.scheduled_end_at;
            const overtime = element('p', 'live-board-overtime');
            overtime.dataset.meetingOvertime = '';
            overtime.hidden = true;
            const count = element('div', 'live-board-count');
            count.appendChild(element('strong', '', String(meeting.attendee_count)));
            count.appendChild(element('span', '', 'Hadir'));

            card.append(title, schedule, details, duration, overtime, count);
            fragment.appendChild(card);
        });

        meetingContainer.replaceChildren(fragment);
        updateElapsedTimes();
    };

    const refreshBoard = async () => {
        if (isUpdating) return;
        isUpdating = true;

        try {
            const response = await fetch(endpoint, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!response.ok) throw new Error(`Server mengembalikan status ${response.status}.`);

            const data = await response.json();
            renderMeetings(data.meetings);
            updateLabel.textContent = `Diperbarui ${new Intl.DateTimeFormat('id-ID', {
                timeZone: 'Asia/Makassar',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hourCycle: 'h23',
            }).format(new Date(data.updated_at))} WITA · otomatis setiap 5 detik`;
            indicator.classList.remove('is-error');
        } catch (error) {
            indicator.classList.add('is-error');
            updateLabel.textContent = `Gagal memperbarui data live board: ${error.message}`;
        } finally {
            isUpdating = false;
        }
    };

    fullscreenButton.addEventListener('click', async () => {
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else {
                await document.documentElement.requestFullscreen();
            }
        } catch (error) {
            indicator.classList.add('is-error');
            updateLabel.textContent = `Mode layar penuh gagal dibuka: ${error.message}`;
        }
    });

    document.addEventListener('fullscreenchange', updateFullscreenControls);
    updateFullscreenControls();
    updateClock();
    window.setInterval(updateElapsedTimes, 1000);
    refreshBoard();
    window.setInterval(updateClock, 1000);
    window.setInterval(refreshBoard, 5000);
}
