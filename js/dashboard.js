(function () {
    if (typeof Chart === 'undefined') return;

    function ensureLeaflet(cb, retries) {
        if (retries === undefined) retries = 0;
        if (typeof L !== 'undefined') { cb(); return; }
        if (retries > 30) return;
        setTimeout(function () { ensureLeaflet(cb, retries + 1); }, 200);
    }

    var root = document.getElementById('lvm-root');
    if (!root) return;
    var state = { data: null, hourlyChart: null, dailyChart: null, map: null, refreshInterval: null, mapReady: false, activeGroup: null, recentGroup: null, adminGroup: null, firstRender: true };
    var markers = { active: null, recent: null };

    function fmt(n) { return n.toLocaleString(); }

    function fmtTime(ts) {
        var d = new Date(ts + ' UTC');
        return d.toLocaleString();
    }

    function fmtDuration(s) {
        if (s < 60) return s + 's';
        var m = Math.floor(s / 60);
        var sec = s % 60;
        if (m < 60) return m + 'm ' + sec + 's';
        var h = Math.floor(m / 60);
        m = m % 60;
        return h + 'h ' + m + 'm';
    }

    function countryFlag(code) {
        if (!code || code === 'XX') return '';
        return code.toUpperCase().replace(/./g, function (c) {
            return String.fromCodePoint(0x1F1E6 + c.charCodeAt(0) - 65);
        });
    }

    function timeAgo(ts) {
        var diff = Date.now() - new Date(ts + ' UTC').getTime();
        var sec = Math.floor(diff / 1000);
        if (sec < 60) return sec + 's ago';
        var min = Math.floor(sec / 60);
        if (min < 60) return min + 'm ago';
        var h = Math.floor(min / 60);
        return h + 'h ago';
    }

    function renderCards(data) {
        var cards = [
            { title: 'Active Now', value: data.realtime.active_visitors, cls: 'active realtime' },
            { title: "Today's Visits", value: data.today.visits, cls: '' },
            { title: "Today's Unique", value: data.today.unique, cls: '' },
            { title: 'Today Sessions', value: data.today.sessions, cls: '' },
            { title: 'Avg Session', value: fmtDuration(data.today.avg_session_duration), cls: '' },
            { title: 'Total Visits', value: data.total.visits, cls: '' },
            { title: 'Total Unique', value: data.total.unique, cls: '' },
        ];
        var html = '<div class="lvm-grid">';
        cards.forEach(function (c, i) {
            var delay = state.firstRender ? 'animation-delay:' + (i * 80) + 'ms;' : '';
            html += '<div class="lvm-card" style="' + delay + '"><p class="lvm-card-title">' + c.title +
                '</p><p class="lvm-card-value ' + c.cls + '">' + c.value + '</p></div>';
        });
        html += '</div>';
        state.firstRender = false;
        return html;
    }

    function renderHourlyChart(data) {
        var hours = Object.keys(data.hourly);
        var visits = hours.map(function (h) { return data.hourly[h].visits; });
        var unique = hours.map(function (h) { return data.hourly[h].unique; });

        if (state.hourlyChart) state.hourlyChart.destroy();
        var ctx = document.getElementById('lvm-hourly-chart').getContext('2d');
        state.hourlyChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: hours.map(function (h) { return h + ':00'; }),
                datasets: [
                    { label: 'Visits', data: visits, backgroundColor: '#b39aff', borderRadius: 4 },
                    { label: 'Unique', data: unique, backgroundColor: '#646cff', borderRadius: 4 },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#98989f' }, position: 'top' } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0, color: '#98989f' }, grid: { color: '#3c3f44' } }, x: { grid: { display: false }, ticks: { color: '#98989f' } } }
            }
        });
    }

    function renderDailyChart(data) {
        if (!data.daily || !data.daily.length) return;
        var labels = data.daily.map(function (d) { return d.date; });
        var visits = data.daily.map(function (d) { return parseInt(d.visits); });
        var unique = data.daily.map(function (d) { return parseInt(d.unique_visits); });

        if (state.dailyChart) state.dailyChart.destroy();
        var ctx = document.getElementById('lvm-daily-chart').getContext('2d');
        state.dailyChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Visits', data: visits,
                        borderColor: '#b39aff', backgroundColor: 'rgba(179,154,255,.1)',
                        fill: true, tension: .3, pointRadius: 3,
                    },
                    {
                        label: 'Unique', data: unique,
                        borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,.1)',
                        fill: true, tension: .3, pointRadius: 3,
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#98989f' }, position: 'top' } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0, color: '#98989f' }, grid: { color: '#3c3f44' } }, x: { grid: { display: false }, ticks: { color: '#98989f' } } }
            }
        });
    }

    function renderCountries(countries) {
        if (!countries || !countries.length) return '<p>No data yet.</p>';
        var html = '<table class="lvm-table"><thead><tr><th>Country</th><th>Visits</th><th>Unique</th></tr></thead><tbody>';
        countries.forEach(function (c) {
            html += '<tr><td>' + countryFlag(c.country_code) + ' ' + c.country + '</td>' +
                '<td>' + fmt(c.visits) + '</td><td>' + fmt(c.unique_visits) + '</td></tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function renderRecent(recent) {
        if (!recent || !recent.length) return '<p>No data yet.</p>';
        var html = '<div class="lvm-recent-scroll"><table class="lvm-table"><thead><tr>' +
            '<th>Time</th><th>IP</th><th>Location</th><th>Page</th></tr></thead><tbody>';
        recent.forEach(function (r) {
            var loc = countryFlag(r.country_code) + ' ' + r.city + ', ' + r.country;
            if (r.city === 'Localhost') loc = 'Localhost';
            var page = r.page_url ? r.page_url.replace(/^https?:\/\/[^\/]+/, '') : '-';
            if (page.length > 50) page = page.substring(0, 50) + '...';
            html += '<tr><td>' + fmtTime(r.visit_time) + '</td>' +
                '<td><code>' + r.ip + '</code></td>' +
                '<td>' + loc + '</td>' +
                '<td title="' + (r.page_url || '') + '">' + page + '</td></tr>';
        });
        html += '</tbody></table></div>';
        return html;
    }

    function getMarkerIcon(isActive) {
        var color = isActive ? '#4dff8f' : '#2e4cff';
        var size = isActive ? 14 : 10;
        return L.divIcon({
            className: 'lvm-marker',
            html: '<div style="background:' + color + ';width:' + size + 'px;height:' + size + 'px;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.3);' +
                (isActive ? 'animation:pulse-marker 1.5s infinite;' : '') + '"></div>',
            iconSize: [size + 4, size + 4],
            iconAnchor: [(size + 4) / 2, (size + 4) / 2],
        });
    }

    function getAdminMarkerIcon() {
        return L.divIcon({
            className: 'lvm-marker',
            html: '<div style="display:flex;flex-direction:column;align-items:center"><div style="background:#b39aff;width:16px;height:16px;border-radius:50%;border:3px solid rgba(179,154,255,.5);box-shadow:0 0 12px rgba(179,154,255,.5),0 0 24px rgba(179,154,255,.25),0 1px 4px rgba(0,0,0,.5);animation:pulse-admin 1.5s infinite"></div><span style="background:#14121a;color:#fff;font-size:10px;padding:1px 6px;border-radius:4px;margin-top:2px;white-space:nowrap;border:1px solid #3c3f44">You</span></div>',
            iconSize: [26, 34],
            iconAnchor: [13, 34],
        });
    }

    function initMap() {
        if (state.mapReady) return;
        var mapEl = document.getElementById('lvm-map');
        if (!mapEl) return;

        state.map = L.map('lvm-map', {
            center: [20, 0],
            zoom: 2,
            zoomControl: true,
            attributionControl: false,
        });
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>, &copy; CartoDB'
        }).addTo(state.map);

        state.activeGroup = L.featureGroup().addTo(state.map);
        state.recentGroup = L.featureGroup().addTo(state.map);
        state.adminGroup = L.featureGroup().addTo(state.map);

        state.mapReady = true;
        setTimeout(function () { state.map.invalidateSize(); }, 100);
    }

    function renderMap(data) {
        if (typeof L === 'undefined') {
            console.warn('LVM: Leaflet not loaded');
            return;
        }

        initMap();
        if (!state.map) {
            setTimeout(function () { renderMap(data); }, 200);
            return;
        }

        state.activeGroup.clearLayers();
        state.recentGroup.clearLayers();
        state.adminGroup.clearLayers();

        var bounds = [];

        if (data.map_active && data.map_active.length) {
            data.map_active.forEach(function (v) {
                var lat = parseFloat(v.latitude);
                var lng = parseFloat(v.longitude);
                if (!lat && lat !== 0) return;
                bounds.push([lat, lng]);
                var page = v.page_url ? v.page_url.replace(/^https?:\/\/[^\/]+/, '') : '-';
                var popup = '<div class="lvm-popup">' +
                    '<strong style="color:#4dff8f">● Active Now</strong><br>' +
                    '<b>IP:</b> ' + v.ip + '<br>' +
                    '<b>Location:</b> ' + countryFlag(v.country_code) + ' ' + v.city + ', ' + v.country + '<br>' +
                    '<b>Page:</b> ' + page + '<br>' +
                    '<b>Views:</b> ' + v.page_views + '<br>' +
                    '<b>Last ping:</b> ' + timeAgo(v.last_visit) +
                    '</div>';
                var m = L.marker([lat, lng], { icon: getMarkerIcon(true) }).bindPopup(popup);
                state.activeGroup.addLayer(m);
            });
        }

        if (data.map_24h && data.map_24h.length) {
            data.map_24h.forEach(function (v) {
                var lat = parseFloat(v.latitude);
                var lng = parseFloat(v.longitude);
                if (!lat && lat !== 0) return;
                bounds.push([lat, lng]);
                var page = v.page_url ? v.page_url.replace(/^https?:\/\/[^\/]+/, '') : '-';
                var popup = '<div class="lvm-popup">' +
                    '<b>IP:</b> ' + v.ip + '<br>' +
                    '<b>Location:</b> ' + countryFlag(v.country_code) + ' ' + v.city + ', ' + v.country + '<br>' +
                    '<b>Page:</b> ' + page + '<br>' +
                    '<b>Time:</b> ' + fmtTime(v.visit_time) +
                    '</div>';
                var m = L.marker([lat, lng], { icon: getMarkerIcon(false) }).bindPopup(popup);
                state.recentGroup.addLayer(m);
            });
        }

        if (lvmData.admin_lat && lvmData.admin_lon) {
            var aLat = parseFloat(lvmData.admin_lat);
            var aLon = parseFloat(lvmData.admin_lon);
            bounds.push([aLat, aLon]);
            var popup = '<div class="lvm-popup">' +
                '<strong style="color:#b39aff">● You</strong><br>' +
                '<b>IP:</b> ' + lvmData.admin_ip + '<br>' +
                '<b>Location:</b> ' + countryFlag(lvmData.admin_country_code) + ' ' + lvmData.admin_city + ', ' + lvmData.admin_country +
                '</div>';
            var m = L.marker([aLat, aLon], { icon: getAdminMarkerIcon() }).bindPopup(popup);
            state.adminGroup.addLayer(m);
        }

        if (bounds.length) {
            try {
                state.map.fitBounds(bounds, { padding: [30, 30], maxZoom: 10 });
            } catch (e) {}
        }

        setTimeout(function () { state.map.invalidateSize(); }, 100);
    }

    function renderDashboard(data) {
        var mapSection = document.getElementById('lvm-map-section');

        if (!mapSection) {
            var html = '';
            html += '<div id="lvm-map-section" class="lvm-chart-wrapper" style="padding:0;overflow:hidden;margin-bottom:24px">';
            html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px 0">';
            html += '<h2 style="margin:0;font-size:16px;color:#fff;font-weight:400">Live Map</h2>';
            html += '<div style="font-size:12px;color:#98989f;display:flex;gap:16px">';
            html += '<span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#4dff8f;margin-right:4px;vertical-align:middle;box-shadow:0 0 6px #4dff8f"></span> Active Now</span>';
            html += '<span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#2e4cff;margin-right:4px;vertical-align:middle"></span> Last 24h</span>';
            html += '<span style="display:flex;align-items:center;gap:4px"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#b39aff;border:2px solid rgba(179,154,255,.5);box-shadow:0 0 6px rgba(179,154,255,.5)"></span> You</span>';
            html += '</div></div>';
            html += '<div id="lvm-map" data-lvm-map style="height:500px;width:100%;display:block!important"></div></div>';
            html += '<div id="lvm-cards-section">' + renderCards(data) + '</div>';
            html += '<div id="lvm-charts-section" class="lvm-two-col">';
            html += '<div class="lvm-chart-wrapper"><h2>Today by Hour</h2><div class="lvm-chart-container"><canvas id="lvm-hourly-chart"></canvas></div></div>';
            html += '<div class="lvm-chart-wrapper" id="lvm-countries-section"><h2>Top Countries Today</h2>' + renderCountries(data.countries) + '</div>';
            html += '</div>';
            html += '<div class="lvm-chart-wrapper" id="lvm-daily-section"><h2>Last 30 Days</h2><div class="lvm-chart-container"><canvas id="lvm-daily-chart"></canvas></div></div>';
            html += '<div class="lvm-chart-wrapper" id="lvm-recent-section"><h2>Recent Visits</h2>' + renderRecent(data.recent) + '</div>';
            root.innerHTML = html;
        } else {
            document.getElementById('lvm-cards-section').innerHTML = renderCards(data);
            document.getElementById('lvm-countries-section').innerHTML = '<h2>Top Countries Today</h2>' + renderCountries(data.countries);
            document.getElementById('lvm-recent-section').innerHTML = '<h2>Recent Visits</h2>' + renderRecent(data.recent);
        }

        setTimeout(function () {
            try { renderHourlyChart(data); } catch (e) {}
            try { renderDailyChart(data); } catch (e) {}
            ensureLeaflet(function () {
                try { renderMap(data); } catch (e) {}
            });
        }, 50);
    }

    function fetchData() {
        fetch(lvmData.rest_url, {
            headers: { 'X-WP-Nonce': lvmData.nonce }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                state.data = data;
                renderDashboard(data);
            })
            .catch(function (err) {
                console.error('LVM: fetch error', err);
            });
    }

    function startRealtime() {
        fetchData();
        state.refreshInterval = setInterval(fetchData, 15000);
    }

    startRealtime();
})();
