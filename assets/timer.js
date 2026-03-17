document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('claimForm');
    const emailInput = form ? form.querySelector('input[name="email"]') : null;
    const claimButton = document.getElementById('claimButton');

    if (!form || !emailInput || !claimButton) {
        return;
    }

    let countdownEl = document.getElementById('claimCountdown');
    if (!countdownEl) {
        countdownEl = document.createElement('div');
        countdownEl.id = 'claimCountdown';
        countdownEl.className = 'text-warning text-center fw-semibold mt-1';
        form.appendChild(countdownEl);
    }

    function formatTime(totalSeconds) {
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }

    function startCountdown(remaining) {
        let seconds = Number(remaining) || 0;

        if (seconds <= 0) {
            claimButton.disabled = false;
            countdownEl.textContent = '';
            return;
        }

        claimButton.disabled = true;
        countdownEl.textContent = `Další claim za ${formatTime(seconds)}`;

        const timerId = setInterval(function () {
            seconds -= 1;
            if (seconds <= 0) {
                clearInterval(timerId);
                claimButton.disabled = false;
                countdownEl.textContent = '';
                return;
            }
            countdownEl.textContent = `Další claim za ${formatTime(seconds)}`;
        }, 1000);
    }

    const email = emailInput.value.trim();
    if (!email) {
        return;
    }

    fetch(`timer.php?email=${encodeURIComponent(email)}`)
        .then((response) => {
            if (!response.ok) {
                throw new Error('Timer request failed');
            }
            return response.json();
        })
        .then((data) => {
            const remaining = Number(data && data.remaining_seconds ? data.remaining_seconds : 0);
            if (remaining > 0) {
                startCountdown(remaining);
            }
        })
        .catch(() => {
            // no-op
        });
});
