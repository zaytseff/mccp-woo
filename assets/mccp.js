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
  function mccp_select2() {
    if ($('#mccp_currency').children().length > 1) {
      $('#mccp_currency').val('null').trigger('change');
    }
    $('#mccp_currency').selectWoo({
      minimumResultsForSearch: 6,
      templateResult: function(coin) {
        if (!coin.id) return coin.text;
        let parts = coin.id.split('@'); let network = (parts.length > 1 ) ? '<span class="coin-icon coin-icon__small ' + parts[1] + '"></span>' : '';
        let item = $('<div class="coin-wrapper"><span class="coin-icon ' + parts[0] + '">' + network + '</span><span> ' + coin.text + '</span></div>');
        return item;
      }});
  }
  $('body').on( 'updated_checkout', function() { mccp_select2() });
  mccp_select2();
});
