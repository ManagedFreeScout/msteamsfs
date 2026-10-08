var _msTeamsInitDone = false;
// Captured before setupLinkInterception() ever overwrites window.open below.
// handleLink()'s fallback must call THIS, never the live window.open, or it
// recurses into its own wrapper forever (card #232, finding F1).
var _msTeamsOriginalOpen = window.open;

(function() {
    // Only run inside an iframe (Teams)
    if (window.self === window.top) return;

    // FreeScout attachments (/storage/attachment/...?id=..&token=..) are
    // same-site, and inside the Teams tab a PDF is then blocked ("This page
    // has been blocked by Microsoft Edge"). FreeScout pages have no TeamsJS,
    // so links leave the tab via window.open(), and Teams only sends OTHER
    // sites to the browser; a same-site window.open() stays in the tab.
    // So open attachments through the hub (another site), which forwards the
    // browser to the attachment (card #260, v1.6.6). The attachment URL goes
    // in the #fragment, so its token never reaches the hub's server; the URL
    // carries its own token, so no FreeScout login is needed in the browser.
    function isAttachmentUrl(linkUrl) {
        return linkUrl.pathname.indexOf('/storage/attachment/') !== -1;
    }

    function hubAttachmentUrl(linkUrl) {
        const meta = document.querySelector('meta[name="msteamsfs-backend-url"]');
        const hub = meta && meta.getAttribute('content');
        if (!hub) return null;
        return hub.replace(/\/+$/, '') + '/teams/open-attachment#u=' + encodeURIComponent(linkUrl.href);
    }

    function handleLink(url) {
        try {
            let linkUrl = new URL(url, window.location.href);
            const currentHost = window.location.hostname;
            if (linkUrl.hostname === currentHost && isAttachmentUrl(linkUrl)) {
                const viaHub = hubAttachmentUrl(linkUrl);
                if (viaHub) linkUrl = new URL(viaHub);
            }
            // Same-domain links — navigate within iframe directly
            if (linkUrl.hostname === currentHost) {
                window.location.href = linkUrl.href;
                return;
            }
            // External links — use Teams SDK app.openLink()
            if (typeof microsoftTeams !== 'undefined' && microsoftTeams.app && microsoftTeams.app.openLink) {
                microsoftTeams.app.openLink(linkUrl.href);
            } else {
                // Fix (v1.5.3, card #232 F1): must call the ORIGINAL window.open, not
                // the wrapper installed in setupLinkInterception() below -- that wrapper
                // calls handleLink() again for a '_blank' target, which reaches this
                // exact branch again, recursing until the browser throws a RangeError
                // (silently swallowed by the catch below). Only hit when TeamsJS isn't
                // available on this page at all, so the openLink() branch above never runs.
                _msTeamsOriginalOpen.call(window, linkUrl.href, '_blank');
            }
        } catch(e) { }
    }

    function setupLinkInterception() {
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a[href]');
            if (!link || !link.href) return;
            // FreeScout's own core JS binds [data-trigger="modal"] links to its
            // native AJAX-modal-load flow in the bubble phase, after this
            // capture-phase listener would otherwise fire first. Some of those
            // links (e.g. Kanban's "Add Card") incidentally also carry
            // target="_blank" — intercepting them here pre-empts FreeScout's
            // modal loader entirely, so the modal's form submit handler never
            // gets bound and Save falls back to a native POST against a
            // GET-only route (405). Leave native modal triggers untouched.
            if (link.closest('[data-trigger="modal"]')) return;
            // Download links (e.g. the arrow next to an attachment) already
            // work inside Teams: the file downloads. Leave them native (1.6.6;
            // 1.6.5 caught them by their attachment URL).
            if (link.hasAttribute('download')) return;
            if (link.getAttribute('target') === '_blank') {
                e.preventDefault();
                handleLink(link.href);
                return;
            }
            // Links to another site WITHOUT target="_blank" (v1.6.3). FreeScout
            // core 9921987a (after 1.8.243) only marks external thread links
            // with target="_blank" server-side and no longer forces it on every
            // thread link in JS (processLinks() disabled), so e.g. an email link
            // with target="_self" or FreeScout's own wiki link would load inside
            // the Teams iframe and show "refused to connect". Open those via
            // handleLink() too; same-site links keep navigating in the iframe,
            // except attachments (v1.6.6, see hubAttachmentUrl()).
            let url;
            try { url = new URL(link.href, window.location.href); } catch (err) { return; }
            if ((url.protocol === 'http:' || url.protocol === 'https:') &&
                (url.hostname !== window.location.hostname || isAttachmentUrl(url))) {
                e.preventDefault();
                handleLink(url.href);
            }
        }, true);

        window.open = function(url, target, features) {
            if (url && (target === '_blank' || target === undefined || target === null)) {
                handleLink(url);
                return null;
            }
            return _msTeamsOriginalOpen.apply(this, arguments);
        };

        // Review S3 (card #271): a GET form opens in the browser like a link,
        // without FreeScout's CSRF _token; any other method stays inside the tab,
        // so a POST body (and its token) never ends up in a URL or browser history.
        document.addEventListener('submit', function(e) {
            const form = e.target.closest('form[target="_blank"]');
            if (!form) return;
            if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') {
                form.setAttribute('target', '_self');
                return;
            }
            e.preventDefault();
            const data = new FormData(form);
            data.delete('_token');
            const action = form.action || window.location.href;
            const params = new URLSearchParams(data).toString();
            const url = params ? action + (action.indexOf('?') === -1 ? '?' : '&') + params : action;
            handleLink(url);
        }, true);
    }

    if (typeof microsoftTeams !== 'undefined' && !_msTeamsInitDone) {
        _msTeamsInitDone = true;
        microsoftTeams.app.initialize().then(function() {
            setupLinkInterception();
        });
    } else {
        setupLinkInterception();
    }

    // EXPERIMENTAL (v1.2.6) — not a confirmed fix, see README changelog.
    //
    // Targets the Teams-Android stale-page-on-resume bug: reopening the Teams
    // Android app after it was killed/frozen can show a stale/broken cached
    // page (FreeScout's generic 404) inside this iframe, recoverable only via
    // "Go to Homepage". Confirmed via debug logging that this never reaches
    // TeamsSsoController::handoff() at all -- something is serving a stale
    // page before our SSO code ever runs. Teams' own official app-lifecycle
    // resume handler (app.lifecycle.registerOnResumeHandler) explicitly does
    // NOT support Android (Microsoft's own docs: Desktop/iOS only), so that
    // official channel isn't available for this exact platform.
    //
    // pageshow + event.persisted is a real, current, still-correct mechanism
    // (MDN: Baseline widely available since 2015, unrelated to and unchanged
    // by the Android-support gap above) for detecting exactly this class of
    // restoration -- MDN explicitly lists "restoring a frozen page on mobile
    // OSes" as one of the scenarios that fires pageshow. Verified before
    // shipping (see README) that this is NOT triggered by mundane tab
    // switching or losing focus -- pageshow/pagehide are tied to actual
    // freeze/bfcache-restore transitions, not visibility changes, per MDN and
    // the WICG Page Lifecycle spec (FROZEN requires "system initiated CPU
    // suspension", not merely losing focus). Scoped to inside-the-Teams-
    // iframe only (same top-level guard as the rest of this file) since a
    // forced reload on a normal desktop browser tab restored from bfcache
    // would defeat the point of bfcache for everyone else.
    //
    // Guarded against the failure mode explicitly asked about: forcing a
    // reload the instant an in-progress reply/note/Kanban card exists would
    // be worse than the bug it's fixing. Skips the reload if any visible
    // Summernote editor or plain text input/textarea still has unsaved
    // content -- a real, worse cost (silent data loss) than occasionally not
    // self-healing a stale page.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;

        var hasUnsavedInput = false;
        document.querySelectorAll('.note-editable[contenteditable="true"]').forEach(function (el) {
            if (el.textContent && el.textContent.trim().length > 0) hasUnsavedInput = true;
        });
        if (!hasUnsavedInput) {
            document.querySelectorAll('textarea, input[type="text"]').forEach(function (el) {
                if (el.value && el.value.trim().length > 0) hasUnsavedInput = true;
            });
        }

        if (hasUnsavedInput) {
            console.warn('[MSTeamsFS] pageshow persisted=true but unsaved input detected — skipping forced reload to avoid discarding it');
            return;
        }

        console.log('[MSTeamsFS] pageshow persisted=true — forcing reload to recover from a possibly stale/frozen page');
        window.location.reload();
    });
})();

// License management — used by settings page only
function manageMSTeamsLicense(action) {
    var btn = $('#btn-' + action + '-license');
    var originalText = btn.text();
    btn.text('Processing...').prop('disabled', true);

    var licenseKey = $('#msteamssso-license-key').val();
    var url = $('#msteamssso-license-form').data('action-url');
    var csrf = $('#msteamssso-license-form').data('csrf');

    $.ajax({
        url: url,
        type: 'POST',
        data: {
            action: action,
            license_key: licenseKey,
            _token: csrf
        },
        success: function (response) {
            if (response.status == 'success') {
                window.location.reload();
            } else {
                alert(response.message);
                btn.text(originalText).prop('disabled', false);
            }
        },
        error: function () {
            alert('An error occurred. Please try again.');
            btn.text(originalText).prop('disabled', false);
        }
    });
}
