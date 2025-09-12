// Cache-warming prefetch for MainWP (no DOM swap)
// Targets only safe links.
// Opt-in: .mainwp-js-prefetch or [mainwp-data-warm]; Opt-out: [mainwp-data-no-warm]
(function ($) {
    // -------------------- Config --------------------
    const HOVER_DELAY_MS = 200;          // delay before hover prefetch
    const REWARM_TTL_MS = 5 * 60 * 1000;// re-warm same URL after 5 min
    const MAX_INFLIGHT = 3;            // throttle concurrent warms
    const MAX_BYTES = 1.5 * 1024 * 1024; // 1.5 MB content size limit

    // -------------------- State ---------------------
    const SEEN = new Map(); // url -> timestamp last warmed
    let inflight = 0;

    // -------------------- Helpers -------------------
    function canPrefetch() {
        const c = navigator.connection;
        if (c) {
            if (c.saveData) return false;
            if (['slow-2g', '2g'].includes(c.effectiveType)) return false;
        }
        return location.protocol.startsWith('http');
    }

    function isWarmableLink(a) {
        if (!a) return false;
        if (a.hasAttribute('download') || a.target === '_blank') return false;
        if (a.hasAttribute('mainwp-data-no-warm')) return false;

        const href = a.getAttribute('href');
        if (!href) return false;

        // Only warm explicitly opt-in links OR MainWP nav links
        const explicitlyOpted = a.classList.contains('mainwp-js-prefetch') || a.hasAttribute('mainwp-data-warm');
        const url = new URL(href, location.href);

        if (url.origin !== location.origin) return false;      // same-origin only
        if (url.hash && url.pathname === location.pathname) return false; // same-page anchor
        if (!explicitlyOpted) return false; // limit scope to MainWP

        // De-dupe within TTL
        const seenAt = SEEN.get(url.toString());
        if (seenAt && (Date.now() - seenAt) < REWARM_TTL_MS) return false;

        return true;
    }

    function markSeen(urlStr) {
        SEEN.set(urlStr, Date.now());
        // Clean out old entries occasionally
        if (SEEN.size > 200) {
            const now = Date.now();
            for (const [u, ts] of SEEN) if (now - ts > REWARM_TTL_MS) SEEN.delete(u);
        }
    }

    function prefetchViaLink(urlStr) {
        const l = document.createElement('link');
        l.rel = 'prefetch';
        l.href = urlStr;
        l.as = 'document';
        document.head.appendChild(l);
    }

    async function headOKAndSmall(urlStr) {
        // Try HEAD first to check Content-Length; if missing, allow.
        try {
            const res = await fetch(urlStr, {
                method: 'HEAD',
                credentials: 'same-origin',
                mode: 'same-origin',
                cache: 'default'
            });
            if (!res.ok) return false;
            const len = res.headers.get('content-length');
            if (!len) return true; // no info -> allow
            return parseInt(len, 10) <= MAX_BYTES;
        } catch {
            // HEAD may be blocked or not supported; allow rather than fail closed.
            return true;
        }
    }

    async function warm(urlStr) {
        if (inflight >= MAX_INFLIGHT) return;
        inflight++;
        try {
            // 1) Let the browser decide (best-effort)
            prefetchViaLink(urlStr);

            // 2) Skip very large pages
            const ok = await headOKAndSmall(urlStr);
            if (!ok) { markSeen(urlStr); return; }

            // 3) Fetch to warm the HTTP cache & connection with auth cookies
            await fetch(urlStr, {
                method: 'GET',
                credentials: 'same-origin',
                mode: 'same-origin',
                cache: 'default'
            }).catch(() => { /* ignore */ });

            markSeen(urlStr);
        } finally {
            inflight--;
        }
    }

    function scheduleHoverWarm(a) {
        const href = a.getAttribute('href');
        if (!href) return;
        const urlStr = new URL(href, location.href).toString();
        const t = setTimeout(() => warm(urlStr), HOVER_DELAY_MS);
        const cancel = () => clearTimeout(t);
        a.addEventListener('pointerleave', cancel, { once: true });
        a.addEventListener('focusout', cancel, { once: true });
    }

    function setupViewportWarm(root) {
        if (!('IntersectionObserver' in window)) return;
        const io = new IntersectionObserver((entries) => {
            entries.forEach((e) => {
                if (!e.isIntersecting) return;
                const a = e.target;
                if (!isWarmableLink(a)) return;
                const urlStr = new URL(a.getAttribute('href'), location.href).toString();
                io.unobserve(a);
                warm(urlStr);
            });
        }, { root: null, rootMargin: '200px', threshold: 0 });

        root.querySelectorAll('a.mainwp-js-prefetch, a[mainwp-data-warm]')
            .forEach((a) => { if (isWarmableLink(a)) io.observe(a); });
    }

    // -------------------- Boot ----------------------
    $(function () {
        if (!canPrefetch()) return;

        // Intent-based warm (hover/focus)
        document.addEventListener('pointerenter', (e) => {
            const target = e.target;
            if (!(target instanceof Element)) return; // skip if not an element
            const a = target.closest('a');
            if (!a || !isWarmableLink(a)) return;
            scheduleHoverWarm(a);
        }, true);
        document.addEventListener('focusin', (e) => {
            const a = e.target.closest('a');
            if (!isWarmableLink(a)) return;
            scheduleHoverWarm(a);
        }, true);

        // Touch warm
        document.addEventListener('touchstart', (e) => {
            const a = e.target.closest('a');
            if (!isWarmableLink(a)) return;
            const urlStr = new URL(a.getAttribute('href'), location.href).toString();
            warm(urlStr);
        }, { passive: true });

        // Viewport warm for persistent nav (e.g., sidebar)
        setupViewportWarm(document);
    });
})(jQuery);


function mainwp_forceReload(targetUrl) {
    // Use provided URL or fallback to current page
    const url = new URL(targetUrl || window.location.href, mainwpParams.admin_url_base);
    // Add nocache param
    url.searchParams.set("_mwpnocache", Date.now());
    // Navigate to URL (force reload from server)
    window.location.href = url.toString();
}

// Cleanup _mwpnocache param after page load
window.addEventListener("load", () => {
    if (jQuery('body.mainwp-ui-page').length) {
        const url = new URL(window.location.href);
        if (url.searchParams.has("_mwpnocache")) {
            url.searchParams.delete("_mwpnocache");
            window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ""));
        }
    }
});

