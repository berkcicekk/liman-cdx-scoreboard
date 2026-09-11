(function () {
    'use strict';
    const root = document.getElementById('ls-scoreboard');
    if (!root) return;
    if (window.lsScoreboardCleanup) window.lsScoreboardCleanup();
    let disposed = false, busy = false, chart = null, failureCount = 0;
    const el = role => root.querySelector('[data-role="' + role + '"]');
    const fmt = n => Number(n || 0).toLocaleString('tr-TR', {maximumFractionDigits: 2});
    const date = value => new Date(value).toLocaleString('tr-TR');
    const cell = (tag, value) => { const node = document.createElement(tag); node.textContent = value; return node; };
    const states = {up:'Ayakta', down:'Kesinti', stale:'Ölçüm gecikti', unknown:'Ölçüm yok', pending:'Başlamadı', ended:'Tamamlandı'};
    function error(message) { el('error').textContent = message; el('error').hidden = !message; }
    function api(url, data, success, fail) {
        try {
            window.request(url, data, raw => {
                if (disposed) return;
                try {
                    const response = typeof raw === 'string' ? JSON.parse(raw) : raw;
                    if (response.status !== 200) throw new Error(typeof response.message === 'string' ? response.message : 'İşlem başarısız.');
                    success(response.message);
                } catch (e) { fail(e.message || 'Sunucu yanıtı okunamadı.'); }
            }, raw => {
                if (disposed) return;
                let message = 'Liman bağlantısı başarısız. Son veriler gösteriliyor.';
                try { const r = typeof raw === 'string' ? JSON.parse(raw) : raw; if (typeof r.message === 'string') message = r.message; } catch (_) {}
                fail(message);
            });
        } catch (_) { fail('Liman request() yardımcısı kullanılamıyor.'); }
    }
    function teamLabel(team) {
        const fragment = document.createDocumentFragment();
        const dot = document.createElement('span'); dot.className = 'ls-team-dot';
        dot.style.backgroundColor = /^#[0-9a-f]{6}$/i.test(team.color) ? team.color : '#36a3ff';
        fragment.append(dot, document.createTextNode(team.name)); return fragment;
    }
    function render(data) {
        el('connection').textContent = 'CANLI';
        el('updated').textContent = 'Son güncelleme: ' + date(data.generated_at);
        el('team-count').textContent = data.teams.length;
        el('up-count').textContent = data.services.filter(s => s.status === 'up').length;
        el('down-count').textContent = data.services.filter(s => s.status === 'down').length;
        el('unknown-count').textContent = data.services.filter(s => ['unknown','stale','pending'].includes(s.status)).length;
        el('empty').hidden = data.teams.length !== 0;
        el('ranking').replaceChildren();
        data.teams.forEach((team, i) => {
            const row = document.createElement('tr'); row.append(cell('td', String(i + 1)));
            const name = document.createElement('td'); name.append(teamLabel(team)); row.append(name);
            row.append(cell('td', fmt(team.sla_score)), cell('td', fmt(team.inject_score)), cell('td', fmt(team.security_score)), cell('td', fmt(team.score)));
            el('ranking').append(row);
        });
        const names = [...new Set(data.services.map(s => s.name))].sort((a,b) => a.localeCompare(b, 'tr'));
        const heading = document.createElement('tr'); heading.append(cell('th','Takım / Servis')); names.forEach(n => heading.append(cell('th',n)));
        el('matrix-head').replaceChildren(heading); el('matrix-body').replaceChildren();
        const byTeam = new Map(data.services.map(s => [s.team_id + ':' + s.name,s]));
        data.teams.forEach(team => {
            const row = document.createElement('tr'), label = document.createElement('td'); label.append(teamLabel(team)); row.append(label);
            names.forEach(name => {
                const td = document.createElement('td'), service = byTeam.get(team.id + ':' + name);
                if (service) {
                    const badge = document.createElement('span'); badge.className = 'ls-status ls-status-' + service.status; badge.tabIndex = 0;
                    badge.textContent = states[service.status] || 'Bilinmiyor';
                    badge.append(cell('small', service.protocol + ' · SLA %' + fmt(service.sla_percent)));
                    badge.title = service.host + ':' + service.port + '\n' + (service.detail || 'Henüz ölçüm yok') + '\nGecikme: ' + fmt(service.latency_ms) + ' ms\nÖlçüm: ' + (service.checked_at ? date(service.checked_at) : '—') + '\nÖlçülemeyen: ' + fmt(service.unknown_seconds) + ' sn';
                    badge.setAttribute('aria-label', team.name + ', ' + name + ', ' + badge.textContent + ', ' + badge.title);
                    td.append(badge);
                } else td.textContent = '—';
                row.append(td);
            });
            el('matrix-body').append(row);
        });
        const vulnerabilityNames = [...new Set((data.vulnerabilities || []).map(v => v.name))].sort((a,b) => a.localeCompare(b, 'tr'));
        const vulnerabilityHeading = document.createElement('tr'); vulnerabilityHeading.append(cell('th','Takım / Denetim')); vulnerabilityNames.forEach(name => vulnerabilityHeading.append(cell('th',name)));
        el('vulnerability-head').replaceChildren(vulnerabilityHeading); el('vulnerability-body').replaceChildren();
        const vulnerabilityByTeam = new Map((data.vulnerabilities || []).map(v => [v.team_id + ':' + v.name,v]));
        data.teams.forEach(team => {
            const row = document.createElement('tr'), label = document.createElement('td'); label.append(teamLabel(team)); row.append(label);
            vulnerabilityNames.forEach(name => {
                const td = document.createElement('td'), vulnerability = vulnerabilityByTeam.get(team.id + ':' + name);
                if (vulnerability) {
                    const badge = document.createElement('span'); const patched = vulnerability.status === 'Patched';
                    badge.className = 'ls-status ' + (patched ? 'ls-vuln-patched' : 'ls-vuln-vulnerable'); badge.tabIndex = 0;
                    badge.textContent = patched ? 'Kapatıldı' : 'Açık';
                    badge.append(cell('small', vulnerability.check_type + ' · +' + fmt(vulnerability.points)));
                    badge.title = vulnerability.command + '\nSon denetim: ' + (vulnerability.last_checked_at ? date(vulnerability.last_checked_at) : '—') + '\nExit code: ' + (vulnerability.exit_code ?? '—') + '\nSüre: ' + fmt(vulnerability.duration_ms) + ' ms' + (vulnerability.last_output ? '\nÇıktı: ' + vulnerability.last_output : '');
                    td.append(badge);
                } else td.textContent = '—';
                row.append(td);
        });
        el('vulnerability-body').append(row);
        });
        el('vulnerability-logs').replaceChildren();
        (data.vulnerability_logs || []).forEach(log => {
            const row = document.createElement('tr');
            const state = cell('td', log.is_patched ? 'Kapatıldı' : 'Açık'); state.className = log.is_patched ? 'ls-positive' : 'ls-negative';
            row.append(cell('td',date(log.checked_at)),cell('td',log.team_name),cell('td',log.vulnerability_name),state,cell('td',log.exit_code ?? 'Zaman aşımı'),cell('td',fmt(log.duration_ms) + ' ms'),cell('td',log.output || '—'));
            el('vulnerability-logs').append(row);
        });
        if (!(data.vulnerability_logs || []).length) { const row = document.createElement('tr'), td = cell('td','Henüz zafiyet denetimi kaydı yok.'); td.colSpan = 7; row.append(td); el('vulnerability-logs').append(row); }
        root.querySelectorAll('[data-role="team-select"]').forEach(select => {
            const selected = select.value;
            const signature = data.teams.map(t => t.id + ':' + t.name).join('|');
            if (select.dataset.signature === signature) return;
            select.dataset.signature = signature; select.replaceChildren();
            data.teams.forEach(team => { const option = cell('option',team.name); option.value = team.id; select.append(option); });
            if ([...select.options].some(o => o.value === selected)) select.value = selected;
        });
        el('injects').replaceChildren();
        data.injects.forEach(inject => {
            const row = document.createElement('tr');
            const points = cell('td',fmt(inject.points)); points.className = Number(inject.points) < 0 ? 'ls-negative' : 'ls-positive';
            row.append(cell('td',date(inject.created_at)),cell('td',inject.team_name),cell('td',inject.kind === 'task' ? 'Görev' : 'Ceza'),points,cell('td',inject.reason),cell('td',inject.created_by));
            el('injects').append(row);
        });
        if (!data.injects.length) { const row = document.createElement('tr'), td = cell('td','Henüz görev veya ceza kaydı yok.'); td.colSpan = 6; row.append(td); el('injects').append(row); }
        if (typeof window.Chart !== 'function') { error('Grafik kütüphanesi yüklenemedi. Eklenti paketindeki Chart.js dosyasını kontrol edin.'); return; }
        const times = [...new Set(data.trend.map(p => p.at))];
        const scores = new Map(data.trend.map(p => [p.team_id + ':' + p.at, Number(p.score)]));
        const datasets = data.teams.map(team => ({label:team.name,borderColor:team.color,backgroundColor:team.color,borderWidth:2,pointRadius:0,tension:0,
            data:times.map(at => scores.has(team.id + ':' + at) ? scores.get(team.id + ':' + at) : null)}));
        const chartData = {labels:times.map(t => new Date(t).toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'})),datasets};
        if (chart) { chart.data = chartData; chart.update('none'); }
        else chart = new window.Chart(el('chart'), {
            type: 'line', data: chartData,
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                interaction: {mode: 'index', intersect: false},
                plugins: {legend: {labels: {color: '#b8cbe0', usePointStyle: true, boxWidth: 7}}},
                scales: {
                    x: {ticks: {color: '#91a8c3', maxTicksLimit: 7}, grid: {color: '#25364a'}},
                    y: {ticks: {color: '#91a8c3'}, grid: {color: '#25364a'}}
                }
            }
        });
    }
    function poll() {
        if (disposed || busy || document.hidden) return;
        if (!root.isConnected) { cleanup(); return; }
        busy = true;
        api(root.dataset.dashboard,new FormData(),data => { busy = false; failureCount = 0; error(''); render(data); },message => {
            busy = false; failureCount++; el('connection').textContent = 'BAĞLANTI SORUNU'; error(message);
        });
    }
    // No overlapping requests. Liman's helper owns transport and CSRF headers.
    const timer = setInterval(poll,10000);
    const staleTimer = setInterval(() => {
        if (!root.isConnected) { cleanup(); return; }
        if (busy) { el('connection').textContent = 'GÜNCELLENİYOR'; }
    },10000);
    const visibility = () => { if (!document.hidden) poll(); };
    document.addEventListener('visibilitychange',visibility);
    const handlers = [];
    root.querySelectorAll('form[data-action]').forEach(form => {
        let submitting = false, retryId = null, retryBody = null;
        const handler = event => {
            event.preventDefault(); if (submitting || !form.reportValidity()) return;
            const data = new FormData(form);
            if (form.dataset.action === 'addInject') {
                const body = JSON.stringify([...data.entries()]);
                if (retryBody !== body) { retryId = crypto.randomUUID(); retryBody = body; }
                data.set('request_id',retryId);
            }
            const button = form.querySelector('button'); submitting = true; button.disabled = true;
            const finish = message => { submitting = false; button.disabled = false; el('form-result').textContent = message; };
            api(root.dataset[form.dataset.action],data,() => { retryId = null; retryBody = null; form.reset(); finish('Kayıt eklendi.'); poll(); },message => finish(message));
        };
        form.addEventListener('submit',handler); handlers.push([form,handler]);
    });
    function cleanup() {
        if (disposed) return; disposed = true; clearInterval(timer); clearInterval(staleTimer);
        document.removeEventListener('visibilitychange',visibility);
        handlers.forEach(([form,handler]) => form.removeEventListener('submit',handler));
        if (chart) chart.destroy();
    }
    window.lsScoreboardCleanup = cleanup;
    poll();
})();
