document.addEventListener('DOMContentLoaded', () => {
  const menu = document.querySelector('.menu-toggle');
  const nav = document.querySelector('.main-nav');
  menu?.addEventListener('click', () => nav.classList.toggle('open'));
  document.querySelector('.filter-toggle')?.addEventListener('click', () => document.querySelector('.filters')?.classList.toggle('open'));
  document.querySelectorAll('[data-qty]').forEach(button => button.addEventListener('click', () => {
    const input = button.parentElement.querySelector('input'); const current = Number(input.value) || 1; const max = Number(input.max) || 999;
    input.value = button.dataset.qty === 'plus' ? Math.min(max, current + 1) : Math.max(1, current - 1);
  }));
  document.querySelectorAll('.thumbs img').forEach(thumb => thumb.addEventListener('click', () => { document.querySelector('.main-product-photo img').src = thumb.src; }));
  document.querySelectorAll('.wishlist').forEach(button => button.addEventListener('click', () => { button.textContent = button.textContent === '♡' ? '♥' : '♡'; }));
});
