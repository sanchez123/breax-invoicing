'use strict';

document.querySelectorAll('[data-email-delivery-form]').forEach((form) => {
  form.addEventListener('submit', () => {
    form.closest('.card')?.classList.add('is-pending');
  });
});

document.querySelector('.email-delivery-grid .result.is-visible')?.focus();
