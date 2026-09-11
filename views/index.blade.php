<link rel="stylesheet" href="{{ publicPath('css/scoreboard.css') }}">
<div id="ls-scoreboard" class="ls-board"
     data-dashboard="{{ API('ls_dashboard') }}"
     data-create-team="{{ API('ls_create_team') }}"
     data-create-service="{{ API('ls_create_service') }}"
     data-add-inject="{{ API('ls_add_inject') }}">
    <div class="ls-heading">
        <div><div class="ls-eyebrow">CYBER DEFENCE EXERCISE</div><h2>CENK <span>Scoreboard</span></h2><p>Takımlar, servis sürekliliği ve tatbikat puanları</p></div>
        <div class="ls-live"><span class="ls-dot"></span><span data-role="connection">Bağlanıyor…</span><small data-role="updated">10 saniyede bir güncellenir</small></div>
    </div>
    <div class="ls-error" data-role="error" role="alert" hidden></div>
    <div class="ls-stats">
        <div><small>TAKIMLAR</small><strong data-role="team-count">—</strong></div>
        <div><small>AKTİF SERVİSLER</small><strong data-role="up-count">—</strong></div>
        <div><small>KESİNTİ</small><strong data-role="down-count">—</strong></div>
        <div><small>ÖLÇÜM BEKLENİYOR / GECİKTİ</small><strong data-role="unknown-count">—</strong></div>
    </div>
    <div class="ls-columns">
        <section class="ls-panel"><div class="ls-panel-heading"><h3>Takım sıralaması</h3><span>TOPLAM PUAN</span></div>
            <div class="ls-table-wrap"><table class="ls-ranking"><thead><tr><th>Sıra</th><th>Takım</th><th>SLA</th><th>Görev / Ceza</th><th>Güvenlik</th><th>Toplam</th></tr></thead><tbody data-role="ranking"></tbody></table></div>
            <p data-role="empty" class="ls-empty" hidden>Henüz takım yok. White Team ilk takımı ekleyerek başlayabilir.</p>
        </section>
        <section class="ls-panel"><div class="ls-panel-heading"><h3>Skor trendi</h3><span>SON 60 DAKİKA</span></div><div class="ls-chart"><canvas data-role="chart" role="img" aria-label="Takımların son bir saatteki toplam puan trendi"></canvas></div><p class="ls-note">Her nokta, servis başlangıcından o ana kadar biriken SLA ve inject puanlarını gösterir.</p></section>
    </div>
    <section class="ls-panel"><div class="ls-panel-heading"><h3>Servis durum matrisi</h3><div class="ls-legend"><span>● Ayakta</span><span>● Kesinti</span><span>● Gecikmiş / bilinmiyor</span></div></div><div class="ls-table-wrap"><table class="ls-matrix"><thead data-role="matrix-head"></thead><tbody data-role="matrix-body"></tbody></table></div><p class="ls-note">Hücrelerde durum ve SLA yüzdesi gösterilir. Ayrıntılar için hücreye odaklanın. Ölçülemeyen süre uptime puanı kazandırmaz.</p></section>
    <section class="ls-panel"><div class="ls-panel-heading"><h3>Zafiyet ve Güvenlik Uyumluluk Matrisi</h3><div class="ls-legend"><span class="ls-vuln-vulnerable">● Açık</span><span class="ls-vuln-patched">● Kapatıldı</span></div></div><div class="ls-table-wrap"><table class="ls-matrix"><thead data-role="vulnerability-head"></thead><tbody data-role="vulnerability-body"></tbody></table></div><p class="ls-note">Her denetim yalnızca yönetici yapılandırmasındaki allowlist komutuyla çalıştırılır. Kapatılan zafiyetlerin puanı toplam skora eklenir.</p></section>
    <section class="ls-panel"><div class="ls-panel-heading"><h3>Zafiyet denetim geçmişi</h3><span>SON 100 KAYIT</span></div><div class="ls-table-wrap"><table><thead><tr><th>Zaman</th><th>Takım</th><th>Denetim</th><th>Durum</th><th>Exit code</th><th>Süre</th><th>Maskelenmiş çıktı</th></tr></thead><tbody data-role="vulnerability-logs"></tbody></table></div></section>
    @if($manageTeams || $manageServices || $manageInjects)
    <details class="ls-panel ls-management"><summary>White Team yönetimi</summary><div class="ls-forms">
        @if($manageTeams)
        <form data-action="createTeam"><h4>Takım ekle</h4><label>Takım adı<input name="name" maxlength="80" required placeholder="Blue Team 01"></label><label>Takım rengi<input name="color" type="color" value="#36a3ff"></label><button type="submit">Takımı oluştur</button></form>
        @endif
        @if($manageServices)
        <form data-action="createService"><h4>Servis ekle</h4><label>Takım<select name="team_id" data-role="team-select" required></select></label><label>Servis adı<input name="name" required maxlength="80" placeholder="Kurumsal web"></label>
            <div class="ls-form-row"><label>Protokol<select name="protocol"><option>HTTP</option><option>HTTPS</option><option>SSH</option><option>DNS</option><option>LDAP</option><option>WINRM</option><option>PING</option></select></label><label>Port<input name="port" type="number" min="1" max="65535" placeholder="Varsayılan"></label></div>
            <label>Hedef IP<input name="host" required placeholder="192.0.2.10"></label>
            <div class="ls-form-row"><label>Azami SLA puanı<input name="weight" type="number" step="0.01" min="0.01" max="10000" value="100" required></label><label>Timeout (ms)<input name="timeout_ms" type="number" min="100" max="5000" value="3000" required></label></div>
            <label>Kontrol aralığı (sn)<input name="interval_seconds" type="number" min="10" max="300" value="10" required></label>
            <label>Protokol seçenekleri (JSON)<textarea name="options" rows="3" spellcheck="false">{}</textarea></label><small>DNS: {"dns_name":"example.test"} · HTTPS: {"hostname":"web.example.test","path":"/health"}</small><button type="submit">Servisi oluştur</button>
        </form>
        @endif
        @if($manageInjects)
        <form data-action="addInject"><h4>Görev / ceza ekle</h4><label>Takım<select name="team_id" data-role="team-select" required></select></label><label>Tür<select name="kind"><option value="task">Görev ödülü (+)</option><option value="penalty">Ceza (−)</option></select></label><label>Puan miktarı<input name="points" type="number" min="0.01" max="100000" step="0.01" required></label><label>Gerekçe<textarea name="reason" maxlength="500" required rows="4"></textarea></label><small>Kayıtlar zaman damgası ve kullanıcı kimliğiyle saklanır. Düzeltmeler için karşı puanlı yeni bir kayıt ekleyin.</small><button type="submit">Puanı kaydet</button></form>
        @endif
    </div><p data-role="form-result" role="status"></p></details>
    @endif
    <section class="ls-panel"><div class="ls-panel-heading"><h3>Görev ve ceza günlüğü</h3><span>SON 100 KAYIT</span></div><div class="ls-table-wrap"><table><thead><tr><th>Zaman</th><th>Takım</th><th>Tür</th><th>Puan</th><th>Gerekçe</th><th>Kullanıcı</th></tr></thead><tbody data-role="injects"></tbody></table></div></section>
</div>
<script src="{{ publicPath('vendor/chart.umd.min.js') }}"></script>
<script src="{{ publicPath('js/scoreboard.js') }}"></script>
