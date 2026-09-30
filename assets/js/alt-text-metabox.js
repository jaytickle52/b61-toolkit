/**
 * Banner AI Alt Text — Attachment metabox script
 * Handles the "Generate Alt Text" button on the individual attachment edit screen.
 */
(function () {
    var config = window.bannerAiAltTextMetabox || {};
    var btn = document.getElementById('banner-ai-generate-single');
    if (!btn) return;

    btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.textContent = 'Generating...';
        var body = new URLSearchParams({
            action: 'banner_ai_generate_alt',
            nonce: config.nonce,
            attachment_id: config.attachmentId,
        });
        fetch(config.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json.success) {
                    alert('Alt text generated: ' + json.data.alt_text);
                    location.reload();
                } else {
                    alert(json.data && json.data.message ? json.data.message : 'Generation failed');
                    btn.disabled = false;
                    btn.textContent = 'Generate Alt Text';
                }
            })
            .catch(function () {
                alert('Request failed. Please try again.');
                btn.disabled = false;
                btn.textContent = 'Generate Alt Text';
            });
    });
})();
