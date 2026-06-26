// AI worker dev-server SPA — Alpine component.
// All calls use relative URLs; WS derived from location. Bearer token optional.
function devserver() {
  return {
    tabs: [
      { id: 'methods',  label: 'Methods' },
      { id: 'stream',   label: 'Audio stream' },
      { id: 'stats',    label: 'Pull stats' },
      { id: 'settings', label: 'Settings' },
    ],
    tab: 'methods',
    token: '',

    // methods tab
    methods: [],
    m: { mode: '', source: '', target: '', model: '', file: null, drag: false, running: false, result: null, error: '' },

    // stream tab
    ws: { sock: null, rec: null, stream: null, active: false, status: 'idle', error: '',
          partial: '', final: '', language: '', segments: [] },

    // stats tab
    stats: null,
    _statsTimer: null,

    // settings tab
    settings: [],
    s: { saving: false, applied: [], pendingRestart: [], error: '', errKey: '' },

    // ---------- lifecycle ----------
    init() {
      this.token = localStorage.getItem('ds_token') || '';
      const h = (location.hash || '').replace('#', '');
      if (this.tabs.some(t => t.id === h)) this.tab = h;
      else this.tab = localStorage.getItem('ds_tab') || 'methods';
      this.loadMethods();
      this.onTabActivate();
      window.addEventListener('hashchange', () => {
        const x = (location.hash || '').replace('#', '');
        if (this.tabs.some(t => t.id === x) && x !== this.tab) { this.tab = x; this.onTabActivate(); }
      });
    },

    setTab(id) {
      this.tab = id;
      localStorage.setItem('ds_tab', id);
      if (location.hash !== '#' + id) location.hash = id;
      this.onTabActivate();
    },

    onTabActivate() {
      this.stopStatsPoll();
      if (this.tab === 'stats') this.startStatsPoll();
      if (this.tab === 'settings' && !this.settings.length) this.loadSettings();
    },

    saveToken() { localStorage.setItem('ds_token', this.token || ''); },

    // ---------- http helpers ----------
    authHeaders(extra = {}) {
      const h = { ...extra };
      if (this.token) h['Authorization'] = 'Bearer ' + this.token;
      return h;
    },
    // Directory of the current page: '/' at :8877/, '/worker-ai/' behind nginx.
    // Lets API/WS/asset URLs resolve relative to wherever the SPA is served.
    basePath() { return location.pathname.replace(/[^/]*$/, ''); },
    // Resolve a path (absolute like '/api/x' or relative) against basePath.
    resolveUrl(p) { return this.basePath() + String(p || '').replace(/^\//, ''); },
    apiUrl(p) { return this.resolveUrl(p); },
    wsUrl(path) {
      const proto = location.protocol === 'https:' ? 'wss' : 'ws';
      let u = proto + '://' + location.host + this.resolveUrl(path);
      if (this.token) u += (u.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(this.token);
      return u;
    },
    async getJSON(url) {
      const r = await fetch(url, { headers: this.authHeaders() });
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw Object.assign(new Error(data.error || ('HTTP ' + r.status)), { data, status: r.status });
      return data;
    },

    // ---------- formatting ----------
    fmtBytes(n) {
      if (n == null) return '–';
      if (n < 1024) return n + ' B';
      if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
      return (n / 1048576).toFixed(2) + ' MB';
    },
    async copy(txt) { try { await navigator.clipboard.writeText(txt); } catch (e) {} },

    // ============ METHODS ============
    get currentMethod() { return this.methods.find(x => x.mode === this.m.mode) || null; },

    async loadMethods() {
      try {
        const d = await this.getJSON(this.apiUrl('api/methods'));
        this.methods = d.methods || [];
        if (this.methods.length && !this.m.mode) { this.m.mode = this.methods[0].mode; this.onModeChange(); }
      } catch (e) { this.m.error = 'Failed to load methods: ' + e.message; }
    },

    onModeChange() {
      const me = this.currentMethod;
      if (!me) return;
      this.m.source = me.sources[0] || '';
      this.m.target = me.targets[0] || '';
    },

    onDrop(ev) {
      this.m.drag = false;
      const f = ev.dataTransfer.files && ev.dataTransfer.files[0];
      if (f) this.m.file = f;
    },
    onPick(ev) { const f = ev.target.files && ev.target.files[0]; if (f) this.m.file = f; },

    async run() {
      if (!this.m.file) return;
      this.m.running = true; this.m.error = ''; this.m.result = null;
      try {
        const fd = new FormData();
        fd.append('file', this.m.file);
        fd.append('sourceFormat', this.m.source);
        fd.append('targetFormat', this.m.target);
        if ((this.m.mode === 'llm' || this.m.mode === 'embedding') && this.m.model) fd.append('model', this.m.model);
        const r = await fetch(this.apiUrl('api/run'), { method: 'POST', headers: this.authHeaders(), body: fd });
        const data = await r.json().catch(() => ({}));
        if (!r.ok || data.ok === false) { this.m.error = data.error || ('HTTP ' + r.status); return; }
        this.m.result = data;
      } catch (e) {
        this.m.error = 'Network error: ' + e.message;
      } finally {
        this.m.running = false;
      }
    },

    // download with auth header (can't rely on plain <a> when token is set)
    async download(url) {
      try {
        const r = await fetch(this.resolveUrl(url), { headers: this.authHeaders() });
        if (!r.ok) { this.m.error = 'Download failed: HTTP ' + r.status; return; }
        const blob = await r.blob();
        const cd = r.headers.get('Content-Disposition') || '';
        const match = /filename="?([^"]+)"?/.exec(cd);
        const name = match ? match[1] : 'result.' + (this.m.result?.ext || 'bin');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
      } catch (e) { this.m.error = 'Download error: ' + e.message; }
    },

    // ============ AUDIO STREAM ============
    async startStream() {
      this.ws.error = ''; this.ws.partial = ''; this.ws.final = ''; this.ws.segments = []; this.ws.language = '';
      if (!navigator.mediaDevices || !window.MediaRecorder) { this.ws.error = 'MediaRecorder not supported in this browser'; return; }
      try {
        this.ws.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      } catch (e) { this.ws.error = 'Mic access denied: ' + e.message; return; }

      let mime = 'audio/webm;codecs=opus';
      if (!MediaRecorder.isTypeSupported(mime)) mime = 'audio/webm';
      const sock = new WebSocket(this.wsUrl('/ws/stream'));
      this.ws.sock = sock;
      this.ws.status = 'connecting…';

      sock.onopen = () => {
        this.ws.status = 'connected';
        sock.send(JSON.stringify({ type: 'start', sampleRate: 16000, format: 'webm/opus', lang: null }));
        let rec;
        try { rec = new MediaRecorder(this.ws.stream, { mimeType: mime }); }
        catch (e) { this.ws.error = 'MediaRecorder init failed (codec unsupported?): ' + e.message; try { sock.close(); } catch (_) {} return; }
        this.ws.rec = rec;
        rec.ondataavailable = (e) => {
          if (e.data && e.data.size > 0 && sock.readyState === WebSocket.OPEN) sock.send(e.data);
        };
        // stop() flushes a final dataavailable asynchronously; send {stop} only after
        // that last blob has been emitted so the server doesn't finalize before the tail arrives.
        rec.onstop = () => {
          if (sock.readyState === WebSocket.OPEN) sock.send(JSON.stringify({ type: 'stop' }));
          if (this.ws.stream) { this.ws.stream.getTracks().forEach(t => t.stop()); }
        };
        rec.start(1000); // 1s timeslice → binary frames
        this.ws.active = true;
        this.ws.status = 'recording';
      };

      sock.onmessage = (ev) => {
        let msg; try { msg = JSON.parse(ev.data); } catch (e) { return; }
        if (msg.type === 'partial') {
          this.ws.partial = msg.text || '';
          if (msg.segments) this.ws.segments = msg.segments;
          if (msg.language) this.ws.language = msg.language;
        } else if (msg.type === 'final') {
          this.ws.final = msg.text || '';
          this.ws.partial = '';
          if (msg.segments) this.ws.segments = msg.segments;
          if (msg.language) this.ws.language = msg.language;
          this.ws.status = 'final received';
        } else if (msg.type === 'error') {
          this.ws.error = msg.message || 'stream error';
        }
      };

      sock.onerror = () => { this.ws.error = 'WebSocket error'; };
      sock.onclose = () => { this.ws.status = this.ws.active ? 'closed' : this.ws.status; this._teardownStream(); };
    },

    stopStream() {
      try {
        if (this.ws.rec && this.ws.rec.state !== 'inactive') this.ws.rec.stop();
      } catch (e) {}
      // {type:'stop'} is sent from rec.onstop after the final blob flushes.
      // If the recorder never started, send it directly as a fallback.
      if (!this.ws.rec && this.ws.sock && this.ws.sock.readyState === WebSocket.OPEN) {
        this.ws.sock.send(JSON.stringify({ type: 'stop' }));
      }
      this.ws.active = false;
      this.ws.status = 'stopping…';
      // mic tracks are stopped on socket close / teardown; keep capturing until rec.stop flushes
    },

    _teardownStream() {
      this.ws.active = false;
      try { if (this.ws.stream) this.ws.stream.getTracks().forEach(t => t.stop()); } catch (e) {}
      this.ws.rec = null; this.ws.stream = null; this.ws.sock = null;
    },

    // ============ PULL STATS ============
    startStatsPoll() {
      this.fetchStats();
      this._statsTimer = setInterval(() => this.fetchStats(), 2000);
    },
    stopStatsPoll() { if (this._statsTimer) { clearInterval(this._statsTimer); this._statsTimer = null; } },
    async fetchStats() {
      try { this.stats = await this.getJSON(this.apiUrl('api/stats')); }
      catch (e) { /* keep last known; avoid console spam */ }
    },

    // ============ SETTINGS ============
    get groups() {
      const seen = [];
      for (const f of this.settings) if (f.key !== 'PULL_ENABLED' && !seen.includes(f.group)) seen.push(f.group);
      return seen;
    },
    get pullField() { return this.settings.find(f => f.key === 'PULL_ENABLED') || null; },
    fieldsByGroup(g) { return this.settings.filter(f => f.group === g && f.key !== 'PULL_ENABLED'); },

    async loadSettings() {
      this.s.error = ''; this.s.errKey = '';
      try {
        const d = await this.getJSON(this.apiUrl('api/settings'));
        this.settings = (d.settings || []).map(f => ({ ...f, _orig: f.value }));
      } catch (e) { this.s.error = 'Failed to load settings: ' + e.message; }
    },

    dirtyCount() { return this.settings.filter(f => f.value !== f._orig).length; },

    async saveSettings() {
      const changed = {};
      for (const f of this.settings) if (f.value !== f._orig) changed[f.key] = f.value;
      if (!Object.keys(changed).length) return;
      this.s.saving = true; this.s.error = ''; this.s.errKey = ''; this.s.applied = []; this.s.pendingRestart = [];
      try {
        const r = await fetch(this.apiUrl('api/settings'), {
          method: 'PUT',
          headers: this.authHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify(changed),
        });
        const data = await r.json().catch(() => ({}));
        if (!r.ok || data.ok === false) { this.s.error = data.error || ('HTTP ' + r.status); this.s.errKey = data.key || ''; return; }
        this.s.applied = data.applied || [];
        this.s.pendingRestart = data.pendingRestart || [];
        this.settings = (data.settings || []).map(f => ({ ...f, _orig: f.value }));
      } catch (e) {
        this.s.error = 'Network error: ' + e.message;
      } finally {
        this.s.saving = false;
      }
    },
  };
}
