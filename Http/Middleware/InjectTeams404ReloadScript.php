<?php

namespace Modules\MSTeamsFS\Http\Middleware;

use Closure;
use Throwable;

/**
 * EXPERIMENTAL (v1.2.7) — Teams-Android stale-page-on-resume workaround.
 * Not a confirmed fix yet. See MSTeamsFS README changelog for full context.
 *
 * Reopening the Teams Android app after it's been killed/frozen can show
 * FreeScout's generic 404 page inside the tab (a real, matched-but-stale
 * conversationId, or some other unmatched request Teams replays from its own
 * cache). The v1.2.6 attempt tried to catch this via the browser's own
 * pageshow/persisted lifecycle event in msteamsfs.js — confirmed by Rutger
 * NOT reliable on this specific Android/Teams WebView.
 *
 * This version detects the actual broken state directly instead of inferring
 * it from timing: it's global middleware (registered in
 * MSTeamsFSServiceProvider::boot(), not scoped to the 'web' middleware
 * group), so it sees every response regardless of whether a route matched at
 * all. Deliberately NOT done by editing resources/views/errors/404.blade.php
 * directly — that's a genuine FreeScout core file, not part of this module,
 * and would silently get wiped by any FreeScout self-update or even survive
 * this module's own reinstall (our zip only ever touches
 * Modules/MSTeamsFS/). Confirmed FreeScout's error-page layout
 * (vendor/laravel/framework/.../Exceptions/views/layout.blade.php) has no
 * javascripts/stylesheets Eventy filter hook at all — msteamsfs.js can never
 * run on that page, hence intercepting the response here instead.
 */
class InjectTeams404ReloadScript
{
    public function handle($request, Closure $next)
    {
        // BUG FOUND LIVE (post-1.2.7 verification, before Rutger's install went
        // live): a genuine 404 (an unmatched route, or e.g.
        // Conversation::findOrFail() -> ModelNotFoundException ->
        // NotFoundHttpException) is THROWN, not returned, from $next($request)
        // -- it propagates straight past this (or any) middleware's
        // post-$next() code, all the way up to Kernel::handle()'s own
        // try/catch, which sits OUTSIDE the entire middleware pipeline
        // (confirmed directly against vendor/laravel/framework's actual
        // Kernel.php: the try/catch wraps sendRequestThroughRouter(), which is
        // what builds and runs the middleware Pipeline in the first place).
        // The original version of this method never saw the 404 at all for
        // that reason -- confirmed live: no exception, no crash, just silent
        // no-op, which is why it looked like it "worked" (no errors) while
        // actually doing nothing.
        //
        // Fix: catch the exception here and render it ourselves via the
        // app's real exception handler -- the exact same call
        // Kernel::renderException() makes -- so we get the real Response to
        // inspect/modify, instead of letting it skip past us.
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Catching broadly here (any Throwable, not just 404s) means we'd
            // otherwise silently swallow Laravel's own exception *reporting*
            // (logging) step too -- Kernel::handle()'s own catch block calls
            // both reportException() and renderException(); since we now
            // catch the exception before it ever reaches that outer catch,
            // Kernel::handle() never gets a chance to log it. Call report()
            // ourselves first so genuine bugs (not just 404s, which were
            // never logged anyway per Laravel's own internalDontReport list)
            // don't silently stop being logged because of this middleware.
            $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
            $handler->report($e);
            $response = $handler->render($request, $e);
        }

        // Only ever act on a genuine 404 response — never touch anything else.
        if (!method_exists($response, 'getStatusCode') || $response->getStatusCode() != 404) {
            return $response;
        }

        // BUG FOUND LIVE (post-fix-#1 verification): checking the Content-Type
        // header here is unreliable and was silently defeating this middleware
        // even after the exception-handling fix above. Confirmed directly via
        // a real Kernel::handle() cycle on the live install: at this point in
        // the pipeline the header comes back completely empty, not
        // "text/html" -- Symfony's Response::prepare() (which fills in
        // sensible header defaults, including Content-Type) runs AFTER the
        // middleware pipeline finishes, not before, so nothing has set it yet
        // when this code runs. Relying on it here meant this middleware
        // never once fired on a real request. Dropped the header check
        // entirely and rely solely on the presence of a real `</body>` tag in
        // the content -- that's already a strictly stronger, more direct
        // signal that this is actual injectable HTML (a JSON error payload
        // will never contain that literal string), and it doesn't depend on
        // response-lifecycle timing at all.
        $content = $response->getContent();
        if (!is_string($content) || stripos($content, '</body>') === false) {
            return $response;
        }

        $response->setContent(str_ireplace('</body>', $this->buildScript() . '</body>', $content));

        return $response;
    }

    private function buildScript()
    {
        // CSP does not currently appear to be enforced on this error page at all
        // (no meta tag, and FreeScout core's own CSP header line in
        // ResponseHeaders.php is commented out) — the nonce is added anyway,
        // cheaply, as defense-in-depth in case that ever changes, matching the
        // pattern already used elsewhere in this module (Helper::cspNonceAttr()).
        $nonce = \Helper::cspNonceAttr();

        return <<<HTML
<script{$nonce}>
(function () {
    // Only act inside the Teams iframe — never touch a normal browser 404.
    if (window.self === window.top) return;

    var STORAGE_KEY = 'msteamsfs_404_reload_attempted';
    var currentUrl = window.location.href;

    try {
        if (window.sessionStorage && sessionStorage.getItem(STORAGE_KEY) === currentUrl) {
            // Already retried this exact stale URL once this session — stop
            // here rather than looping. Falls through to the normal
            // FreeScout 404 page with its own "Homepage" recovery link.
            return;
        }
    } catch (e) {
        // sessionStorage unavailable (e.g. a restrictive WebView mode) —
        // proceed without the loop guard; worst case is one extra reload.
    }

    // A genuine 404 page has no editable form content, so this should
    // essentially never trigger here — kept anyway per explicit instruction,
    // since a wrong assumption about that is a worse outcome than a
    // redundant check.
    var hasUnsavedInput = false;
    document.querySelectorAll('.note-editable[contenteditable="true"]').forEach(function (el) {
        if (el.textContent && el.textContent.trim().length > 0) hasUnsavedInput = true;
    });
    if (!hasUnsavedInput) {
        document.querySelectorAll('textarea, input[type="text"]').forEach(function (el) {
            if (el.value && el.value.trim().length > 0) hasUnsavedInput = true;
        });
    }
    if (hasUnsavedInput) return;

    try {
        if (window.sessionStorage) sessionStorage.setItem(STORAGE_KEY, currentUrl);
    } catch (e) {
        // ignore — see above
    }

    window.location.reload();
})();
</script>
HTML;
    }
}
