
</main>
<footer class="site-footer">
    <p>GRANTED — Scholarship Compliance Monitoring, University of Luzon</p>
</footer>
<?php if (isAdminShell() || isScholarShell()): ?></div></div><?php endif; ?>
<script>
document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method.toUpperCase() !== 'POST') {
        return;
    }

    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
    }

    form.dataset.submitting = 'true';
    const submitter = event.submitter;
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
        if (button === submitter) {
            const loadingLabel = button.dataset.submittingLabel;
            if (loadingLabel) {
                if (button instanceof HTMLInputElement) {
                    button.value = loadingLabel;
                } else {
                    button.textContent = loadingLabel;
                }
            }
            return;
        }
        button.disabled = true;
    });

    if (submitter) {
        window.setTimeout(function () {
            submitter.disabled = true;
        }, 0);
    }
});

document.querySelectorAll('form[data-confirmation]').forEach(function (form) {
    var trigger = form.querySelector('[data-confirmation-trigger]');
    var panel = form.querySelector('[data-confirmation-panel]');
    var cancel = form.querySelector('[data-confirmation-cancel]');
    var hideWhileConfirming = form.parentElement.querySelectorAll('[data-confirmation-hide]');

    trigger.addEventListener('click', function (event) {
        event.preventDefault();
        trigger.hidden = true;
        hideWhileConfirming.forEach(function (element) {
            element.hidden = true;
        });
        panel.hidden = false;
        cancel.focus();
    });
    cancel.addEventListener('click', function () {
        panel.hidden = true;
        trigger.hidden = false;
        hideWhileConfirming.forEach(function (element) {
            element.hidden = false;
        });
        trigger.focus();
    });
});

document.querySelectorAll('[data-grade-confirmation-trigger]').forEach(function (trigger) {
    var confirmationRow = document.getElementById(trigger.getAttribute('aria-controls'));
    if (!confirmationRow) {
        return;
    }
    var cancel = confirmationRow.querySelector('[data-grade-confirmation-cancel]');

    trigger.addEventListener('click', function () {
        confirmationRow.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        cancel.focus();
    });
    cancel.addEventListener('click', function () {
        confirmationRow.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        trigger.focus();
    });
});
</script>
</body>
</html>
