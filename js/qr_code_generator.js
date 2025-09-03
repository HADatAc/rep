(function (Drupal, once) {
  Drupal.behaviors.qrCodeGenerator = {
    attach: function (context, settings) {
      const elements = once('qr-code-processed', '#qr-output', context);
      elements.forEach(function (el) {
        const uri = el.dataset.uri;

        if (!uri || !/^https?:\/\//.test(uri)) {
          console.error("Invalid or missing URI for QR code.");
          return;
        }

        console.log("Generating QR Code for:", uri);

        if (typeof QRCode === 'undefined') {
          console.error("QRCode library not loaded.");
          return;
        }

        new QRCode(el, {
          text: uri,
          width: 128,
          height: 128,
        });
      });
    }
  };
})(Drupal, once);