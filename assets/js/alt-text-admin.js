/**
 * Banner AI Alt Text — Admin page script
 * Handles individual and bulk alt text generation on the Media > AI Alt Text page.
 */
(function () {
    var config = window.bannerAiAltText || {};

    function generate(id, row) {
        var body = new URLSearchParams({
            action: 'banner_ai_generate_alt',
            nonce: config.nonce,
            attachment_id: id,
        });
        return fetch(config.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json.success) {
                    throw new Error(json.data && json.data.message ? json.data.message : 'Generation failed');
                }
                if (row) {
                    var cell = row.querySelector('.current-alt');
                    if (cell) cell.textContent = json.data.alt_text;
                }
                return json.data.alt_text;
            });
    }

    document.querySelectorAll('.banner-ai-generate').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = this.closest('tr');
            this.disabled = true;
            this.textContent = 'Generating...';
            var self = this;
            generate(this.dataset.id, row)
                .then(function () { self.textContent = 'Done'; })
                .catch(function (e) {
                    alert(e.message);
                    self.disabled = false;
                    self.textContent = 'Generate Now';
                });
        });
    });

    var bulk = document.getElementById('banner-ai-bulk');
    if (bulk) {
        bulk.addEventListener('click', async function () {
            var rows = Array.from(document.querySelectorAll('tr[data-id]')).slice(0, config.batchLimit || 25);
            var done = 0;
            var status = document.getElementById('banner-ai-status');
            bulk.disabled = true;
            for (var i = 0; i < rows.length; i++) {
                if (status) status.textContent = 'Processing ' + (done + 1) + ' of ' + rows.length + '...';
                try {
                    await generate(rows[i].dataset.id, rows[i]);
                    done++;
                } catch (e) {
                    console.error(e);
                }
            }
            if (status) status.textContent = 'Done. Generated ' + done + ' alt text values.';
            bulk.disabled = false;
        });
    }
})();
