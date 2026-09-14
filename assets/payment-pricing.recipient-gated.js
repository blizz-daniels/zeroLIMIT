document.addEventListener('DOMContentLoaded', async () => {
  const links = [...document.querySelectorAll('a[href*="page=product&id="]')];
  const ids = [...new Set(links.map(link => new URL(link.href, location.href).searchParams.get('id')).filter(Boolean))];
  const ownId = new URL(location.href).searchParams.get('page') === 'product' ? new URL(location.href).searchParams.get('id') : null;
  if (ownId && !ids.includes(ownId)) ids.push(ownId);
  if (!ids.length) return;
  try {
    const data = await fetch(`payment_quote.php?ids=${encodeURIComponent(ids.join(','))}`, {credentials: 'same-origin'}).then(response => response.ok ? response.json() : null);
    if (!data?.status) return;
    for (const link of links) {
      const id = new URL(link.href, location.href).searchParams.get('id'); const quote = data.quotes[id]; if (!quote) continue;
      const card = link.closest('.product-card'); const price = card?.querySelector('.price');
      if (price) price.innerHTML = `<span>${quote.price}</span><small>final price</small>`;
    }
    if (ownId && data.quotes[ownId]) {
      const price = document.querySelector('.product-info .price.large');
      if (price) price.innerHTML = `<span>${data.quotes[ownId].price}</span><small>includes payment charges</small>`;
    }
  } catch (_) { /* Keep the existing price available if a quote cannot be loaded. */ }
});
