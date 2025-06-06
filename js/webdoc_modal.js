(function ($, Drupal) {
  /**
   * Drupal behavior to open various media files (PDFs, images) in a custom modal.
   */
  Drupal.behaviors.openModalBehavior = {
    attach: function (context, settings) {
      // Unbind previous click handlers to avoid duplicates when behaviors re-attach.
      $(document).off('click', '.view-media-button');

      // Bind click event to elements with class 'view-media-button'.
      $(document).on('click', '.view-media-button', function (e) {
        e.preventDefault();

        // Retrieve the URL to view from data attribute.
        var modalUrl = $(this).data('view-url');
        if (!modalUrl) {
          console.error('data-view-url is undefined.');
          return;
        }

        // Get the native Drupal modal container.
        var drupalModal = document.getElementById('drupal-modal');
        if (drupalModal) {
          // Clear any existing content in the modal.
          drupalModal.innerHTML = '';
        }

        // Configure PDF.js worker source.
        pdfjsLib.GlobalWorkerOptions.workerSrc =
          settings.webdoc_modal.baseUrl + '/modules/custom/rep/js/pdf.worker.min.js';

        /**
         * Render a PDF file using PDF.js
         * @param {ArrayBuffer} response - Binary data of the fetched PDF.
         */
        function renderPDF(response) {
          var pdfData = new Uint8Array(response);
          var loadingTask = pdfjsLib.getDocument({ data: pdfData });

          loadingTask.promise.then(function (pdf) {
            // Create a container for PDF pages.
            var container = document.createElement('div');
            container.className = 'pdf-pages-container';

            // Render each page onto a canvas.
            for (var i = 1; i <= pdf.numPages; i++) {
              pdf.getPage(i).then(function (page) {
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                var viewport = page.getViewport({ scale: 1.5 });
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                canvas.style.margin = '0 auto';

                // Render page into canvas context.
                page.render({ canvasContext: ctx, viewport: viewport });
                container.appendChild(canvas);
              });
            }

            // Append the pages to the media container.
            document.getElementById('media-container').appendChild(container);
          }).catch(function (error) {
            console.error('Error loading PDF:', error);
            document.getElementById('media-container').innerHTML = '<p>Error loading PDF.</p>';
          });
        }

        // Build the HTML markup for the modal, with backdrop and content wrapper.
        var modalMarkup = "" +
          '<div class="my-modal-backdrop"></div>' +
          '<div class="modal-content">' +
            '<button id="modal-close" class="close-btn" type="button">&times;</button>' +
            '<div id="media-container" style="text-align:center; padding:1em;"></div>' +
          '</div>';

        // Inject the modal markup and display the modal.
        if (drupalModal) {
          drupalModal.innerHTML = modalMarkup;
          drupalModal.style.display = 'block';
          // Scroll to top to ensure modal is visible.
          // window.scrollTo(0, 0);
        }

        // Perform AJAX request to fetch file as binary data.
        $.ajax({
          url: modalUrl,
          method: 'GET',
          xhrFields: { responseType: 'arraybuffer' },
          success: function (response, status, xhr) {
            var contentType = xhr.getResponseHeader('Content-Type') || '';

            if (contentType.indexOf('pdf') !== -1) {
              // If PDF, render via PDF.js.
              renderPDF(response);

            } else if (contentType.indexOf('image/') === 0) {
              // If image, create Blob and display via <img>.
              var blob = new Blob([response], { type: contentType });
              var imgUrl = URL.createObjectURL(blob);
              var img = document.createElement('img');
              img.src = imgUrl;
              img.style.maxWidth = '100%';
              img.style.height = 'auto';

              document.getElementById('media-container').appendChild(img);

              // Revoke the object URL when modal closes to free memory.
              $(document).one('click', '#modal-close, .my-modal-backdrop', function () {
                URL.revokeObjectURL(imgUrl);
              });

            } else {
              // For unsupported file types, show download link.
              document.getElementById('media-container').innerHTML =
                '<p>Unsupported file type: ' + contentType + '</p>';
            }
          },
          error: function () {
            // On error, provide a download link as fallback.
            document.getElementById('media-container').innerHTML =
              '<p>Error loading file. <a href="' + modalUrl + '" download>Click here to download</a>.</p>';
          }
        });
      });

      // Bind close event on close button and backdrop to hide modal.
      $(document).off('click', '#modal-close, .my-modal-backdrop')
        .on('click', '#modal-close, .my-modal-backdrop', function (e) {
          e.preventDefault();
          var drupalModal = document.getElementById('drupal-modal');
          if (drupalModal) {
            drupalModal.style.display = 'none';
            drupalModal.innerHTML = '';
          }
        });
    }
  };
})(jQuery, Drupal);
