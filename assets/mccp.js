jQuery(document).ready(function($) {
  let invoice = document.querySelector('#invoice-app>.invoice');
  if (invoice) {
    let observer = new MutationObserver((mutationsList) => {
      for (let mutation of mutationsList) {
        const stats = ['expired', 'success'];
        if (mutation.attributeName === 'class' && stats.some(stat => invoice.className.includes(stat))) {
          setTimeout(function() {
            window.location.replace($('#mccp-invoice').attr('data-received-url'));
          }, 5000);
        }
      }
    });
    observer.observe(invoice, { attributes: true });
  }
});

jQuery( function($) {
  $( 'body' ).on( 'updated_checkout', function() {
    $('#mccp_currency').selectWoo({
      templateResult: function(coin) {
        if (!coin.id) return coin.text;
        let parts = coin.id.split('@');
        let network = (parts.length > 1 ) ? '<span class="coin-icon coin-icon__small ' + parts[1] + '"></span>' : '';
        let item = $('<div class="coin-wrapper"><span class="coin-icon ' + parts[0] + '">' + network + '</span><span> ' + coin.text + '</span></div>');
        return item;
      }
    });
  });
});
