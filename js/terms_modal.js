(function ($, Drupal) {
    Drupal.behaviors.termsModalBehavior = {
      attach: function (context, settings) {
        once('termsModalBehavior', '.view-terms-button', context).forEach(function (button) {
          $(button).on('click', function (e) {
            e.preventDefault();
  
            const termsUrl = $(this).data("terms-url");
            if (!termsUrl) {
              console.error("data-terms-url is undefined.");
              return;
            }
  
            // Use a dedicated container to avoid interfering with Drupal core dialogs (#drupal-modal).
            let repModal = document.getElementById('rep-webdoc-modal');
            if (!repModal) {
              repModal = document.createElement('div');
              repModal.id = 'rep-webdoc-modal';
              document.body.appendChild(repModal);
            }
            repModal.innerHTML = '';
  
            const modalMarkup = `
              <div class="rep-webdoc-modal__backdrop"></div>
              <div class="rep-webdoc-modal__content">
                <button type="button" class="rep-webdoc-modal__close" aria-label="Close">&times;</button>
                <div class="rep-webdoc-modal__media">
                  <iframe src="${termsUrl}" width="100%" height="600px" style="border:none;"></iframe>
                </div>
              </div>
            `;

            repModal.innerHTML = modalMarkup;
            repModal.style.display = 'block';
          });
        });
  
        once('termsModalClose', 'body', context).forEach(function () {
          $(document).on('click', '.rep-webdoc-modal__close, .rep-webdoc-modal__backdrop', function (e) {
            e.preventDefault();
            const repModal = document.getElementById('rep-webdoc-modal');
            if (repModal) {
              repModal.style.display = 'none';
              repModal.innerHTML = '';
            }
          });
        });
      }
    };
})(jQuery, Drupal);