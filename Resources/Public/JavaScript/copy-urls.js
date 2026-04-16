const button = document.querySelector('[data-action-copy-urls]');
if (button) {
    button.addEventListener('click', () => {
        const container = button.closest('.card-body');
        const urls = Array.from(container.querySelectorAll('[data-callback-url]'))
            .map(el => el.textContent.trim())
            .filter(url => url.length > 0);

        if (urls.length === 0) {
            return;
        }

        const originalText = button.textContent;
        navigator.clipboard.writeText(urls.join('\n')).then(() => {
            button.textContent = 'Copied ' + urls.length + ' URLs!';
            button.classList.replace('btn-outline-secondary', 'btn-success');
            setTimeout(() => {
                button.textContent = originalText;
                button.classList.replace('btn-success', 'btn-outline-secondary');
            }, 2000);
        }).catch(() => {
            const textarea = document.createElement('textarea');
            textarea.value = urls.join('\n');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            button.textContent = 'Copied ' + urls.length + ' URLs!';
            setTimeout(() => { button.textContent = originalText; }, 2000);
        });
    });
}
