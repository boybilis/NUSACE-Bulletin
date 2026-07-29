(() => {
  const EYE_ICON = `
    <svg class="password-toggle-icon password-eye-open" viewBox="0 0 24 24" aria-hidden="true">
      <path d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z"></path>
      <circle cx="12" cy="12" r="2.75"></circle>
    </svg>
    <svg class="password-toggle-icon password-eye-closed" viewBox="0 0 24 24" aria-hidden="true">
      <path d="m3 3 18 18"></path>
      <path d="M10.6 6.12A9.97 9.97 0 0 1 12 6c6.25 0 9.75 6 9.75 6a16.7 16.7 0 0 1-2.32 3.05"></path>
      <path d="M6.2 6.2C3.65 8.02 2.25 12 2.25 12s3.5 6 9.75 6a9.8 9.8 0 0 0 3.04-.47"></path>
      <path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"></path>
    </svg>
  `;

  function enhancePasswordInput(input) {
    if (!(input instanceof HTMLInputElement) || input.dataset.passwordToggleReady === 'true') {
      return;
    }

    input.dataset.passwordToggleReady = 'true';

    const wrapper = document.createElement('div');
    wrapper.className = 'password-input-wrap';
    input.parentNode?.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'password-toggle';
    button.setAttribute('aria-label', 'Show password');
    button.setAttribute('aria-pressed', 'false');
    button.title = 'Show password';
    button.innerHTML = EYE_ICON;

    button.addEventListener('click', () => {
      const showPassword = input.type === 'password';
      input.type = showPassword ? 'text' : 'password';
      button.classList.toggle('is-visible', showPassword);
      button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
      button.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
      button.title = showPassword ? 'Hide password' : 'Show password';
      input.focus({ preventScroll: true });
    });

    wrapper.appendChild(button);
  }

  function enhanceAllPasswordInputs(root = document) {
    root.querySelectorAll('input[type="password"]').forEach(enhancePasswordInput);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => enhanceAllPasswordInputs(), { once: true });
  } else {
    enhanceAllPasswordInputs();
  }

  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (!(node instanceof Element)) {
          return;
        }

        if (node.matches('input[type="password"]')) {
          enhancePasswordInput(node);
        }
        enhanceAllPasswordInputs(node);
      });
    });
  });

  observer.observe(document.documentElement, {
    childList: true,
    subtree: true
  });
})();
