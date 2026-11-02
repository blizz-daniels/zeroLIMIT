document.addEventListener('DOMContentLoaded', () => {
  const quoteJson = async (url) => {
    const response = await fetch(url, { credentials: 'same-origin' });
    return response.ok ? response.json() : null;
  };

  const replaceProductPrices = async () => {
    const links = [...document.querySelectorAll('a[href*="page=product&id="]')];
    const ids = [...new Set(links.map((link) => new URL(link.href, location.href).searchParams.get('id')).filter(Boolean))];
    const pageUrl = new URL(location.href);
    const ownId = pageUrl.searchParams.get('page') === 'product' ? pageUrl.searchParams.get('id') : null;
    if (ownId && !ids.includes(ownId)) ids.push(ownId);
    if (!ids.length) return;

    const data = await quoteJson(`payment_quote.php?ids=${encodeURIComponent(ids.join(','))}`);
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
  };

  const setSummaryRow = (row, label, amount) => {
    if (!row) return;
    const labelNode = row.querySelector('span');
    const amountNode = row.querySelector('b');
    if (labelNode) labelNode.textContent = label;
    if (amountNode) amountNode.textContent = amount;
  };

  const replaceCartTotal = async () => {
    const summary = document.querySelector('.cart-layout .order-summary');
    if (!summary) return;
    const data = await quoteJson('payment_cart_quote.php');
    if (!data?.status) return;
    const quote = data.quote;
    const rows = [...summary.children].filter((node) => node.matches?.('div'));
    if (rows.length < 3) return;

    setSummaryRow(rows[0], 'Items / recipient amount', quote.base);
    setSummaryRow(rows[1], 'Delivery', quote.delivery);
    setSummaryRow(rows[2], 'Total to pay', quote.total);

    const totalDivider = rows[2].previousElementSibling;
    for (const item of [
      ['service', 'Service charge', quote.service],
      ['paystack', 'Payment processing', quote.paystack],
    ]) {
      let row = summary.querySelector(`[data-payment-row="${item[0]}"]`);
      if (!row) {
        row = document.createElement('div');
        row.dataset.paymentRow = item[0];
        row.className = 'payment-line service';
        row.innerHTML = '<span></span><b></b>';
        totalDivider.before(row);
      }
      setSummaryRow(row, item[1], item[2]);
    }

    let note = summary.querySelector('.payment-note');
    if (!note) {
      note = document.createElement('p');
      note.className = 'payment-note';
      summary.querySelector('.button.wide')?.before(note);
    }
    note.textContent = 'The total includes payment processing and service charges. This is the final amount charged by Paystack.';
  };

  Promise.all([replaceProductPrices(), replaceCartTotal()]).catch(() => {});
});
