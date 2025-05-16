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
  
            const drupalModal = document.getElementById("drupal-modal");
            if (!drupalModal) {
              console.error("Modal container #drupal-modal não encontrado.");
              return;
            }
  
            const modalMarkup = `
              <div class="modal-content">
                <button id="modal-close" class="close-btn" type="button">&times;</button>
                <div id="terms-container">
                  <iframe src="${termsUrl}" width="100%" height="600px" style="border:none;"></iframe>
                </div>
              </div>
              <div class="my-modal-backdrop"></div>
            `;
  
            drupalModal.innerHTML = modalMarkup;
            drupalModal.style.display = "block";
          });
        });
  
        once('termsModalClose', 'body', context).forEach(function () {
          $(document).on('click', '#modal-close, .my-modal-backdrop', function (e) {
            e.preventDefault();
            const drupalModal = document.getElementById("drupal-modal");
            if (drupalModal) {
              drupalModal.style.display = "none";
              drupalModal.innerHTML = "";
            }
          });
        });
      }
    };
})(jQuery, Drupal);  