document.addEventListener('DOMContentLoaded', async () => {
  const links = [...document.querySelectorAll('a[href*="page=product&id="]')];
  const ids = [...new Set(links.map((link) => new URL(link.href, location.href).searchParams.get('id')).filter(Boolean))];
  const pageUrl = new URL(location.href);
  const ownId = pageUrl.searchParams.get('page') === 'product' ? pageUrl.searchParams.get('id') : null;
  if (ownId && !ids.includes(ownId)) ids.push(ownId);
  if (!ids.length) return;

  try {
    const response = await fetch(`payment_quote.php?ids=${encodeURIComponent(ids.join(','))}`, { credentials: 'same-origin' });
    const data = response.ok ? await response.json() : null;
    if (!data?.status) return;

    for (const link of links) {
      const id = new URL(link.href, location.href).searchParams.get('id');
      const quote = data.quotes[id];
      const price = link.closest('.product-card')?.querySelector('.price');
      if (quote && price) price.innerHTML = `<span>${quote.price}</span><small>final price</small>`;
    }
    if (ownId && data.quotes[ownId]) {
      const price = document.querySelector('.product-info .price.large');
      if (price) price.innerHTML = `<span>${data.quotes[ownId].price}</span><small>final price, including payment charges</small>`;
    }
  } catch (_) {
    /* Keep the server-rendered price if the optional quote request is unavailable. */
  }
});
