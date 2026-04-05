document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var textarea = document.querySelector('textarea[name="additional_prompt"]');
    if (!textarea) {
        return;
    }

    // Fetch base prompt and translations, then build UI
    var baseUrl = window.location.href.split('?')[0];
    fetch(baseUrl + '?action=get-base-prompt', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var i18n = data.i18n || {};
            buildPromptButton(textarea, data.prompt || '', i18n);
        })
        .catch(function () {
            // Silently fail — button just won't appear
        });

    function buildPromptButton(el, promptText, i18n) {
        // Create "View base prompt" button before the textarea
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-secondary btn-sm mb-2';
        btn.innerHTML = '<i class="fa-solid fa-eye me-1"></i>' + (i18n.view || 'View base prompt');
        el.parentNode.insertBefore(btn, el);

        // Create modal
        var modalId = 'basePromptModal';
        var modalHtml =
            '<div class="modal fade" id="' + modalId + '" tabindex="-1">' +
            '  <div class="modal-dialog modal-xl modal-dialog-scrollable">' +
            '    <div class="modal-content">' +
            '      <div class="modal-header">' +
            '        <h5 class="modal-title"><i class="fa-solid fa-comment-dots me-1"></i>' +
                       (i18n.title || 'Base extraction prompt') + '</h5>' +
            '        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
            '      </div>' +
            '      <div class="modal-body">' +
            '        <pre class="bg-light p-3 rounded" ' +
            '             style="white-space:pre-wrap;word-wrap:break-word;max-height:70vh;overflow-y:auto;font-size:0.85rem;">' +
                       escapeHtml(promptText) +
            '        </pre>' +
            '      </div>' +
            '      <div class="modal-footer">' +
            '        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' +
                       (i18n.close || 'Close') + '</button>' +
            '      </div>' +
            '    </div>' +
            '  </div>' +
            '</div>';

        var container = document.createElement('div');
        container.innerHTML = modalHtml;
        document.body.appendChild(container.firstElementChild);

        btn.addEventListener('click', function () {
            new bootstrap.Modal(document.getElementById(modalId)).show();
        });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
});
