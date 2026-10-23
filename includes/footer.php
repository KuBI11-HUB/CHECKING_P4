
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
</script>
</body>
</html>
