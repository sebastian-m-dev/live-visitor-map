(function () {
    try {
        var cfg = window.lvmTracking || {};
        var restUrl = cfg.rest || '';
        var pixelUrl = cfg.pixel || (window.location.origin + '/?lvm_track=1');
        var sessionId = localStorage.getItem('sm_session_id');
        if (!sessionId) {
            sessionId = 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            try { localStorage.setItem('sm_session_id', sessionId); } catch (e) {}
        }

        var lastPing = 0;

        function pixel(url) {
            try { new Image().src = url + '&_=' + Date.now(); } catch (e) {}
        }

        function postTrack(action, data) {
            try {
                var url = restUrl + action;
                var body = JSON.stringify(data);
                fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true })
                    .catch(function () {
                        var params = 'lvm_track=1&session_id=' + encodeURIComponent(sessionId) + '&url=' + encodeURIComponent(window.location.href) + '&ref=' + encodeURIComponent(document.referrer || '');
                        pixel(pixelUrl.replace('lvm_track=1', '') + params);
                    });
            } catch (e) {
                var params = 'lvm_track=1&session_id=' + encodeURIComponent(sessionId) + '&url=' + encodeURIComponent(window.location.href) + '&ref=' + encodeURIComponent(document.referrer || '');
                pixel(pixelUrl.replace('lvm_track=1', '') + params);
            }
        }

        function sendTrack() {
            postTrack('track', { session_id: sessionId, page_url: window.location.href, referrer: document.referrer || '' });
        }

        function sendHeartbeat() {
            var now = Date.now();
            if (now - lastPing < 15000) return;
            lastPing = now;
            try {
                var url = restUrl + 'heartbeat';
                fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ session_id: sessionId }), keepalive: true })
                    .catch(function () {
                        pixel(pixelUrl.replace('lvm_track=1', 'lvm_heartbeat=1') + '&session_id=' + encodeURIComponent(sessionId));
                    });
            } catch (e) {
                pixel(pixelUrl.replace('lvm_track=1', 'lvm_heartbeat=1') + '&session_id=' + encodeURIComponent(sessionId));
            }
        }

        if (document.readyState === 'complete') {
            sendTrack();
        } else {
            window.addEventListener('load', sendTrack);
        }

        setInterval(sendHeartbeat, 20000);

        var origPushState = history.pushState;
        var origReplaceState = history.replaceState;
        history.pushState = function () {
            origPushState.apply(this, arguments);
            setTimeout(sendTrack, 500);
        };
        history.replaceState = function () {
            origReplaceState.apply(this, arguments);
            setTimeout(sendTrack, 500);
        };
        window.addEventListener('popstate', function () { setTimeout(sendTrack, 500); });
    } catch (e) {}
})();
