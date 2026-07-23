var _msTeamsInitDone = false;

(function() {
    // Only run inside an iframe (Teams)
    if (window.self === window.top) return;

    function handleLink(url) {
        try {
            const linkUrl = new URL(url, window.location.href);
            const currentHost = window.location.hostname;
            // Same-domain links — navigate within iframe directly
            if (linkUrl.hostname === currentHost) {
                window.location.href = linkUrl.href;
                return;
            }
            // External links — use Teams SDK app.openLink()
            if (typeof microsoftTeams !== 'undefined' && microsoftTeams.app && microsoftTeams.app.openLink) {
                microsoftTeams.app.openLink(linkUrl.href);
            } else {
                window.open(linkUrl.href, '_blank');
            }
        } catch(e) { }
    }

    function setupLinkInterception() {
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a[target="_blank"]');
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
            e.preventDefault();
            handleLink(link.href);
        }, true);

        const originalOpen = window.open;
        window.open = function(url, target, features) {
            if (url && (target === '_blank' || target === undefined || target === null)) {
                handleLink(url);
                return null;
            }
            return originalOpen.apply(this, arguments);
        };

        document.addEventListener('submit', function(e) {
            const form = e.target.closest('form[target="_blank"]');
            if (!form) return;
            e.preventDefault();
            const action = form.action || window.location.href;
            const params = new URLSearchParams(new FormData(form)).toString();
            const url = params ? action + '?' + params : action;
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
