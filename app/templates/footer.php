    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Guard against double submission: block a second submit of the same form
    // (impatient double-click while the POST / LLM call is in flight). The
    // disable runs on a 0ms timeout so the clicked button's name/value is still
    // included in the POST (refinement.php relies on action=analyse/continue).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.dataset.submitting === 'true') {
            e.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        setTimeout(function () {
            form.querySelectorAll('button[type="submit"]').forEach(function (b) {
                b.disabled = true;
            });
        }, 0);
    }, true);
    </script>
</body>
</html>
