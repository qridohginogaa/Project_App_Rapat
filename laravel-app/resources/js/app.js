const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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
        });
    };

    const renderMeetings = (meetings) => {
        const fragment = document.createDocumentFragment();

        if (meetings.length === 0) {
            fragment.appendChild(element('p', 'live-board-empty', 'Tidak ada rapat yang sedang berlangsung.'));
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
            const count = element('div', 'live-board-count');
            count.appendChild(element('strong', '', String(meeting.attendee_count)));
            count.appendChild(element('span', '', 'Hadir'));

            card.append(title, schedule, details, duration, count);
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
