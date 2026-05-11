(function ($, Drupal) {
  /**
   * Drupal behavior to open media files (PDF, images, DOCX) in a custom modal.
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
        var fileExt = String($(this).data('file-ext') || '').toLowerCase();
        var fileName = String($(this).data('file-name') || '');
        if (!modalUrl) {
          console.error('data-view-url is undefined.');
          return;
        }

        // Use a dedicated container to avoid interfering with Drupal core dialogs (#drupal-modal).
        var repModal = document.getElementById('rep-webdoc-modal');
        if (!repModal) {
          repModal = document.createElement('div');
          repModal.id = 'rep-webdoc-modal';
          document.body.appendChild(repModal);
        }
        repModal.innerHTML = '';

        // Configure PDF.js worker source.
        if (typeof pdfjsLib !== 'undefined' && settings.webdoc_modal && settings.webdoc_modal.baseUrl) {
          pdfjsLib.GlobalWorkerOptions.workerSrc =
            settings.webdoc_modal.baseUrl + '/modules/custom/rep/js/pdf.worker.min.js';
        }

        function normalizeType(contentType) {
          var normalized = String(contentType || '').toLowerCase();
          if (normalized.indexOf(';') !== -1) {
            normalized = normalized.split(';')[0];
          }
          if (normalized && normalized !== 'application/octet-stream') {
            return normalized;
          }

          if (fileExt === 'pdf') {
            return 'application/pdf';
          }
          if (fileExt === 'doc') {
            return 'application/msword';
          }
          if (fileExt === 'docx') {
            return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
          }
          if (fileExt === 'png') {
            return 'image/png';
          }
          if (fileExt === 'jpg' || fileExt === 'jpeg') {
            return 'image/jpeg';
          }
          if (fileExt === 'gif') {
            return 'image/gif';
          }
          if (fileExt === 'webp') {
            return 'image/webp';
          }
          if (fileExt === 'svg') {
            return 'image/svg+xml';
          }

          return normalized || 'application/octet-stream';
        }

        function showActionFallback(message) {
          var media = repModal.querySelector('.rep-webdoc-modal__media');
          media.innerHTML = '';

          var msg = document.createElement('p');
          msg.textContent = message;
          media.appendChild(msg);

          if (String(modalUrl).indexOf('data:') === 0) {
            return;
          }

          var actions = document.createElement('p');
          var openLink = document.createElement('a');
          openLink.href = modalUrl;
          openLink.target = '_blank';
          openLink.rel = 'noopener noreferrer';
          openLink.textContent = 'Open file';

          var separator = document.createTextNode(' | ');

          var downloadLink = document.createElement('a');
          downloadLink.href = modalUrl;
          downloadLink.setAttribute('download', fileName || 'document');
          downloadLink.textContent = 'Download file';

          actions.appendChild(openLink);
          actions.appendChild(separator);
          actions.appendChild(downloadLink);
          media.appendChild(actions);
        }

        function renderImage(arrayBuffer, contentType) {
          var blob = new Blob([arrayBuffer], { type: contentType || 'application/octet-stream' });
          var imgUrl = URL.createObjectURL(blob);
          var img = document.createElement('img');
          img.src = imgUrl;
          img.style.maxWidth = '100%';
          img.style.height = 'auto';

          repModal.querySelector('.rep-webdoc-modal__media').appendChild(img);

          // Revoke the object URL when modal closes to free memory.
          $(document).one('click', '.rep-webdoc-modal__close, .rep-webdoc-modal__backdrop', function () {
            URL.revokeObjectURL(imgUrl);
          });
        }

        function renderDOCX(arrayBuffer) {
          if (typeof mammoth === 'undefined' || typeof mammoth.convertToHtml !== 'function') {
            showActionFallback('Word preview is unavailable in this browser.');
            return;
          }

          mammoth.convertToHtml({ arrayBuffer: arrayBuffer }).then(function (result) {
            var media = repModal.querySelector('.rep-webdoc-modal__media');
            media.innerHTML = '<div class="rep-webdoc-modal__docx">' + result.value + '</div>';

            if (result.messages && result.messages.length) {
              var note = document.createElement('p');
              note.className = 'text-muted';
              note.textContent = 'Some Word formatting may be simplified in preview.';
              media.appendChild(note);
            }
          }).catch(function (error) {
            console.error('Error rendering DOCX:', error);
            showActionFallback('Error rendering Word document preview.');
          });
        }

        function decodeDataUri(dataUri) {
          var match = /^data:([^,]*?),(.*)$/i.exec(String(dataUri));
          if (!match) {
            return null;
          }

          var meta = match[1] || '';
          var payload = match[2] || '';
          var mime = meta.split(';')[0] || '';
          var isBase64 = meta.indexOf(';base64') !== -1;
          var binary;

          if (isBase64) {
            binary = atob(payload);
          } else {
            binary = decodeURIComponent(payload);
          }

          var len = binary.length;
          var bytes = new Uint8Array(len);
          for (var i = 0; i < len; i++) {
            bytes[i] = binary.charCodeAt(i);
          }

          return {
            buffer: bytes.buffer,
            mime: mime,
          };
        }

        function handleBinary(arrayBuffer, contentType) {
          var normalizedType = normalizeType(contentType);

          if (normalizedType.indexOf('pdf') !== -1) {
            renderPDF(arrayBuffer);
            return;
          }

          if (normalizedType.indexOf('image/') === 0) {
            renderImage(arrayBuffer, normalizedType);
            return;
          }

          if (normalizedType.indexOf('wordprocessingml.document') !== -1 || fileExt === 'docx') {
            renderDOCX(arrayBuffer);
            return;
          }

          if (normalizedType.indexOf('msword') !== -1 || fileExt === 'doc') {
            showActionFallback('Word .doc files cannot be previewed inline.');
            return;
          }

          showActionFallback('Preview unavailable for file type: ' + normalizedType);
        }

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
            repModal.querySelector('.rep-webdoc-modal__media').appendChild(container);
          }).catch(function (error) {
            console.error('Error loading PDF:', error);
            repModal.querySelector('.rep-webdoc-modal__media').innerHTML = '<p>Error loading PDF.</p>';
          });
        }

        // Build the HTML markup for the modal, with backdrop and content wrapper.
        var modalMarkup = "" +
          '<div class="rep-webdoc-modal__backdrop"></div>' +
          '<div class="rep-webdoc-modal__content">' +
            '<button type="button" class="rep-webdoc-modal__close" aria-label="Close">&times;</button>' +
            '<div class="rep-webdoc-modal__media" style="text-align:center; padding:1em;"></div>' +
          '</div>';

        // Inject the modal markup and display the modal.
        repModal.innerHTML = modalMarkup;
        repModal.style.display = 'block';

        // Legacy support for inline data URIs.
        if (String(modalUrl).indexOf('data:') === 0) {
          try {
            var decoded = decodeDataUri(modalUrl);
            if (!decoded || !decoded.buffer) {
              showActionFallback('Invalid inline document data.');
              return;
            }
            handleBinary(decoded.buffer, decoded.mime || '');
          } catch (err) {
            console.error('Error decoding data URI:', err);
            showActionFallback('Error decoding inline document data.');
          }
          return;
        }

        // Perform AJAX request to fetch file as binary data.
        $.ajax({
          url: modalUrl,
          method: 'GET',
          xhrFields: { responseType: 'arraybuffer' },
          success: function (response, status, xhr) {
            var contentType = xhr.getResponseHeader('Content-Type') || '';
            handleBinary(response, contentType);
          },
          error: function () {
            showActionFallback('Error loading file.');
          }
        });
      });

      // Bind close event on close button and backdrop to hide modal.
      $(document).off('click', '.rep-webdoc-modal__close, .rep-webdoc-modal__backdrop')
        .on('click', '.rep-webdoc-modal__close, .rep-webdoc-modal__backdrop', function (e) {
          e.preventDefault();
          var repModal = document.getElementById('rep-webdoc-modal');
          if (repModal) {
            repModal.style.display = 'none';
            repModal.innerHTML = '';
          }
        });
    }
  };
})(jQuery, Drupal);
