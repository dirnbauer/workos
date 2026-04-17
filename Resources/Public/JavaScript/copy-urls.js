const trigger = document.querySelector('[data-action-copy-urls]');
if (trigger) {
    trigger.addEventListener('click', (event) => {
        event.preventDefault();

        const container = trigger.closest('.card') || document;
        const urls = Array.from(container.querySelectorAll('[data-callback-url]'))
            .map(el => el.textContent.trim())
            .filter(url => url.length > 0);

        if (urls.length === 0) {
            return;
        }

        const originalText = trigger.textContent;
        const restore = () => {
            setTimeout(() => { trigger.textContent = originalText; }, 2000);
        };

        navigator.clipboard.writeText(urls.join('\n')).then(() => {
            trigger.textContent = 'Copied ' + urls.length + ' URLs!';
            restore();
        }).catch(() => {
            const textarea = document.createElement('textarea');
            textarea.value = urls.join('\n');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            trigger.textContent = 'Copied ' + urls.length + ' URLs!';
            restore();
        });
    });
}
