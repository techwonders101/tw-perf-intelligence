(function () {
    'use strict';
    var events = (window.twperfDelayConfig && window.twperfDelayConfig.events) || ['scroll', 'touchstart', 'click'];
    var loaded = false;
    var timer;
    function loadDelayed() {
        if (loaded) return;
        loaded = true;
        clearTimeout(timer);
        events.forEach(function (event) { window.removeEventListener(event, loadDelayed); });
        var queue = Array.from(document.querySelectorAll('template[data-twperf-delay]'));
        function next() {
            var placeholder = queue.shift();
            if (!placeholder) return;
            var template = document.createElement('template');
            try {
                // PHP base64 encodes UTF-8 bytes, not a JavaScript UTF-16 string.
                var bytes = Uint8Array.from(atob(placeholder.dataset.twperfTag), function (c) { return c.charCodeAt(0); });
                template.innerHTML = new TextDecoder().decode(bytes);
                var original = template.content.querySelector('script[src]');
                if (!original) { next(); return; }
                var script = document.createElement('script');
                Array.from(original.attributes).forEach(function (attribute) {
                    if (attribute.name !== 'async' && attribute.name !== 'defer') script.setAttribute(attribute.name, attribute.value);
                });
                script.async = false;
                // Preserve inline event handlers while advancing on success or failure.
                script.addEventListener('load', next, {once: true});
                script.addEventListener('error', next, {once: true});
                if (script.noModule && 'noModule' in HTMLScriptElement.prototype) {
                    placeholder.replaceWith(script);
                    next();
                } else {
                    placeholder.replaceWith(script);
                }
            } catch (error) {
                console.error('TW Perf could not restore a delayed script', error);
                next();
            }
        }
        next();
    }
    function ready() {
        events.forEach(function (event) { window.addEventListener(event, loadDelayed, {passive: true}); });
        timer = setTimeout(loadDelayed, 5000);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready, {once: true});
    else ready();
})();
