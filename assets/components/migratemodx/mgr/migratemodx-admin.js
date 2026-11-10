(function () {
  var cfg = window.MigrateMODXAdmin || {};
  var L = cfg.lang || {};
  function readStoredUiMode() {
    try {
      var m = window.localStorage && localStorage.getItem('migratemodx_ui_mode');
      if (m === 'expert' || m === 'simple') return m;
    } catch (e) { /* ignore */ }
    return 'simple';
  }
  var initialUiMode = readStoredUiMode();
  var state = {
    report: null,
    uiMode: initialUiMode,
    tab: initialUiMode === 'expert' ? 'overview' : 'migrate',
    busy: false,
    busyLabel: '',
    busyStep: 0,
    journal: null,
    snapshots: [],
    recoverySecret: null,
    busyTimer: null,
    phpMode: 'keepers',
    phpSelected: {},
    codeMode: 'keepers',
    codeSource: 'all',
    tplMode: 'keepers',
    tplSource: 'all',
    phpSource: 'all',
    migrateRun: null,
    migrateAttention: null,
    migratePhase: 1,
    migrateNext: [],
    migrateHasMs2: false,
    migrateMs3Installed: false,
    migrateModxMajor: 2,
    migrateConfirmMs3: false,
    migrateGoal: 'modx3',
    migratePhpReady: false,
    migrateStatusLoaded: false,
    recoveryStatus: null,
    bootstrapped: false
  };

  var BUSY_STEPS = [
    'busy_env',
    'busy_packages',
    'busy_code',
    'busy_php',
    'busy_templates',
    'busy_gate'
  ];

  function _(key, fallback) {
    if (L[key] != null && L[key] !== '') return L[key];
    return fallback != null ? fallback : key;
  }

  function targetModx() {
    if (state.report && state.report.versions && state.report.versions.effective_modx) {
      return String(state.report.versions.effective_modx);
    }
    if (state.report && state.report.environment && state.report.environment.target_modx) {
      return String(state.report.environment.target_modx);
    }
    var pinned = cfg.targetModx || 'auto';
    if (pinned && pinned !== 'auto' && pinned !== 'latest') return String(pinned);
    return '3.0.3';
  }

  function targetPhp() {
    if (state.report && state.report.versions && state.report.versions.effective_php) {
      return String(state.report.versions.effective_php);
    }
    if (state.report && state.report.environment && state.report.environment.target_php) {
      return String(state.report.environment.target_php);
    }
    var pinned = cfg.targetPhp || 'auto';
    if (pinned && pinned !== 'auto' && pinned !== 'latest') return String(pinned);
    return '8.2';
  }

  function withVer(text) {
    return String(text || '')
      .replace(/3\.0\.0/g, targetModx())
      .replace(/3\.0\.3/g, targetModx());
  }

  function yn(v) {
    return v ? _('yes', 'yes') : _('no', 'no');
  }

  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    if (tag === 'button' && (!attrs || attrs.type == null)) {
      n.setAttribute('type', 'button');
    }
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        if (k === 'className') n.className = attrs[k];
        else if (k === 'text') n.textContent = attrs[k];
        else if (k === 'disabled') n.disabled = !!attrs[k];
        else if (k === 'checked') n.checked = !!attrs[k];
        else if (k === 'value') n.value = attrs[k];
        else if (k === 'readonly' || k === 'readOnly') n.readOnly = !!attrs[k];
        else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
        else n.setAttribute(k, attrs[k]);
      });
    }
    (kids || []).forEach(function (c) {
      if (c == null) return;
      n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return n;
  }

  function api(action, data) {
    if (!cfg.connector) {
      return Promise.reject(new Error(_('no_connector', 'Connector URL is missing')));
    }
    var payload = {};
    if (data) {
      Object.keys(data).forEach(function (k) { payload[k] = data[k]; });
    }
    payload.MODAUTH = cfg.token || '';
    return fetch(cfg.connector + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        MODAUTH: cfg.token || '',
        'X-MODAUTH': cfg.token || ''
      },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    }).then(function (r) {
      var ct = (r.headers.get('content-type') || '').toLowerCase();
      var isExport = action.indexOf('export_') === 0 || action.indexOf('migrate_export_') === 0;
      if (isExport && r.ok && ct.indexOf('application/json') === -1) {
        return r.blob().then(function (b) {
          var a = document.createElement('a');
          a.href = URL.createObjectURL(b);
          a.download = 'migratemodx-export';
          a.click();
          return { ok: true, downloaded: true };
        });
      }
      return r.text().then(function (text) {
        var out;
        try {
          out = JSON.parse(text);
        } catch (e) {
          throw new Error(
            _('bad_response', 'Bad server response') + ' (HTTP ' + r.status + ')'
            + (text ? ': ' + String(text).slice(0, 180) : '')
          );
        }
        if (!out || typeof out !== 'object') {
          out = { ok: false, error: _('bad_response', 'Bad server response') };
        }
        if (!r.ok && out.ok !== false) {
          out.ok = false;
          out.error = out.error || ('HTTP ' + r.status);
        }
        return out;
      });
    });
  }

  function stopBusyTicker() {
    if (state.busyTimer) {
      clearInterval(state.busyTimer);
      state.busyTimer = null;
    }
  }

  function startBusyTicker(mode) {
    stopBusyTicker();
    state.busyStep = 0;
    if (mode !== 'audit') return;
    state.busyTimer = setInterval(function () {
      state.busyStep = (state.busyStep + 1) % BUSY_STEPS.length;
      var labelEl = document.getElementById('mmx-busy-label');
      if (labelEl) {
        labelEl.textContent = _(BUSY_STEPS[state.busyStep], _('busy_working', 'Working…'));
      }
      var bar = document.getElementById('mmx-busy-bar');
      if (bar) {
        bar.style.width = Math.min(92, 18 + state.busyStep * 14) + '%';
      }
    }, 1400);
  }

  function syncBusyUi() {
    var root = document.getElementById('mmx-root');
    if (!root) return;
    root.classList.toggle('mmx-busy', !!state.busy);
    root.querySelectorAll('button').forEach(function (b) {
      b.disabled = !!state.busy;
    });

    var overlay = document.getElementById('mmx-busy-overlay');
    if (!state.busy) {
      if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
      return;
    }
    if (!overlay) {
      overlay = el('div', {
        id: 'mmx-busy-overlay',
        className: 'mmx-busy-overlay',
        role: 'status',
        'aria-live': 'polite'
      }, [
        el('div', { className: 'mmx-busy-card' }, [
          el('div', { className: 'mmx-spinner', 'aria-hidden': 'true' }),
          el('div', { id: 'mmx-busy-label', className: 'mmx-busy-label', text: state.busyLabel || _('busy_working', 'Working…') }),
          el('div', { className: 'mmx-busy-track' }, [
            el('div', { id: 'mmx-busy-bar', className: 'mmx-busy-bar' })
          ]),
          el('p', { className: 'mmx-busy-hint', text: _('busy_hint', 'This may take several minutes…') })
        ])
      ]);
      root.appendChild(overlay);
    } else {
      var labelEl = document.getElementById('mmx-busy-label');
      if (labelEl) labelEl.textContent = state.busyLabel || _('busy_working', 'Working…');
      root.appendChild(overlay);
    }
  }

  function setBusy(v, label, mode) {
    state.busy = !!v;
    state.busyLabel = label || _('busy_working', 'Working…');
    if (v) startBusyTicker(mode || '');
    else stopBusyTicker();
    syncBusyUi();
  }

  function showError(err) {
    var msg = (err && err.message) ? err.message : String(err || _('audit_failed'));
    alert(msg);
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function tagVerdict(v) {
    var label = _('verdict_' + (v || 'unknown'), v || 'unknown');
    return el('span', { className: 'tag ' + (v || 'unknown'), text: label });
  }

  function human(key, fallback) {
    return _(key, fallback != null ? fallback : key);
  }

  function trafficLabel(t) {
    return human('traffic_' + (t || 'unknown'), t || '—');
  }

  function severityLabel(s) {
    return human('sev_' + (s || 'warning'), s || 'warning');
  }

  function domainLabel(d) {
    return human('domain_' + (d || 'system'), d || 'system');
  }

  function sourceLabel(s) {
    return human('src_' + (s || 'other'), s || '—');
  }

  function evidenceLabel(e) {
    return human('evidence_' + (e || 'unknown'), e || '—');
  }

  function codeHelp(code) {
    if (!code) return '';
    var c = String(code);
    var k = 'help_' + c;
    var t = _(k, '');
    if (t && t !== k) return t;
    if (c.indexOf('pkg_unknown') === 0) return _('help_pkg_unknown', '');
    if (c.indexOf('pkg_legacy_') === 0) return _('help_pkg_legacy', '');
    if (c.indexOf('pkg_update_') === 0) return _('help_pkg_update', '');
    if (c.indexOf('pkg_') === 0) return _('help_pkg_fail', '');
    return '';
  }

  function issueTitle(issue) {
    var code = issue.code || '';
    var domain = issue.domain || '';
    if (domain === 'package') {
      var name = '';
      var key = String(issue.key || '');
      if (key.indexOf('pkg:') === 0) name = key.slice(4);
      else if (key.indexOf('pkg_unknown:') === 0) name = key.slice(12);
      else if (key.indexOf('pkg_legacy:') === 0) name = key.slice(11);
      else if (key.indexOf('pkg_update:') === 0) name = key.slice(11);
      else if (String(code).indexOf('pkg_unknown_') === 0) name = String(code).slice(12);
      else if (String(code).indexOf('pkg_legacy_') === 0) name = String(code).slice(11);
      else if (String(code).indexOf('pkg_update_') === 0) name = String(code).slice(11);
      else if (String(code).indexOf('pkg_') === 0) name = String(code).slice(4);
      if (String(code).indexOf('pkg_unknown') === 0) {
        return _('issue_pkg_unknown_title', 'Package status unknown') + (name ? ': ' + name : '');
      }
      if (String(code).indexOf('pkg_legacy_') === 0) {
        return _('issue_pkg_legacy_title', 'Legacy with successor') + (name ? ': ' + name : '');
      }
      if (String(code).indexOf('pkg_update_') === 0) {
        return _('issue_pkg_update_title', 'Update package') + (name ? ': ' + name : '');
      }
      return _('issue_pkg_title', 'Package needs a decision') + (name ? ': ' + name : '');
    }
    var titled = _('issue_' + code + '_title', '');
    if (titled && titled !== ('issue_' + code + '_title')) return titled;
    return issue.message || code || _('blockers');
  }

  function issueCard(issue, kind) {
    var domain = issue.domain || 'system';
    var help = codeHelp(issue.code) || _('help_generic', '');
    var cardEl = el('div', { className: 'mmx-issue is-' + (kind || 'warning') }, [
      el('div', { className: 'mmx-issue-top' }, [
        el('span', { className: 'mmx-badge domain-' + domain, text: domainLabel(domain) }),
        el('span', { className: 'mmx-badge', text: severityLabel(issue.severity || kind) }),
        el('h4', { className: 'mmx-issue-title', text: issueTitle(issue) })
      ]),
      help ? el('p', { className: 'mmx-issue-help', text: help }) : null,
      el('p', { className: 'mmx-issue-tech', text: _('tech_detail', 'Details') + ': ' + (issue.message || '—') })
    ]);
    if (issue.key && kind === 'blocker') {
      var actions = el('div', { className: 'mmx-issue-actions' }, [
        el('button', {
          text: _('accept_risk'),
          onClick: function () {
            setBusy(true, _('busy_working', 'Working…'));
            api('accept', { key: issue.key })
              .then(function () { return runAudit(); })
              .catch(showError)
              .finally(function () { setBusy(false); });
          }
        })
      ]);
      cardEl.appendChild(actions);
    }
    return cardEl;
  }

  function fitShell() {
    var root = document.getElementById('mmx-root');
    if (!root) return;
    try {
      document.documentElement.classList.add('mmx-lock');
      document.body.classList.add('mmx-lock');
      var hosts = [
        document.getElementById('modx-content'),
        document.getElementById('modx-panel-holder'),
        document.getElementById('modx-content-div'),
        root.parentNode
      ];
      hosts.forEach(function (n) {
        if (!n || !n.classList) return;
        n.classList.add('mmx-host');
        n.style.overflow = 'hidden';
      });
      var walk = root.parentNode;
      var guard = 0;
      while (walk && walk !== document.body && guard < 12) {
        if (walk.style) {
          walk.style.overflow = 'hidden';
        }
        walk = walk.parentNode;
        guard += 1;
      }
    } catch (e) { /* ignore */ }
    var top = 0;
    try { top = root.getBoundingClientRect().top; } catch (e2) { top = 96; }
    var h = Math.floor(window.innerHeight - top - 4);
    if (h < 320) h = 320;
    root.style.height = h + 'px';
    root.style.maxHeight = h + 'px';
  }

  function vendorPackages() {
    if (state.report && state.report.vendor_packages && state.report.vendor_packages.length) {
      return state.report.vendor_packages;
    }
    return (state.report && state.report.replaced_packages) ? state.report.replaced_packages : [];
  }

  function filterFindings(list, mode) {
    list = list || [];
    if (mode === 'all') return list;
    if (mode === 'keepers') {
      return list.filter(function (f) {
        if (f.manual_override) return true;
        return f.keeper !== false && !f.will_be_replaced;
      });
    }
    if (mode === 'replaced') {
      return list.filter(function (f) { return !!f.will_be_replaced && !f.manual_override; });
    }
    return list;
  }

  function peekFinding(f) {
    setBusy(true, _('busy_peek', 'Opening…'));
    api('peek', {
      source: f.source === 'component_file' ? 'file' : f.source,
      id: f.id || 0,
      path: f.path || (f.source === 'file' || f.source === 'component_file' ? f.name : '')
    }).then(function (out) {
      if (!out || !out.ok) {
        alert((out && out.error) || _('peek_failed', 'Cannot open'));
        return;
      }
      showPeekModal(out);
    }).catch(showError).finally(function () { setBusy(false); });
  }

  function showPeekModal(out) {
    var old = document.getElementById('mmx-peek');
    if (old && old.parentNode) old.parentNode.removeChild(old);
    var root = document.getElementById('mmx-root') || document.body;
    var modal = el('div', { id: 'mmx-peek', className: 'mmx-busy-overlay' }, [
      el('div', { className: 'mmx-busy-card', style: 'width:min(960px,94vw);text-align:left;max-height:85vh;display:flex;flex-direction:column;' }, [
        el('div', { className: 'mmx-finding-top' }, [
          el('h4', { className: 'mmx-finding-title', text: out.mgr_hint || out.name || 'peek' }),
          el('button', { text: _('close', 'Close'), onClick: function () {
            var n = document.getElementById('mmx-peek');
            if (n && n.parentNode) n.parentNode.removeChild(n);
          } })
        ]),
        el('pre', { style: 'max-height:60vh;flex:1;', text: out.content || '' })
      ])
    ]);
    root.appendChild(modal);
  }

  function absoluteUrl(pathOrUrl) {
    var s = String(pathOrUrl || '');
    if (!s) return '';
    if (/^https?:\/\//i.test(s)) return s;
    var base = (cfg.siteUrl || '').replace(/\/?$/, '/');
    return base + s.replace(/^\//, '');
  }

  function copyText(text) {
    text = String(text || '');
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).catch(function () { fallbackCopy(text); });
      return;
    }
    fallbackCopy(text);
  }

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', 'readonly');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) { /* ignore */ }
    document.body.removeChild(ta);
  }

  function copyFieldRow(label, value) {
    var input = el('input', {
      className: 'mmx-copy-input',
      type: 'text',
      value: value || '',
      readonly: 'readonly',
      onFocus: function (ev) { try { ev.target.select(); } catch (e) { /* ignore */ } }
    });
    return el('div', { className: 'mmx-copy-row' }, [
      el('label', { className: 'mmx-copy-label', text: label }),
      el('div', { className: 'mmx-copy-controls' }, [
        input,
        el('button', {
          type: 'button',
          text: _('copy', 'Copy'),
          onClick: function () {
            copyText(value);
            input.focus();
            try { input.select(); } catch (e) { /* ignore */ }
          }
        })
      ])
    ]);
  }

  function normalizeRecoveryCreds(rec) {
    rec = rec || {};
    var url = rec.url || absoluteUrl(rec.url_path || '');
    var shortcutUrl = rec.shortcut_url || absoluteUrl(rec.shortcut || 'migratemodx-recovery.php');
    return {
      url: url,
      shortcut_url: shortcutUrl,
      password: rec.password || '',
      token: rec.token || '',
      url_path: rec.url_path || '',
      shortcut: rec.shortcut || 'migratemodx-recovery.php'
    };
  }

  function showRecoveryCredsModal(rec) {
    var c = normalizeRecoveryCreds(rec);
    state.recoverySecret = c;
    var old = document.getElementById('mmx-recovery-creds');
    if (old && old.parentNode) old.parentNode.removeChild(old);
    var root = document.getElementById('mmx-root') || document.body;
    var allText = _('snap_url') + ': ' + c.url + '\n'
      + _('snap_shortcut') + ': ' + c.shortcut_url + '\n'
      + _('snap_password') + ': ' + c.password;
    var modal = el('div', { id: 'mmx-recovery-creds', className: 'mmx-busy-overlay' }, [
      el('div', { className: 'mmx-busy-card mmx-creds-card' }, [
        el('div', { className: 'mmx-finding-top' }, [
          el('h4', { className: 'mmx-finding-title', text: _('snap_creds') }),
          el('button', { text: _('close', 'Close'), onClick: function () {
            var n = document.getElementById('mmx-recovery-creds');
            if (n && n.parentNode) n.parentNode.removeChild(n);
          } })
        ]),
        el('p', { className: 'muted', text: _('snap_save') }),
        copyFieldRow(_('snap_url'), c.url),
        copyFieldRow(_('snap_shortcut'), c.shortcut_url),
        copyFieldRow(_('snap_password'), c.password),
        el('div', { className: 'mmx-actions' }, [
          el('button', {
            className: 'primary',
            text: _('copy_all', 'Copy all'),
            onClick: function () { copyText(allText); }
          }),
          el('a', {
            href: c.url,
            target: '_blank',
            rel: 'noopener',
            className: 'mmx-btn',
            text: _('open_recovery', 'Open Recovery')
          })
        ])
      ])
    ]);
    root.appendChild(modal);
  }

  function findingMetaLine(f) {
    var bits = [];
    if (f.id) bits.push('#' + f.id);
    if (f.path) bits.push(f.path);
    else if (f.name && f.source && f.source !== 'file' && f.source !== 'component_file') {
      bits.push(sourceLabel(f.source) + ': ' + f.name);
    }
    if (f.successor) bits.push('→ ' + f.successor);
    return bits.join(' · ');
  }

  function sourceFilterBar(list, current, onPick) {
    var counts = { all: list.length };
    list.forEach(function (f) {
      var s = f.source || 'other';
      counts[s] = (counts[s] || 0) + 1;
    });
    var order = ['all', 'snippet', 'plugin', 'chunk', 'template', 'file', 'component_file'];
    var keys = order.filter(function (k) { return k === 'all' || counts[k]; });
    Object.keys(counts).forEach(function (k) {
      if (keys.indexOf(k) === -1) keys.push(k);
    });
    return el('div', { className: 'mmx-source-filters' }, keys.map(function (k) {
      var label = k === 'all' ? _('filter_src_all', 'All types') : sourceLabel(k);
      return el('button', {
        className: current === k ? 'is-on' : '',
        text: label + ' (' + counts[k] + ')',
        onClick: function () { onPick(k); }
      });
    }));
  }

  function bySourceThenName(a, b) {
    var sa = String(a.source || '');
    var sb = String(b.source || '');
    if (sa !== sb) return sa.localeCompare(sb);
    return String(a.name || '').localeCompare(String(b.name || ''));
  }

  function appendGroupedFindings(parent, list, opts) {
    opts = opts || {};
    var groups = {};
    var order = [];
    list.slice().sort(bySourceThenName).forEach(function (f) {
      var key = f.source || 'other';
      if (!groups[key]) {
        groups[key] = [];
        order.push(key);
      }
      groups[key].push(f);
    });
    order.forEach(function (key) {
      var wrap = el('div', { className: 'mmx-group' }, [
        el('h4', { className: 'mmx-group-title', text: sourceLabel(key) + ' · ' + groups[key].length })
      ]);
      var grid = el('div', { className: 'mmx-grid' });
      groups[key].forEach(function (f) {
        grid.appendChild(findingCard(f, opts));
      });
      wrap.appendChild(grid);
      parent.appendChild(wrap);
    });
  }

  function findingCard(f, opts) {
    opts = opts || {};
    var replaced = !!f.will_be_replaced;
    var title = (f.name || '').length > 90 ? (f.name || '').slice(0, 90) + '…' : (f.name || '');
    var meta = findingMetaLine(f);
    var kids = [
      el('div', { className: 'mmx-finding-top' }, [
        opts.selectable ? el('input', {
          className: 'mmx-check',
          type: 'checkbox',
          checked: !!state.phpSelected[f.uid],
          disabled: replaced && state.phpMode === 'keepers',
          onChange: function (ev) {
            if (ev.target.checked) state.phpSelected[f.uid] = true;
            else delete state.phpSelected[f.uid];
          }
        }) : null,
        el('span', { className: 'mmx-badge', text: sourceLabel(f.source) }),
        el('span', { className: 'mmx-badge', text: severityLabel(f.severity) }),
        f.package ? el('span', {
          className: 'mmx-badge domain-package',
          text: (f.package === 'custom' ? _('pkg_custom', 'custom') : f.package)
            + (f.manual_override ? ' · ' + _('pkg_manual', 'manual edit') : '')
            + (replaced ? ' · ' + _('pkg_will_replace', 'vendor update') : '')
        }) : null,
        el('h4', { className: 'mmx-finding-title', text: title })
      ]),
      el('p', { className: 'mmx-finding-meta', text: f.message || f.rule || f.code || '' }),
      meta ? el('p', { className: 'mmx-finding-path', text: meta }) : null,
      f.excerpt ? el('pre', { text: f.excerpt }) : null,
      el('div', { className: 'mmx-finding-actions' }, [
        el('button', { text: _('open_place', 'Open place'), onClick: function () { peekFinding(f); } })
      ])
    ];
    return el('div', { className: 'mmx-finding' + (replaced ? ' is-replaced' : '') }, kids);
  }

  function card(title, kids) {
    var body = kids || [];
    if (title) body = [el('h3', { className: 'mmx-card-title', text: title })].concat(body);
    return el('div', { className: 'mmx-card' }, body);
  }

  function chip(label, value, tone) {
    return el('span', { className: 'mmx-chip' + (tone ? ' ' + tone : '') }, [
      el('span', { className: 'dot' }),
      el('span', { text: label + ': ' + value })
    ]);
  }

  function isExpertMode() {
    return state.uiMode === 'expert';
  }

  function setUiMode(mode) {
    var next = mode === 'expert' ? 'expert' : 'simple';
    state.uiMode = next;
    try {
      if (window.localStorage) localStorage.setItem('migratemodx_ui_mode', next);
    } catch (e) { /* ignore */ }
    if (next === 'simple') {
      state.tab = 'migrate';
    } else if (state.tab === 'migrate' && !state.report) {
      /* stay on migrate until audit exists */
    }
    render();
    if (next === 'simple' && !state.migrateStatusLoaded) {
      loadMigrateStatus();
    }
  }

  function render() {
    var root = document.getElementById('mmx-root');
    if (!root) return;
    if (!isExpertMode() && state.tab !== 'migrate') {
      state.tab = 'migrate';
    }
    root.innerHTML = '';
    var chrome = el('div', { className: 'mmx-chrome' });
    var modeBar = el('div', { className: 'mmx-mode-bar', role: 'group', 'aria-label': _('mode_label', 'Interface mode') }, [
      el('button', {
        type: 'button',
        className: 'mmx-mode-chip' + (!isExpertMode() ? ' active' : ''),
        text: _('mode_simple', 'Simple'),
        onClick: function () { if (isExpertMode()) setUiMode('simple'); }
      }),
      el('button', {
        type: 'button',
        className: 'mmx-mode-chip' + (isExpertMode() ? ' active' : ''),
        text: _('mode_expert', 'Expert'),
        onClick: function () { if (!isExpertMode()) setUiMode('expert'); }
      })
    ]);
    var headTools = el('div', { className: 'mmx-head-tools' }, [modeBar]);
    if (isExpertMode()) {
      headTools.appendChild(el('div', { className: 'mmx-actions' }, [
        el('button', { className: 'primary', text: _('run_audit'), onClick: runAudit }),
        el('button', { text: _('export_html'), onClick: function () { exportFile('export_html'); } }),
        el('button', { text: _('export_md'), onClick: function () { exportFile('export_md'); } })
      ]));
    }
    chrome.appendChild(el('div', { className: 'mmx-head' }, [
      el('div', { className: 'mmx-brand' }, [
        el('div', { className: 'mmx-mark', 'aria-hidden': 'true' }),
        el('div', { className: 'mmx-brand-text' }, [
          el('h1', { text: _('title', 'MigrateMODX') }),
          el('p', { text: isExpertMode()
            ? _('subtitle', 'MODX 2 → 3 · packages, PHP, Fenom, snapshot')
            : _('subtitle_simple', 'MODX 2 → 3 · guided migrate') })
        ])
      ]),
      headTools
    ]));

    if (isExpertMode()) {
      var tabs = [
        ['overview', _('tab_overview')],
        ['migrate', _('tab_migrate', 'Migrate')],
        ['packages', _('tab_packages')],
        ['env', _('tab_env')],
        ['code', _('tab_code')],
        ['php', _('tab_php')],
        ['templates', _('tab_templates')],
        ['plan', _('tab_plan')],
        ['snapshot', _('tab_snapshot')],
        ['journal', _('tab_journal')]
      ];
      var tabBar = el('div', { className: 'mmx-tabs' });
      tabs.forEach(function (t) {
        tabBar.appendChild(el('button', {
          className: 'mmx-tab' + (state.tab === t[0] ? ' active' : ''),
          text: t[1],
          onClick: function () {
            if (state.busy) return;
            state.tab = t[0];
            if (t[0] === 'migrate' && !state.migrateStatusLoaded) {
              render();
              loadMigrateStatus();
              return;
            }
            render();
          }
        }));
      });
      chrome.appendChild(tabBar);
    }
    root.appendChild(chrome);

    var scroll = el('div', { className: 'mmx-scroll', id: 'mmx-scroll' });
    var panel = el('div', { className: 'mmx-panel active' });
    scroll.appendChild(panel);
    root.appendChild(scroll);

    if (state.tab === 'migrate' || !isExpertMode()) {
      renderMigrate(panel);
      syncBusyUi();
      fitShell();
      return;
    }
    if (state.tab === 'snapshot') {
      renderSnapshot(panel, state.report || { recovery: state.recoveryStatus || {} });
      syncBusyUi();
      fitShell();
      return;
    }
    if (state.tab === 'journal') {
      renderJournal(panel);
      syncBusyUi();
      fitShell();
      return;
    }
    if (!state.report) {
      var inProgress = hasMigrateProgress() || !!state.migrateAttention;
      panel.appendChild(el('div', { className: 'mmx-card mmx-empty' }, [
        el('h2', { text: inProgress
          ? _('empty_in_progress_title', 'Migration already started')
          : _('empty_title', _('title', 'MigrateMODX')) }),
        el('p', { text: inProgress
          ? _('empty_in_progress_body', 'Audit report is not loaded in this session (page reload or post-core PHP). Open Migrate for phases, or Snapshot & Recovery for the emergency exit.')
          : _('intro') }),
        el('div', { className: 'mmx-actions' }, [
          el('button', { className: 'primary', text: _('run_audit'), onClick: runAudit }),
          el('button', { text: _('tab_migrate', 'Migrate'), onClick: function () {
            state.tab = 'migrate';
            render();
            if (!state.migrateStatusLoaded) loadMigrateStatus();
          } }),
          el('button', { text: _('tab_snapshot', 'Snapshot & Recovery'), onClick: function () {
            state.tab = 'snapshot';
            render();
          } }),
          el('button', { text: _('mode_simple', 'Simple'), onClick: function () { setUiMode('simple'); } })
        ])
      ]));
      syncBusyUi();
      fitShell();
      return;
    }
    var r = state.report;
    if (state.tab === 'overview') renderOverview(panel, r);
    else if (state.tab === 'packages') renderPackages(panel, r);
    else if (state.tab === 'env') renderEnv(panel, r);
    else if (state.tab === 'code') renderCode(panel, r);
    else if (state.tab === 'php') renderPhp(panel, r);
    else if (state.tab === 'templates') renderTemplates(panel, r);
    else if (state.tab === 'plan') renderPlan(panel, r);
    syncBusyUi();
    fitShell();
  }

  function applyMigrateGuide(out) {
    if (!out) return;
    if (out.run) state.migrateRun = out.run;
    if (out.attention) state.migrateAttention = out.attention;
    if (out.report) state.report = out.report;
    if (out.recovery) {
      state.recoveryStatus = out.recovery;
      if (out.recovery.password) {
        state.recoverySecret = normalizeRecoveryCreds(out.recovery);
      }
    }
    if (out.phase != null) state.migratePhase = out.phase;
    state.migrateNext = out.next_actions || [];
    if (out.has_ms2 != null) state.migrateHasMs2 = !!out.has_ms2;
    if (out.ms3_installed != null) state.migrateMs3Installed = !!out.ms3_installed;
    if (out.modx_major != null) state.migrateModxMajor = out.modx_major;
    if (out.modx_file_major != null) state.migrateModxFileMajor = out.modx_file_major;
    if (out.modx_db_major != null) state.migrateModxDbMajor = out.modx_db_major;
    if (out.setup_pending != null) state.migrateSetupPending = !!out.setup_pending;
    if (out.goal) state.migrateGoal = out.goal;
    else if (out.run && out.run.goal) state.migrateGoal = out.run.goal;
    if (out.php_ready != null) state.migratePhpReady = !!out.php_ready;
    if (out.post_rollback && (out.post_rollback.reset || (out.post_rollback.audit && out.post_rollback.audit.ok))) {
      state.postRollbackNotice = out.post_rollback;
    }
  }

  function maybeShowRecoveryCreds(out) {
    if (out && out.recovery && out.recovery.password) {
      showRecoveryCredsModal(out.recovery);
      return true;
    }
    return false;
  }

  function hasMigrateProgress() {
    var run = state.migrateRun;
    if (!run) return false;
    return !!(run.core_applied_at || run.finished_at || run.upgrade_ready
      || run.pre_apply_snapshot_id || (run.steps && run.steps.length));
  }

  function setMigrateGoal(goal) {
    setBusy(true, _('busy_migrate_status', 'Loading migrate status…'));
    api('migrate_set_goal', { goal: goal }).then(function (out) {
      applyMigrateGuide(out);
      state.migrateGoal = goal;
      render();
    }).catch(showError).finally(function () { setBusy(false); });
  }

  function stepStatusLabel(st) {
    if (st === 'ok' || st === 'done') return _('migrate_st_done', 'Done');
    if (st === 'failed') return _('migrate_st_failed', 'Failed');
    if (st === 'running') return _('migrate_st_running', 'Running');
    if (st === 'current') return _('migrate_st_current', 'Current');
    return _('migrate_st_todo', 'Not done');
  }

  function statusBadge(st) {
    var tone = 'todo';
    if (st === 'ok' || st === 'done') tone = 'done';
    else if (st === 'failed') tone = 'failed';
    else if (st === 'running' || st === 'current') tone = 'current';
    return el('span', { className: 'mmx-st mmx-st-' + tone, text: stepStatusLabel(st) });
  }

  function phaseTitleRow(title, st) {
    return el('div', { className: 'mmx-phase-title-row' }, [
      el('h3', { className: 'mmx-card-title', text: title }),
      statusBadge(st)
    ]);
  }

  /** Derive high-level phase completion from run journal. */
  function migratePhaseStates(run, phase, goal, major) {
    var runGoal = (run && run.goal) || '';
    var finished = !!(run && run.finished_at && runGoal === goal);
    var hardFail = !!(run && (run.hard_blocked || run.status === 'failed'));
    var setupPending = !!state.migrateSetupPending;
    var fileMajor = state.migrateModxFileMajor != null ? Number(state.migrateModxFileMajor) : major;
    var dbMajor = state.migrateModxDbMajor != null ? Number(state.migrateModxDbMajor) : 0;
    var live3 = !setupPending && major >= 3;
    var coreOk = !!(run && run.core_applied_at) || fileMajor >= 3 || live3;
    var ms3Ok = !!(run && run.ms3_data_ok);
    var p1 = 'todo';
    var p2 = 'todo';
    var p3 = 'todo';
    if (goal === 'php_on_2') {
      if (run && run.goal === 'php_on_2' && run.finished_at && !hardFail) p1 = 'done';
      else if (run && run.goal === 'php_on_2' && hardFail) p1 = 'failed';
      else p1 = 'current';
      return { p1: p1, p2: 'todo', p3: 'todo', php: p1 };
    }
    if (runGoal === 'modx3' && finished && hardFail) p1 = 'failed';
    else if (runGoal === 'modx3' && finished) p1 = 'done';
    else if (goal === 'modx3') p1 = 'current';

    /* Files applied ≠ /setup/ done — keep Phase 2 current until DB settings_version is 3.x. */
    if (live3 && !setupPending) p2 = 'done';
    else if (coreOk && setupPending) p2 = 'current';
    else if (coreOk) p2 = 'done';
    else if (p1 === 'done') p2 = 'current';
    else p2 = 'todo';

    if (ms3Ok || (live3 && !state.migrateHasMs2 && coreOk)) p3 = 'done';
    else if (live3) p3 = 'current';
    else if (coreOk || setupPending || dbMajor === 2) p3 = 'todo';
    else p3 = 'todo';

    return { p1: p1, p2: p2, p3: p3, php: 'todo' };
  }

  function renderPrepareStepList(steps) {
    var expected = [
      ['snapshot_recovery', _('migrate_step_snap', 'Snapshot + Recovery')],
      ['full_audit', _('migrate_step_audit', 'Full audit')],
      ['php_keepers', _('migrate_step_php', 'PHP keepers autofix')],
      ['modx3_rewrites', _('migrate_step_rewrites', 'MODX 3 / ms2 rewrites')],
      ['package_matrix', _('migrate_step_matrix', 'Package matrix')],
      ['vendor_compat', _('migrate_step_vendor', 'Vendor port / quarantine')],
      ['ms2_ms3_data', _('migrate_step_ms3dry', 'ms2→ms3 dry-run')],
      ['re_audit_report', _('migrate_step_report', 'Re-audit + Attention Report')],
      ['php_rescan', _('migrate_step_phprescan', 'PHP remaining scan')]
    ];
    var byId = {};
    (steps || []).forEach(function (s) {
      if (s && s.id) byId[s.id] = s;
    });
    var ul = el('ul', { className: 'mmx-step-status-list' });
    expected.forEach(function (row) {
      if (!byId[row[0]] && row[0] === 'php_rescan' && !(steps || []).some(function (s) { return s.id === 'php_rescan'; })) {
        return;
      }
      if (!byId[row[0]] && row[0] === 'modx3_rewrites' && (steps || []).some(function (s) { return s.id === 'php_rescan'; })) {
        return;
      }
      var s = byId[row[0]];
      var st = s ? (s.status || 'todo') : 'todo';
      if (st === 'ok') st = 'done';
      ul.appendChild(el('li', { className: 'mmx-step-status-item mmx-st-row-' + st }, [
        statusBadge(st),
        el('span', { text: row[1] })
      ]));
    });
    return ul;
  }

  function renderMigrate(panel) {
    var att = state.migrateAttention || {};
    var run = state.migrateRun || {};
    var steps = run.steps || [];
    var phase = state.migratePhase || 1;
    var goal = state.migrateGoal || 'modx3';
    /* Live major from migrate_status wins. Do not promote to 3 from file/audit while /setup/ is pending. */
    var major = state.migrateModxMajor != null ? Number(state.migrateModxMajor) : 2;
    if (!major || major < 1) major = 2;
    if (!state.migrateSetupPending && state.report && state.report.environment && state.report.environment.modx_version) {
      var mv = String(state.report.environment.modx_version);
      if (/^3([\D.]|$)/.test(mv)) major = Math.max(major, 3);
    }
    var pst = migratePhaseStates(run, phase, goal, major);
    var prepDone = pst.p1 === 'done';

    var goalBar = el('div', { className: 'mmx-phase-bar mmx-goal-bar' }, [
      el('button', {
        type: 'button',
        className: 'mmx-phase-chip' + (goal === 'php_on_2' ? ' active' : ''),
        text: _('migrate_goal_php', 'Goal A · Stay on MODX 2 → PHP 8.x'),
        onClick: function () { setMigrateGoal('php_on_2'); }
      }),
      el('button', {
        type: 'button',
        className: 'mmx-phase-chip' + (goal === 'modx3' ? ' active' : ''),
        text: _('migrate_goal_modx3', 'Goal B · Move to MODX 3'),
        onClick: function () { setMigrateGoal('modx3'); }
      })
    ]);

    var nextBox = el('div', { className: 'ok-box' });
    nextBox.appendChild(el('strong', { text: _('migrate_do_now', 'Do now') }));
    var nextUl = el('ul');
    (state.migrateNext || []).slice(0, 3).forEach(function (a) {
      nextUl.appendChild(el('li', { text: a.message || a.id || '' }));
    });
    if (!(state.migrateNext || []).length) {
      nextUl.appendChild(el('li', { text: _('migrate_do_refresh', 'Refresh status or pick a goal above.') }));
    }
    nextBox.appendChild(nextUl);

    var honesty = el('div', { className: 'warn-box' }, [
      el('p', { text: _('migrate_positioning', 'Prefight + inventory — not a few-click migration, not a replacement for neighbour MODX 3 + agent + playbooks.') }),
      el('p', { text: _('migrate_auto', 'Auto: PHP keepers, known rewrites, package matrix, ms2 dry-run, snapshot/recovery, Attention Report.') }),
      el('p', { text: _('migrate_not_auto', 'Not auto: MiniShop3 install (only after MODX 3), theme 1:1, unique custom, packages without MODX 3 on repo.') })
    ]);

    var trackA = el('div', {
      className: 'mmx-phase-panel'
        + (goal === 'php_on_2' ? ' current' : '')
        + (goal === 'php_on_2' && pst.php === 'done' ? ' is-done' : '')
        + (goal !== 'php_on_2' ? ' mmx-dim' : '')
    }, [
      phaseTitleRow(_('migrate_goal_php', 'Goal A · Stay on MODX 2 → PHP 8.x'), goal === 'php_on_2' ? pst.php : 'todo'),
      el('p', { className: 'muted', text: _('migrate_goal_php_body', 'Snapshot, audit, PHP keepers autofix, remaining deprecates. No core 3 apply. Raise host PHP when ready.') }),
      el('div', { className: 'mmx-actions' }, [
        el('button', {
          className: (pst.php === 'done' || pst.php === 'failed') ? '' : 'primary',
          text: (pst.php === 'done' || pst.php === 'failed')
            ? _('migrate_prepare_php_again', 'Re-run PHP track')
            : _('migrate_prepare_php', 'Prepare PHP track'),
          disabled: goal !== 'php_on_2',
          onClick: runMigratePreparePhp
        })
      ]),
      goal === 'php_on_2'
        ? el('div', { className: 'mmx-step-status-wrap' }, [
          el('strong', { text: _('migrate_steps_checklist', 'Prepare steps') }),
          renderPrepareStepList(steps)
        ])
        : el('p', { className: 'muted', text: _('migrate_switch_goal_a', 'Switch to Goal A to use the PHP-on-2 track.') }),
      goal === 'php_on_2' && state.migratePhpReady
        ? el('p', { className: 'muted', text: _('migrate_php_ready_yes', 'php_ready: yes (or remaining only in Attention).') })
        : null
    ]);

    var phaseBar = el('div', { className: 'mmx-phase-bar' });
    [
      [1, _('migrate_phase1', 'Phase 1 · Prepare (MODX 2)'), pst.p1],
      [2, withVer(_('migrate_phase2', 'Phase 2 · Core 3.0.3')), pst.p2],
      [3, _('migrate_phase3', 'Phase 3 · After MODX 3'), pst.p3]
    ].forEach(function (p) {
      var st = goal === 'modx3' ? p[2] : 'todo';
      var chip = el('div', {
        className: 'mmx-phase-chip'
          + (st === 'current' ? ' active' : '')
          + (st === 'done' ? ' done' : '')
          + (st === 'failed' ? ' failed' : '')
      });
      chip.appendChild(el('span', { text: p[1] }));
      chip.appendChild(statusBadge(st));
      phaseBar.appendChild(chip);
    });

    var phase1 = el('div', { className: 'mmx-phase-panel'
      + (goal === 'modx3' && pst.p1 === 'current' ? ' current' : '')
      + (goal === 'modx3' && pst.p1 === 'done' ? ' is-done' : '')
      + (goal === 'modx3' && pst.p1 === 'failed' ? ' is-failed' : '') }, [
      phaseTitleRow(_('migrate_phase1', 'Phase 1 · Prepare (MODX 2)'), goal === 'modx3' ? pst.p1 : 'todo'),
      el('p', { className: 'muted', text: _('migrate_phase1_body', 'Snapshot, audit, PHP keepers, rewrites, package matrix, ms2→ms3 dry-run only. No core apply. Do not install MiniShop3 yet.') }),
      el('div', { className: 'mmx-actions' }, [
        el('button', {
          className: (prepDone || pst.p1 === 'failed') ? '' : 'primary',
          text: (prepDone || pst.p1 === 'failed')
            ? _('migrate_prepare_again', 'Re-run prepare')
            : _('migrate_prepare', 'Prepare migration'),
          disabled: goal !== 'modx3',
          onClick: runMigratePrepare
        })
      ]),
      goal === 'modx3'
        ? el('div', { className: 'mmx-step-status-wrap' }, [
          el('strong', { text: _('migrate_steps_checklist', 'Prepare steps') }),
          renderPrepareStepList(steps)
        ])
        : null
    ]);

    var coreAppliedUi = !!(run && run.core_applied_at)
      || (state.migrateModxFileMajor != null && Number(state.migrateModxFileMajor) >= 3)
      || !!state.migrateSetupPending;
    var phase2 = el('div', { className: 'mmx-phase-panel'
      + (goal === 'modx3' && pst.p2 === 'current' ? ' current' : '')
      + (goal === 'modx3' && pst.p2 === 'done' ? ' is-done' : '') }, [
      phaseTitleRow(withVer(_('migrate_phase2', 'Phase 2 · Core 3.0.3')), goal === 'modx3' ? pst.p2 : 'todo'),
      el('p', { className: 'muted', text: _('migrate_phase2_body', 'After Prepare is clean: apply core on staging, then open /setup/ and finish the wizard.') }),
      el('div', { className: 'mmx-actions' }, [
        el('button', {
          className: 'primary',
          text: (pst.p2 === 'done' || coreAppliedUi)
            ? _('migrate_apply_core_done', 'Core already applied')
            : withVer(_('migrate_apply_core', 'Apply core 3.0.3 (staging)')),
          disabled: goal !== 'modx3' || major >= 3 || pst.p2 === 'done' || coreAppliedUi || pst.p1 !== 'done',
          onClick: runMigrateApplyCore
        })
      ]),
      pst.p1 !== 'done' && goal === 'modx3'
        ? el('p', { className: 'muted', text: _('migrate_need_phase1', 'Finish Phase 1 first (status must be Done).') })
        : null,
      major >= 3 && !state.migrateSetupPending
        ? el('p', { className: 'muted', text: _('migrate_already_3', 'Site already on MODX 3 — skip to Phase 3.') })
        : el('p', { className: 'muted', text: _('migrate_setup_hint', 'After file apply: open /setup/ in the browser before installing extras.') })
    ]);

    function flagRow(checked, onToggle, label) {
      var lab = el('label', { className: 'mmx-flag' });
      var inp = el('input', {
        type: 'checkbox',
        checked: !!checked,
        onChange: function (e) { onToggle(!!e.target.checked); }
      });
      lab.appendChild(inp);
      lab.appendChild(document.createTextNode(' ' + label));
      return lab;
    }

    var phase3Kids = [
      phaseTitleRow(_('migrate_phase3', 'Phase 3 · After MODX 3'), goal === 'modx3' ? pst.p3 : 'todo'),
      el('p', { className: 'muted', text: _('migrate_phase3_body', 'Install MiniShop3 only on MODX 3, then apply catalog/orders data. Uninstall miniShop2 after verify.') })
    ];
    if (major < 3 || state.migrateSetupPending) {
      var lockMsg = (state.migrateSetupPending || (state.migrateRun && state.migrateRun.core_applied_at))
        ? _('migrate_phase3_need_setup', 'Core files are applied, but MODX still reports 2.x — finish /setup/ (or refresh Migrate status after setup). Then Phase 3 unlocks.')
        : _('migrate_phase3_locked', 'Locked until the site is on MODX 3 (finish Phase 2 + /setup/).');
      phase3Kids.push(el('div', { className: 'warn-box', text: lockMsg }));
      phase3Kids.push(el('div', { className: 'mmx-actions' }, [
        el('button', {
          text: _('migrate_refresh_status', 'Refresh migrate status'),
          onClick: function () { loadMigrateStatus(); }
        })
      ]));
    } else if (state.migrateHasMs2) {
      phase3Kids.push(el('p', { text: state.migrateMs3Installed
        ? _('migrate_ms3_detected', 'MiniShop3 detected.')
        : _('migrate_ms3_install_now', 'Use «Install/update packages» to download MiniShop3 (+ deps) and outdated extras, then confirm below.') }));
      phase3Kids.push(el('div', { className: 'mmx-migrate-flags' }, [
        flagRow(state.migrateConfirmMs3, function (v) { state.migrateConfirmMs3 = v; },
          _('migrate_confirm_ms3', 'MiniShop3 is installed — allow data migrate apply'))
      ]));
      phase3Kids.push(el('div', { className: 'mmx-actions' }, [
        el('button', {
          className: state.migrateMs3Installed ? '' : 'primary',
          text: _('migrate_install_packages', 'Install/update packages (MS3 + outdated)'),
          onClick: runMigrateInstallPackages
        }),
        el('button', {
          className: state.migrateMs3Installed ? 'primary' : '',
          text: pst.p3 === 'done'
            ? _('migrate_ms3_data_done', 'Data migrate done — re-run if needed')
            : _('migrate_apply_ms3_data', 'Apply ms2→ms3 data'),
          onClick: runMigrateApplyMs3Data
        }),
        el('button', {
          text: _('migrate_retire_ms2', 'Retire miniShop2 (fix front Fatal)'),
          onClick: runMigrateRetireMs2
        }),
        el('button', {
          text: _('migrate_repair_catalog', 'Repair catalog (class_key + ms3 data)'),
          onClick: runMigrateRepairCatalog
        }),
        el('button', {
          text: _('migrate_heal_front', 'Heal front (counts, thumbs, minicart)'),
          onClick: runMigrateHealFront
        })
      ]));
      phase3Kids.push(el('p', { className: 'muted', text: _('migrate_retire_ms2_hint', 'Never uninstall miniShop2 via Package Management. Use Retire. Install/update packages pulls successors + outdated builds from providers. Zero counts / missing thumbs / broken minicart — Heal front.') }));
    } else {
      phase3Kids.push(el('p', { className: 'muted', text: _('migrate_no_ms2', 'No miniShop2 — use health check and finish remaining package updates.') }));
      if (state.migrateMs3Installed) {
        phase3Kids.push(el('div', { className: 'mmx-actions' }, [
          el('button', {
            text: _('migrate_retire_ms2', 'Retire miniShop2 (fix front Fatal)'),
            onClick: runMigrateRetireMs2
          }),
          el('button', {
            className: 'primary',
            text: _('migrate_repair_catalog', 'Repair catalog (class_key + ms3 data)'),
            onClick: runMigrateRepairCatalog
          }),
          el('button', {
            text: _('migrate_heal_front', 'Heal front (counts, thumbs, minicart)'),
            onClick: runMigrateHealFront
          })
        ]));
        phase3Kids.push(el('p', { className: 'muted', text: _('migrate_repair_catalog_hint', 'Empty catalog after PM uninstall? Repair converts remaining class_keys and fills ms3_products. If resources were deleted — restore a snapshot. Heal front fixes theme class_key queries, listing thumbs, minicart, phpThumbOn.') }));
      }
    }
    if (goal !== 'modx3') {
      phase3Kids.push(el('p', { className: 'muted', text: _('migrate_switch_goal_b', 'Switch to Goal B to use MODX 3 phases.') }));
    }
    var phase3 = el('div', { className: 'mmx-phase-panel'
      + (goal === 'modx3' && pst.p3 === 'current' ? ' current' : '')
      + (goal === 'modx3' && pst.p3 === 'done' ? ' is-done' : '') }, phase3Kids);

    var sharedActions = el('div', { className: 'mmx-actions' }, [
      el('button', { text: _('migrate_refresh', 'Refresh status'), onClick: loadMigrateStatus }),
      el('button', {
        text: _('migrate_copy_report', 'Copy Attention Report'),
        onClick: function () {
          var md = (att && att.agent_export) ? att.agent_export : JSON.stringify(att || {}, null, 2);
          copyText(md);
        }
      }),
      el('button', {
        text: _('migrate_export_report', 'Export Attention MD'),
        onClick: function () { exportFile('migrate_export_attention'); }
      })
    ]);

    var trackB = el('div', { className: goal === 'modx3' ? '' : 'mmx-dim' }, [
      el('h3', { className: 'mmx-card-title', text: _('migrate_goal_modx3', 'Goal B · Move to MODX 3') }),
      phaseBar,
      phase1,
      phase2,
      phase3
    ]);
    var migrateKids = [
      el('p', { className: 'muted', text: _('migrate_intro', 'Pick a goal first. Prefight inventory — not a few-click full migration.') })
    ];
    if (state.postRollbackNotice) {
      migrateKids.push(el('div', { className: 'ok-box' }, [
        el('strong', { text: _('migrate_post_rollback_title', 'After rollback') }),
        el('p', { text: _('migrate_post_rollback_body', 'Migrate progress was reset and audit re-ran automatically. Start again from Phase 1.') })
      ]));
    }
    migrateKids.push(goalBar, nextBox, honesty, trackA, trackB, sharedActions);
    panel.appendChild(card(_('tab_migrate', 'Migrate'), migrateKids));

    if (run.status) {
      panel.appendChild(card(_('migrate_run_summary', 'Last run'), [
        el('p', { text: _('migrate_status', 'Status') + ': ' + run.status
          + (run.goal ? ' · goal=' + run.goal : '')
          + (run.upgrade_ready ? ' · ' + _('migrate_ready', 'upgrade_ready') : '')
          + (run.hard_blocked ? ' · ' + _('migrate_blocked', 'hard blocked') : '')
          + (run.finished_at ? ' · ' + run.finished_at : '') })
      ]));
    }

    function listCard(title, items, tone) {
      var box = el('div', { className: tone === 'bad' ? 'err-box' : (tone === 'warn' ? 'warn-box' : 'ok-box') });
      box.appendChild(el('strong', { text: title + ' (' + (items || []).length + ')' }));
      var ul = el('ul');
      (items || []).slice(0, 80).forEach(function (it) {
        ul.appendChild(el('li', { text: (it.id ? it.id + ': ' : '') + (it.message || JSON.stringify(it)) }));
      });
      box.appendChild(ul);
      return box;
    }

    if (att && (att.done || att.failed || att.needs_attention || att.known || att.unknown)) {
      var attKids = [
        el('p', { className: 'muted', text: (att.generated_at || '') + (att.goal ? ' · goal=' + att.goal : '') }),
        el('p', { className: 'muted', text: _('migrate_attention_disclaimer', 'Inventory/flags only — «done» means a scanner step finished, not that custom code was understood.') }),
        listCard(_('migrate_known', 'Known (deterministic)'), att.known || [], 'ok'),
        listCard(_('migrate_unknown', 'Unknown / needs agent'), att.unknown || [], 'warn'),
        listCard(_('migrate_failed', 'Failed'), att.failed || [], 'bad')
      ];
      if (att.schema_notes && (att.schema_notes.advice || _('migrate_schema_advice', ''))) {
        attKids.push(el('div', { className: 'warn-box' }, [
          el('strong', { text: _('migrate_schema_title', 'DB / schema advice') }),
          el('p', { text: _('migrate_schema_advice', att.schema_notes.advice || '') })
        ]));
      }
      panel.appendChild(card(_('migrate_attention', 'Attention Report'), attKids));

      var pkgs = att.packages || [];
      if (pkgs.length) {
        var table = el('table');
        table.appendChild(el('tr', null, [
          el('th', { text: _('col_package') }),
          el('th', { text: _('migrate_installed', 'Installed') }),
          el('th', { text: _('migrate_repo_latest', 'Repo latest') }),
          el('th', { text: 'MODX 3' }),
          el('th', { text: _('migrate_successor', 'Successor') }),
          el('th', { text: _('migrate_conflicts', 'Conflicts') })
        ]));
        pkgs.forEach(function (p) {
          table.appendChild(el('tr', null, [
            el('td', { text: p.name || '' }),
            el('td', { text: p.installed || '' }),
            el('td', { text: p.repo_latest || '' }),
            el('td', { text: p.modx3_ok ? _('yes') : _('no') }),
            el('td', { text: p.successor || '' }),
            el('td', { text: (p.conflicts || []).join(', ') })
          ]));
        });
        panel.appendChild(card(_('migrate_matrix', 'Package matrix'), [table]));
      }

      if (att.ms3 && Object.keys(att.ms3).length) {
        panel.appendChild(card(_('migrate_ms3', 'ms2→ms3 data'), [
          el('pre', { text: JSON.stringify(att.ms3, null, 2) })
        ]));
      }

      if (att.post_upgrade_diff) {
        panel.appendChild(card(_('migrate_post_diff', 'Post-upgrade diff'), [
          el('pre', { text: JSON.stringify(att.post_upgrade_diff, null, 2) })
        ]));
      }
    }
  }

  function loadMigrateStatus() {
    setBusy(true, _('busy_migrate_status', 'Loading migrate status…'));
    return api('migrate_status').then(function (out) {
      applyMigrateGuide(out);
      state.migrateStatusLoaded = true;
      render();
    }).catch(showError).finally(function () { setBusy(false); });
  }

  function bootstrapWorkspace() {
    if (!isExpertMode()) {
      state.tab = 'migrate';
    }
    setBusy(true, _('busy_migrate_status', 'Loading migrate status…'));
    return api('migrate_status').then(function (out) {
      applyMigrateGuide(out);
      state.migrateStatusLoaded = true;
      state.bootstrapped = true;
      if (!isExpertMode()) {
        state.tab = 'migrate';
      } else if (hasMigrateProgress() && state.tab === 'overview' && !state.report) {
        state.tab = 'migrate';
      } else if (hasMigrateProgress() && (state.migratePhase >= 2 || (state.migrateRun && state.migrateRun.core_applied_at))) {
        if (state.tab === 'overview') state.tab = 'migrate';
      }
      render();
    }).catch(function () {
      state.bootstrapped = true;
      render();
    }).finally(function () { setBusy(false); });
  }

  function runMigratePrepare() {
    if (!confirm(_('migrate_prepare_confirm', 'Run Phase 1 Prepare? Snapshot + Recovery, audit, PHP keepers, rewrites, package matrix, ms2 dry-run. Core and MiniShop3 data are NOT applied.'))) {
      return;
    }
    setBusy(true, _('busy_migrate_prepare', 'Preparing migration…'));
    api('migrate_prepare', {}).then(function (out) {
      applyMigrateGuide(out);
      state.migrateGoal = 'modx3';
      state.migrateStatusLoaded = true;
      state.tab = 'migrate';
      render();
      maybeShowRecoveryCreds(out);
      if (!out.ok) {
        alert(out.error || _('migrate_prepare_issues', 'Prepare finished with issues — see Attention Report'));
      }
    }).catch(showError).finally(function () { setBusy(false); });
  }

  function runMigratePreparePhp() {
    if (!confirm(_('migrate_prepare_php_confirm', 'Run PHP track on MODX 2? Snapshot, audit, keepers autofix, remaining deprecates. Core 3 is NOT applied.'))) {
      return;
    }
    setBusy(true, _('busy_migrate_prepare_php', 'Preparing PHP track…'));
    api('migrate_prepare_php', {}).then(function (out) {
      applyMigrateGuide(out);
      state.migrateGoal = 'php_on_2';
      state.migrateStatusLoaded = true;
      state.tab = 'migrate';
      render();
      maybeShowRecoveryCreds(out);
      if (!out.ok) {
        alert(out.error || _('migrate_prepare_issues', 'Prepare finished with issues — see Attention Report'));
      }
    }).catch(showError).finally(function () { setBusy(false); });
  }

  function runMigrateApplyCore() {
    var ver = targetModx();
    if (cfg.requireStaging && !confirm(_('confirm_staging'))) return;
    if (!confirm(withVer(_('migrate_apply_core_confirm', 'Apply MODX 3.0.3 files on this staging site? This replaces core files.')))) return;
    if (!confirm(_('confirm_apply'))) return;
    setBusy(true, withVer(_('busy_migrate_core', 'Applying core 3.0.3…')));
    api('migrate_apply_core', {
      confirm_staging: true,
      confirm_apply: true,
      version: ver,
      force: !!(state.migrateRun && !state.migrateRun.upgrade_ready)
    }).then(function (out) {
      applyMigrateGuide(out);
      state.tab = 'migrate';
      render();
      if (out.ok) {
        maybeShowRecoveryCreds(out);
        alert(_('migrate_core_done', 'Core apply finished. Next: 1) switch host PHP for this site to ≥8.1/8.2, 2) open /setup/ and finish the wizard, 3) then Phase 3.'));
      } else {
        alert(out.error || 'fail');
      }
    }).catch(showError).finally(function () { setBusy(false); loadJournal(); });
  }

  function runMigrateApplyMs3Data() {
    if ((state.migrateModxMajor || 2) < 3 || state.migrateSetupPending) {
      alert(_('migrate_phase3_locked', 'Locked until the site is on MODX 3 (finish Phase 2 + /setup/).'));
      return;
    }
    if (!state.migrateConfirmMs3) {
      alert(_('migrate_confirm_ms3_required', 'Confirm that MiniShop3 is installed first.'));
      return;
    }
    if (!confirm(_('migrate_apply_ms3_confirm', 'Apply ms2→ms3 catalog/orders data now? miniShop2 will also be retired (quarantine + class_key) so the front stops loading MS2.'))) return;
    setBusy(true, _('busy_migrate_ms3', 'Applying ms2→ms3 data…'));
    api('migrate_apply_ms3_data', { confirm_ms3: true }).then(function (out) {
      applyMigrateGuide(out);
      state.tab = 'migrate';
      render();
      if (out.ok) {
        var msg = _('migrate_ms3_done', 'Data migrate finished — verify shop, then uninstall miniShop2');
        if (out.retire_ms2 && out.retire_ms2.ok) {
          msg += '\n' + _('migrate_ms2_retired_ok', 'miniShop2 retired (class_key + quarantine + cache).');
        } else if (out.retire_ms2 && !out.retire_ms2.ok) {
          msg += '\n' + _('migrate_ms2_retire_needed', 'Retire miniShop2 still needed — use the Phase 3 button.');
        }
        alert(msg);
        return;
      }
      var bits = [];
      if (out.error) bits.push(out.error);
      (out.failed || []).slice(0, 5).forEach(function (f) {
        if (f && f.message && bits.indexOf(f.message) === -1) bits.push(f.message);
      });
      if (out.report_file) bits.push('report: ' + String(out.report_file).split(/[/\\]/).pop());
      alert(bits.length ? bits.join('\n') : (_('migrate_ms3_failed', 'ms2→ms3 data apply failed — see Attention Report')));
    }).catch(showError).finally(function () { setBusy(false); });
  }

  function runMigrateRetireMs2() {
    if ((state.migrateModxMajor || 2) < 3 || state.migrateSetupPending) {
      alert(_('migrate_phase3_locked', 'Locked until the site is on MODX 3 (finish Phase 2 + /setup/).'));
      return;
    }
    if (!confirm(_('migrate_retire_ms2_confirm', 'Retire miniShop2 now? Updates class_key to MiniShop3\\Model\\*, renames minishop2 folders, clears cache. Does NOT delete products.'))) return;
    setBusy(true, _('busy_retire_ms2', 'Retiring miniShop2…'));
    api('migrate_retire_ms2', {}).then(function (out) {
      applyMigrateGuide(out);
      state.tab = 'migrate';
      render();
      alert(out.ok
        ? _('migrate_ms2_retired_ok', 'miniShop2 retired (class_key + quarantine + cache).')
        : (out.error || _('migrate_ms2_retire_failed', 'Retire miniShop2 failed')));
    }).catch(showError).finally(function () { setBusy(false); });
  }

  function runMigrateRepairCatalog() {
    if ((state.migrateModxMajor || 2) < 3 || state.migrateSetupPending) {
      alert(_('migrate_phase3_locked', 'Locked until the site is on MODX 3 (finish Phase 2 + /setup/).'));
      return;
    }
    if (!confirm(_('migrate_repair_catalog_confirm', 'Repair catalog now? Converts msProduct/msCategory → MiniShop3\\Model\\*, fills ms3_products, clears cache. Also runs front heal. Does not delete pages.'))) return;
    setBusy(true, _('busy_repair_catalog', 'Repairing catalog…'));
    api('migrate_repair_catalog', {}).then(function (out) {
      applyMigrateGuide(out);
      state.tab = 'migrate';
      render();
      var inv = (out.repair && out.repair.inventory_after) ? out.repair.inventory_after : null;
      var lines = [];
      if (out.ok) {
        lines.push(_('migrate_repair_catalog_ok', 'Catalog repair finished.'));
      } else {
        lines.push(out.error || _('migrate_repair_catalog_failed', 'Catalog repair incomplete'));
      }
      if (inv) {
        lines.push('MS3 products: ' + (inv.products_ms3_key || 0)
          + ', MS3 categories: ' + (inv.categories_ms3_key || 0)
          + ', ms3_products rows: ' + (inv.ms3_products_rows || 0));
        if (inv.orphan_ms3_data) {
          lines.push('Orphan ms3_products (no resource): ' + inv.orphan_ms3_data);
        }
      }
      if (out.front_compat) {
        lines.push(_('migrate_heal_front_summary', 'Front heal changes: ') + (out.front_compat.changes || 0));
      }
      ((out.repair && out.repair.advice) || []).forEach(function (a) { lines.push(a); });
      alert(lines.join('\n'));
    }).catch(showError).finally(function () { setBusy(false); });
  }

  function runMigrateHealFront() {
    if ((state.migrateModxMajor || 2) < 3 || state.migrateSetupPending) {
      alert(_('migrate_phase3_locked', 'Locked until the site is on MODX 3 (finish Phase 2 + /setup/).'));
      return;
    }
    if (!confirm(_('migrate_heal_front_confirm', 'Heal front now? Rewrites class_key/queries in custom snippets, remaps MS2 shop snippets, bridges msMiniCart, listing thumbs, patches phpThumbOn. Safe journal kept under migratemodx-data/patches.'))) return;
    setBusy(true, _('busy_heal_front', 'Healing front…'));
    api('migrate_heal_front', { apply: 1 }).then(function (out) {
      applyMigrateGuide(out);
      state.tab = 'migrate';
      render();
      var lines = [];
      if (out.ok) {
        lines.push(_('migrate_heal_front_ok', 'Front heal finished.'));
      } else {
        lines.push(out.error || _('migrate_heal_front_failed', 'Front heal incomplete'));
      }
      if (out.front_compat) {
        lines.push(_('migrate_heal_front_summary', 'Front heal changes: ') + (out.front_compat.changes || 0));
        var needs = (out.front_compat.needs_attention || []).length;
        if (needs) {
          lines.push(_('migrate_heal_front_needs', 'Still needs manual theme work: ') + needs);
        }
      }
      alert(lines.join('\n'));
    }).catch(showError).finally(function () { setBusy(false); });
  }

  function runMigrateInstallPackages() {
    if ((state.migrateModxMajor || 2) < 3 || state.migrateSetupPending) {
      alert(_('migrate_phase3_locked', 'Locked until the site is on MODX 3 (finish Phase 2 + /setup/).'));
      return;
    }
    if (!confirm(_('migrate_install_packages_confirm', 'Download and install successors (e.g. MiniShop3 + VueTools/pdoTools) and update outdated MODX-3-ready packages from providers? Does not uninstall anything.'))) return;
    setBusy(true, _('busy_install_packages', 'Installing packages from providers…'));
    api('migrate_install_packages', { confirm_packages: 1, apply: 1 }).then(function (out) {
      applyMigrateGuide(out);
      if (out.ms3_installed) state.migrateConfirmMs3 = true;
      state.tab = 'migrate';
      render();
      var lines = [];
      if (out.ok) {
        lines.push(_('migrate_install_packages_ok', 'Package install/update finished.'));
      } else {
        lines.push(out.error || _('migrate_install_packages_failed', 'Some packages failed to download/install — see Attention (paid/auth packages need Package Management).'));
      }
      var plan = out.plan && out.plan.summary ? out.plan.summary : null;
      if (plan) {
        lines.push(_('migrate_install_packages_summary', 'Plan: ')
          + (plan.installs || 0) + ' install / ' + (plan.updates || 0) + ' update');
      }
      var done = (out.packages && out.packages.done) || [];
      done.slice(0, 12).forEach(function (d) {
        if (d && d.message) lines.push('• ' + d.message);
      });
      var failed = (out.packages && out.packages.failed) || [];
      failed.slice(0, 8).forEach(function (d) {
        if (d && d.message) lines.push('✗ ' + d.message);
      });
      if (out.ms3_installed) {
        lines.push(_('migrate_ms3_detected', 'MiniShop3 detected.'));
      }
      alert(lines.join('\n'));
    }).catch(showError).finally(function () { setBusy(false); });
  }

  function renderOverview(panel, r) {
    var g = r.gate || {};
    var traffic = g.traffic || '';
    var upgradeTone = g.upgrade_ready ? 'ok' : 'bad';
    var phpTone = g.php_ready ? 'ok' : 'warn';
    var trafficTone = traffic === 'green' ? 'ok' : (traffic === 'yellow' ? 'warn' : 'bad');
    var heroRight = el('div', null, [
      el('div', { className: 'mmx-metrics' }, [
        chip(_('traffic'), trafficLabel(traffic), trafficTone),
        chip(_('upgrade_ready'), yn(g.upgrade_ready), upgradeTone),
        chip(_('php_ready'), yn(g.php_ready), phpTone)
      ]),
      el('p', { className: 'muted', text: _('generated') + ' ' + (r.generated_at || '') + ' · ' + _('kb') + ' ' + (r.kb_version || '') })
    ]);
    panel.appendChild(card(_('tab_overview'), [
      el('p', { className: 'muted', text: _('overview_intro', '') }),
      el('div', { className: 'mmx-hero' }, [
        el('div', { className: 'mmx-score-wrap' }, [
          el('div', { className: 'mmx-score ' + traffic, text: String(g.score != null ? g.score : '—') }),
          el('div', { className: 'mmx-score-label', text: _('score_label', 'Readiness') })
        ]),
        heroRight
      ])
    ]));
    var blockers = g.blockers || [];
    if (!blockers.length) {
      panel.appendChild(el('div', { className: 'ok-box' }, [
        el('strong', { text: _('no_blockers') }),
        el('p', { text: _('no_blockers_help', '') })
      ]));
    } else {
      var box = el('div', { className: 'mmx-card' }, [
        el('h3', { className: 'mmx-card-title', text: _('blockers') + ' (' + blockers.length + ')' }),
        el('p', { className: 'muted', text: _('blockers_intro', '') })
      ]);
      var list = el('div', { className: 'mmx-issue-list' });
      blockers.forEach(function (b) { list.appendChild(issueCard(b, 'blocker')); });
      box.appendChild(list);
      panel.appendChild(box);
    }
    if ((g.warnings || []).length) {
      var w = el('div', { className: 'mmx-card' }, [
        el('h3', { className: 'mmx-card-title', text: _('warnings') + ' (' + g.warnings.length + ')' }),
        el('p', { className: 'muted', text: _('warnings_intro', '') })
      ]);
      var wlist = el('div', { className: 'mmx-issue-list' });
      (g.warnings || []).slice(0, 40).forEach(function (x) {
        wlist.appendChild(issueCard(x, 'warning'));
      });
      w.appendChild(wlist);
      panel.appendChild(w);
    }
  }

  function resolutionLabel(p) {
    var res = p.resolution || 'none';
    if (res === 'successor_installed') {
      return _('res_successor_installed', 'Successor installed')
        + (p.resolution_package ? ': ' + p.resolution_package + (p.resolution_version ? ' ' + p.resolution_version : '') : '');
    }
    return human('res_' + res, res);
  }

  function packagePathText(p) {
    var parts = [];
    if (p.successor || (p.alternatives && p.alternatives.length)) {
      var alt = (p.alternatives || []).map(function (a) { return a.name || a.signature; }).filter(Boolean);
      if (p.successor && alt.indexOf(p.successor) === -1) {
        alt.unshift(p.successor_title || p.successor);
      }
      if (alt.length) parts.push('→ ' + alt.join(', '));
    }
    if (p.migration) parts.push(p.migration);
    else if (p.notes) parts.push(p.notes);
    if (p.verdict === 'update' && p.required_version) {
      parts.push(_('pkg_need_version', 'Need version') + ' ≥ ' + p.required_version);
    }
    if (p.repo_found && p.repo_signature) {
      parts.push(_('pkg_repo', 'Repo') + ' ' + (p.repo_source || '') + ': ' + p.repo_signature
        + (p.repo_modx3 ? ' · MODX 3' : ''));
    }
    return parts.join(' · ') || '—';
  }

  function reposStatusLine(r) {
    var repos = (r.repos && r.repos.repos) ? r.repos.repos : {};
    var bits = [];
    Object.keys(repos).forEach(function (id) {
      var x = repos[id] || {};
      if (x.error === 'disabled') return;
      if (x.ok) {
        bits.push(id + ': OK (' + (x.hits || 0) + '/' + (x.checked || 0) + ')');
      } else {
        bits.push(id + ': ' + (x.error || 'down'));
      }
    });
    return bits.length ? bits.join(' · ') : '';
  }

  function renderPackages(panel, r) {
    var sorted = (r.packages || []).slice().sort(function (a, b) {
      var rank = { fail: 0, replace: 1, update: 2, unknown: 3, ok: 4 };
      var ra = rank[a.verdict] != null ? rank[a.verdict] : 5;
      var rb = rank[b.verdict] != null ? rank[b.verdict] : 5;
      if (ra !== rb) return ra - rb;
      return String(a.name).localeCompare(String(b.name));
    });
    var grid = el('div', { className: 'mmx-pkg-grid' });
    sorted.forEach(function (p) {
      var ver = p.version || '—';
      if (p.required_version && p.version_status === 'outdated') {
        ver += ' < ' + p.required_version;
      }
      if (p.repo_latest) {
        ver += ' → repo ' + p.repo_latest;
      }
      var reqBits = [];
      if (p.repo_minimum_supports) reqBits.push('MODX ≥' + p.repo_minimum_supports);
      if (p.repo_php_min) reqBits.push('PHP ≥' + p.repo_php_min);
      if (p.php) reqBits.push('KB PHP ' + p.php);
      if (p.min_modx) reqBits.push('KB MODX ≥' + p.min_modx);
      var title = p.title && p.title !== p.name ? (p.title + ' (' + p.name + ')') : p.name;
      grid.appendChild(el('div', { className: 'mmx-pkg-card' }, [
        el('div', { className: 'mmx-finding-top' }, [
          tagVerdict(p.verdict),
          el('h4', { text: title })
        ]),
        el('p', { className: 'mmx-finding-meta', text: _('col_version') + ': ' + ver }),
        reqBits.length ? el('p', { className: 'mmx-finding-meta', text: _('col_requires', 'Requires') + ': ' + reqBits.join(', ') }) : null,
        el('p', { className: 'mmx-finding-meta', text: resolutionLabel(p) }),
        el('p', { className: 'muted', text: packagePathText(p) })
      ]));
    });
    var repoLine = reposStatusLine(r);
    panel.appendChild(card(_('tab_packages'), [
      el('p', { className: 'muted', text: _('packages_intro', '') }),
      el('p', { className: 'muted', text: _('packages_intro2', '') }),
      el('p', { className: 'muted', text: _('packages_repo_intro', '') }),
      repoLine ? el('p', { className: 'muted', text: _('packages_repo_status', 'Repos') + ': ' + repoLine }) : null,
      grid
    ]));
  }

  function renderEnv(panel, r) {
    var e = r.environment || {};
    var v = r.versions || {};
    var ul = el('ul');
    [
      [_('env_modx', 'MODX'), e.modx_version],
      [_('env_php', 'PHP'), e.php_version],
      [_('env_target_modx', 'Target MODX'), (e.target_modx || v.effective_modx || '—') + (v.modx_mode ? ' (' + v.modx_mode + ')' : '')],
      [_('env_target_php', 'Target PHP'), (e.target_php || v.effective_php || '—') + (v.php_mode ? ' (' + v.php_mode + ')' : '')],
      [_('env_modx_latest', 'Latest MODX 3.x'), v.modx_latest_3 || e.modx_latest_3 || '—'],
      [_('env_req_min_modx', 'Required min MODX'), v.required_min_modx || e.required_min_modx || '—'],
      [_('env_req_min_php', 'Required min PHP'), v.required_min_php || e.required_min_php || '—'],
      [_('env_core_path', 'Core path'), e.core_path],
      [_('env_core_in_root', 'Core in project root'), e.core_in_root ? yn(true) : _('no', 'no')],
      [_('env_memory', 'Memory limit'), e.memory_limit]
    ].forEach(function (row) {
      ul.appendChild(el('li', { text: row[0] + ': ' + row[1] }));
    });
    panel.appendChild(card(_('tab_env'), [
      el('p', { className: 'muted', text: _('env_intro', '') }),
      el('p', { className: 'muted', text: _('env_versions_intro', 'Targets are resolved from live MODX releases + package/docs floors (auto), unless pinned in settings.') }),
      ul
    ]));
    if ((v.package_requirements || []).length) {
      var reqUl = el('ul');
      (v.package_requirements || []).forEach(function (pr) {
        var bits = [pr.name || '?'];
        if (pr.latest) bits.push('latest ' + pr.latest);
        if (pr.min_modx) bits.push('MODX ≥' + pr.min_modx);
        if (pr.min_php) bits.push('PHP ≥' + pr.min_php);
        if (pr.source) bits.push('[' + pr.source + ']');
        reqUl.appendChild(el('li', { text: bits.join(' · ') }));
      });
      panel.appendChild(card(_('env_pkg_reqs', 'Package version floors'), [reqUl]));
    }
    if ((e.issues || []).length) {
      var list = el('div', { className: 'mmx-issue-list' });
      (e.issues || []).forEach(function (i) {
        i.domain = i.domain || 'environment';
        list.appendChild(issueCard(i, i.severity === 'blocker' ? 'blocker' : 'warning'));
      });
      panel.appendChild(card(_('env_issues', 'Environment issues'), [list]));
    }
  }

  function modeSelect(current, onPick) {
    var sel = el('select', { onChange: function (ev) { onPick(ev.target.value); } }, [
      el('option', { value: 'keepers', text: _('filter_keepers', 'Keepers only') }),
      el('option', { value: 'replaced', text: _('filter_replaced', 'Vendor packages (update/replace)') }),
      el('option', { value: 'all', text: _('filter_all', 'All findings') })
    ]);
    sel.value = current;
    return sel;
  }

  function applySourceFilter(list, src) {
    if (!src || src === 'all') return list;
    return list.filter(function (f) { return (f.source || 'other') === src; });
  }

  function renderCode(panel, r) {
    var base = filterFindings(r.heuristics || [], state.codeMode);
    var list = applySourceFilter(base, state.codeSource);
    if (!(r.heuristics || []).length) {
      panel.appendChild(el('div', { className: 'ok-box', text: _('no_heuristics') }));
      return;
    }
    list.forEach(function (h) {
      if (codeHelp(h.code) && !h.message) h.message = codeHelp(h.code);
    });
    var body = el('div');
    appendGroupedFindings(body, list);
    panel.appendChild(card(_('tab_code'), [
      el('p', { className: 'muted', text: _('code_intro', '') }),
      el('p', { className: 'muted', text: _('code_intro2', '') }),
      el('div', { className: 'mmx-filterbar' }, [
        el('label', null, [_('filter_label', 'Show'), modeSelect(state.codeMode, function (v) {
          state.codeMode = v; render();
        })]),
        el('span', { className: 'muted', text: list.length + ' / ' + (r.heuristics || []).length })
      ]),
      sourceFilterBar(base, state.codeSource, function (v) {
        state.codeSource = v; render();
      }),
      body
    ]));
  }

  function renderPhp(panel, r) {
    var all = r.php || [];
    var keepers = filterFindings(all, 'keepers');
    var autofixKeepers = keepers.filter(function (f) { return !!f.autofixable; });
    var selected = Object.keys(state.phpSelected);
    var base = filterFindings(all, state.phpMode);
    var list = applySourceFilter(base, state.phpSource);
    list.forEach(function (h) {
      h.message = human('rule_' + (h.rule || ''), h.message || h.rule || '');
    });
    var body = el('div');
    appendGroupedFindings(body, list, { selectable: true });

    panel.appendChild(card(_('tab_php'), [
      el('p', { text: _('php_intro') }),
      el('p', { className: 'muted', text: _('php_intro2', '') }),
      el('div', { className: 'mmx-stat-row' }, [
        chip(_('php_stat_total', 'Total'), String(all.length)),
        chip(_('php_stat_keepers', 'Keepers'), String(keepers.length), 'ok'),
        chip(_('php_stat_replaced', 'In replace pkgs'), String(filterFindings(all, 'replaced').length), 'warn'),
        chip(_('php_stat_autofix', 'Autofix keepers'), String(autofixKeepers.length), 'ok')
      ]),
      el('div', { className: 'mmx-filterbar' }, [
        el('label', null, [_('filter_label', 'Show'), modeSelect(state.phpMode, function (v) {
          state.phpMode = v; render();
        })]),
        el('button', { text: _('php_select_keepers', 'Select autofix keepers'), onClick: function () {
          state.phpSelected = {};
          autofixKeepers.forEach(function (f) { if (f.uid) state.phpSelected[f.uid] = true; });
          render();
        } }),
        el('button', { text: _('php_clear_sel', 'Clear selection'), onClick: function () {
          state.phpSelected = {}; render();
        } })
      ]),
      sourceFilterBar(base, state.phpSource, function (v) {
        state.phpSource = v; render();
      }),
      el('div', { className: 'mmx-actions' }, [
        el('button', { text: _('php_dryrun_sel', 'Dry-run selected'), onClick: function () {
          phpAction('php_dryrun', phpPayload('all', selected));
        } }),
        el('button', { className: 'primary', text: _('php_apply_sel', 'Apply selected'), onClick: function () {
          if (!selected.length) { alert(_('php_need_sel', 'Select findings first')); return; }
          if (!confirm(_('php_confirm_sel'))) return;
          phpAction('php_apply', phpPayload('all', selected));
        } }),
        el('button', { text: _('php_dryrun_keepers', 'Dry-run keepers (custom+extras)'), onClick: function () {
          phpAction('php_dryrun', phpPayload('all', null, 'keepers'));
        } }),
        el('button', { text: _('php_apply_keepers', 'Apply keepers autofix'), onClick: function () {
          if (!confirm(_('php_confirm_keepers'))) return;
          phpAction('php_apply', phpPayload('all', null, 'keepers'));
        } }),
        el('button', { text: _('php_rollback'), onClick: function () { phpAction('php_rollback', {}); } })
      ]),
      el('p', { className: 'muted', text: _('php_selected', 'Selected') + ': ' + selected.length }),
      body
    ]));
  }

  function phpPayload(scope, targets, mode) {
    var data = {
      scope: scope || 'all',
      vendor_packages: vendorPackages(),
      replaced_packages: vendorPackages()
    };
    if (mode) data.mode = mode;
    else if (targets && targets.length) data.mode = 'all';
    else data.mode = state.phpMode || 'keepers';
    if (targets && targets.length) data.targets = targets;
    return data;
  }

  function phpAction(action, data) {
    setBusy(true, _('busy_php', 'Scanning PHP…'));
    return api(action, data).then(function (out) {
      if (out && out.fixes) {
        alert(_('php_dryrun_count') + ': ' + out.fixes.length);
      } else if (out && out.applied != null) {
        alert(_('php_applied') + ': ' + out.applied);
        state.phpSelected = {};
      } else if (out && out.restored != null) {
        alert(_('php_restored') + ': ' + out.restored);
      } else if (out && out.error) {
        alert(out.error);
      }
      if (action !== 'php_dryrun') return runAudit();
      return out;
    }).catch(showError).finally(function () { setBusy(false); });
  }

  function renderTemplates(panel, r) {
    var all = r.templates || [];
    if (!all.length) {
      panel.appendChild(el('div', { className: 'ok-box', text: _('tpl_ok') }));
      return;
    }
    var base = filterFindings(all, state.tplMode);
    var list = applySourceFilter(base, state.tplSource);
    list.forEach(function (h) {
      h.message = (human('engine_' + (h.engine || ''), h.engine || '') + ': ') + (h.message || '');
    });
    var body = el('div');
    appendGroupedFindings(body, list);
    panel.appendChild(card(_('tab_templates'), [
      el('p', { text: _('tpl_intro') }),
      el('p', { className: 'muted', text: _('tpl_intro2', '') }),
      el('div', { className: 'mmx-filterbar' }, [
        el('label', null, [_('filter_label', 'Show'), modeSelect(state.tplMode, function (v) {
          state.tplMode = v; render();
        })]),
        el('span', { className: 'muted', text: list.length + ' / ' + all.length })
      ]),
      sourceFilterBar(base, state.tplSource, function (v) {
        state.tplSource = v; render();
      }),
      body
    ]));
  }

  function renderPlan(panel, r) {
    var current = (r.plan || {}).current || '';
    var steps = [
      { id: 'prep', title: _('plan_step1_title'), body: _('plan_step1_body') },
      { id: 'packages', title: _('plan_step2_title'), body: _('plan_step2_body') },
      { id: 'snapshot', title: _('plan_step3_title'), body: _('plan_step3_body') },
      { id: '28', title: _('plan_step4_title'), body: _('plan_step4_body') },
      { id: '300', title: _('plan_step5_title'), body: _('plan_step5_body') },
      { id: 'php', title: _('plan_step6_title'), body: _('plan_step6_body') },
      { id: 'health', title: _('plan_step7_title'), body: _('plan_step7_body') }
    ];
    var timeline = el('ol', { className: 'mmx-timeline' });
    steps.forEach(function (s, idx) {
      timeline.appendChild(el('li', { className: 'mmx-timeline-item' }, [
        el('div', { className: 'mmx-timeline-dot', text: String(idx + 1) }),
        el('div', { className: 'mmx-timeline-body' }, [
          el('h4', { text: s.title }),
          el('p', { text: s.body })
        ])
      ]));
    });

    var recovery = el('div', { className: 'mmx-recovery-card' }, [
      el('h4', { text: _('plan_recovery_title') }),
      el('p', { text: _('plan_recovery_body') }),
      el('ul', null, [
        el('li', { text: _('plan_recovery_li1') }),
        el('li', { text: _('plan_recovery_li2') }),
        el('li', { text: _('plan_recovery_li3') }),
        el('li', { text: _('plan_recovery_li4') })
      ])
    ]);

    var autoSteps = el('ul');
    ((r.plan || {}).steps || []).forEach(function (s) {
      var label = human('plan_auto_' + (s.id || ''), s.label || s.id || '');
      autoSteps.appendChild(el('li', { text: label + (s.target ? ' → ' + s.target : '') }));
    });

    panel.appendChild(card(_('tab_plan'), [
      el('p', { className: 'mmx-plan-intro', text: _('plan_intro') }),
      el('p', { text: _('plan_current') + ': ' + (current || '—') }),
      timeline,
      recovery,
      el('h3', { className: 'mmx-card-title', text: _('plan_engine_title') }),
      el('p', { className: 'muted', text: _('plan_engine_intro') }),
      autoSteps,
      el('div', { className: 'warn-box', text: _('plan_hint') }),
      el('div', { className: 'mmx-actions' }, [
        el('button', { text: withVer(_('download_300', 'Download MODX 3.0.3 distro')), onClick: function () {
          setBusy(true, _('busy_download', 'Downloading distro…'));
          api('upgrade_download', { version: targetModx() }).then(function (out) {
            alert(out.ok ? ('OK: ' + out.zip) : (out.error || 'fail'));
            state.lastZip = out.zip;
          }).catch(showError).finally(function () { setBusy(false); });
        } }),
        el('button', { className: 'primary', text: _('apply_distro'), onClick: function () {
          if (cfg.requireStaging && !confirm(_('confirm_staging'))) return;
          if (!confirm(_('confirm_apply'))) return;
          if (!state.lastZip) {
            alert(_('need_download'));
            return;
          }
          setBusy(true, _('busy_apply', 'Applying distro…'));
          api('upgrade_apply', {
            zip: state.lastZip,
            confirm_staging: true,
            confirm_apply: true
          }).then(function (out) {
            alert(out.message || out.error || JSON.stringify(out));
          }).catch(showError).finally(function () { setBusy(false); loadJournal(); });
        } }),
        el('button', { text: _('run_health'), onClick: function () {
          setBusy(true, _('busy_health', 'Running health check…'));
          api('health').then(function (out) {
            alert(_('health_issues') + ': ' + ((out.health && out.health.issues) || []).length);
            console.log(out.health);
          }).catch(showError).finally(function () { setBusy(false); loadJournal(); });
        } })
      ])
    ]));
  }

  function renderSnapshot(panel, r) {
    var rec = state.recoveryStatus || (r && r.recovery) || {};
    if (r && r.recovery && !state.recoveryStatus) rec = r.recovery;
    panel.appendChild(card(_('tab_snapshot'), [
      el('p', { text: _('snap_intro') }),
      el('p', { className: 'muted', text: _('snap_recovery_hint', '') }),
      el('div', { className: 'mmx-actions' }, [
        el('button', { className: 'primary', text: _('snap_create'), onClick: function () {
          setBusy(true, _('busy_snapshot', 'Creating snapshot…'));
          api('snapshot_create', { label: 'pre-upgrade' }).then(function (out) {
            if (out.recovery) {
              state.recoveryStatus = out.recovery;
              if (out.recovery.password) {
                state.recoverySecret = normalizeRecoveryCreds(out.recovery);
                showRecoveryCredsModal(out.recovery);
              }
            }
            return api('recovery_status').then(function (st) {
              if (st.recovery) state.recoveryStatus = st.recovery;
              if (state.report) {
                return runAudit();
              }
              render();
            });
          }).catch(showError).finally(function () { setBusy(false); });
        } }),
        el('button', { text: _('snap_rotate', 'Rotate Recovery password'), onClick: function () {
          if (!confirm(_('snap_rotate_confirm', 'Generate a new Recovery password? The old one will stop working.'))) return;
          setBusy(true, _('busy_working', 'Working…'));
          api('recovery_activate', {}).then(function (out) {
            state.recoveryStatus = out;
            if (out.password) {
              state.recoverySecret = normalizeRecoveryCreds(out);
              showRecoveryCredsModal(out);
            }
            render();
          }).catch(showError).finally(function () { setBusy(false); });
        } }),
        el('button', { text: _('snap_deactivate'), onClick: function () {
          setBusy(true, _('busy_working', 'Working…'));
          api('recovery_deactivate').then(function () {
            state.recoverySecret = null;
            state.recoveryStatus = { active: false };
            render();
          }).catch(showError).finally(function () { setBusy(false); });
        } })
      ])
    ]));

    panel.appendChild(el('div', { className: 'mmx-recovery-card' }, [
      el('h4', { text: _('plan_recovery_title') }),
      el('p', { text: _('plan_recovery_body') }),
      el('ul', null, [
        el('li', { text: _('plan_recovery_li1') }),
        el('li', { text: _('plan_recovery_li2') }),
        el('li', { text: _('plan_recovery_li3') }),
        el('li', { text: _('plan_recovery_li4') }),
        el('li', { text: _('plan_firstaid_li', 'Empty manager after /setup/: open /migratemodx-firstaid.php (one click, no FTP).') })
      ])
    ]));

    if (state.recoverySecret && state.recoverySecret.password) {
      var c = normalizeRecoveryCreds(state.recoverySecret);
      panel.appendChild(el('div', { className: 'warn-box mmx-creds-box' }, [
        el('strong', { text: _('snap_creds') }),
        el('p', { className: 'muted', text: _('snap_save') }),
        copyFieldRow(_('snap_url'), c.url),
        copyFieldRow(_('snap_shortcut'), c.shortcut_url),
        copyFieldRow(_('snap_password'), c.password),
        el('div', { className: 'mmx-actions' }, [
          el('button', {
            text: _('copy_all', 'Copy all'),
            onClick: function () {
              copyText(_('snap_url') + ': ' + c.url + '\n'
                + _('snap_shortcut') + ': ' + c.shortcut_url + '\n'
                + _('snap_password') + ': ' + c.password);
            }
          }),
          el('button', {
            text: _('show_creds_modal', 'Open copy dialog'),
            onClick: function () { showRecoveryCredsModal(c); }
          })
        ])
      ]));
    }

    var active = !!(rec.active || (state.recoveryStatus && state.recoveryStatus.active));
    var activeUrl = rec.url || (rec.url_path ? absoluteUrl(rec.url_path) : '');
    var shortcutUrl = rec.shortcut_url || absoluteUrl(rec.shortcut || 'migratemodx-recovery.php');
    panel.appendChild(card(_('snap_emergency_title', 'Emergency exit'), [
      el('p', { text: _('snap_active') + ': ' + yn(active) }),
      activeUrl ? copyFieldRow(_('snap_url'), activeUrl) : el('p', { className: 'muted', text: _('snap_inactive_hint', 'Recovery is off — create a snapshot or rotate password.') }),
      active ? copyFieldRow(_('snap_shortcut'), shortcutUrl) : null,
      active ? el('p', { className: 'muted', text: _('snap_password_lost', 'Password was shown once at activate. If lost, use “Rotate Recovery password”.') }) : null,
      active && activeUrl ? el('div', { className: 'mmx-actions' }, [
        el('a', {
          href: activeUrl,
          target: '_blank',
          rel: 'noopener',
          className: 'mmx-btn primary',
          text: _('open_recovery', 'Open Recovery')
        })
      ]) : null
    ]));

    var table = el('table');
    table.appendChild(el('tr', null, [
      el('th', { text: 'ID' }), el('th', { text: _('col_created') }), el('th', { text: _('col_actions') })
    ]));
    api('recovery_status').then(function (st) {
      if (st && st.recovery) {
        var hadUrl = !!(state.recoveryStatus && state.recoveryStatus.url);
        state.recoveryStatus = st.recovery;
        if (!hadUrl && st.recovery.url) {
          render();
        }
      }
    }).catch(function () { /* best-effort */ });
    api('snapshot_list').then(function (out) {
      (out.snapshots || []).forEach(function (s) {
        table.appendChild(el('tr', null, [
          el('td', { text: s.id }),
          el('td', { text: s.created_at || '' }),
          el('td', null, [
            el('button', { text: _('verify'), onClick: function () {
              api('snapshot_verify', { id: s.id }).then(function (v) {
                alert(v.ok ? 'OK' : (v.error || 'fail'));
              }).catch(showError);
            } }),
            el('button', { text: _('rollback'), onClick: function () {
              if (!confirm(_('confirm_rollback') + ' (' + s.id + ')')) return;
              setBusy(true, _('busy_rollback', 'Restoring snapshot…'));
              api('rollback', { id: s.id }).then(function (v) {
                alert(v.ok ? _('restored') : ((v.errors || []).join(', ') || v.error || 'fail'));
              }).catch(showError).finally(function () { setBusy(false); });
            } })
          ])
        ]));
      });
      syncBusyUi();
    }).catch(function () { /* list is best-effort */ });
    panel.appendChild(card(null, [table]));
  }

  function renderJournal(panel) {
    loadJournal().then(function () {
      panel.appendChild(card(_('tab_journal'), [
        el('pre', { text: JSON.stringify(state.journal || {}, null, 2) })
      ]));
      syncBusyUi();
    }).catch(showError);
  }

  function loadJournal() {
    return api('journal').then(function (out) {
      state.journal = out.state || null;
      return state.journal;
    });
  }

  function exportFile(action) {
    setBusy(true, _('busy_export', 'Preparing export…'));
    api(action).then(function (out) {
      if (out && out.error) alert(out.error);
    }).catch(showError).finally(function () { setBusy(false); });
  }

  function runAudit() {
    setBusy(true, _('busy_audit', 'Running audit…'), 'audit');
    return api('audit').then(function (out) {
      if (!out || !out.ok) {
        alert((out && out.error) || _('audit_failed'));
        return;
      }
      state.report = out.report;
      state.uiMode = 'expert';
      try {
        if (window.localStorage) localStorage.setItem('migratemodx_ui_mode', 'expert');
      } catch (e) { /* ignore */ }
      state.tab = 'overview';
      render();
    }).catch(showError).finally(function () { setBusy(false); });
  }

  if (typeof window !== 'undefined') {
    window.addEventListener('resize', function () { fitShell(); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapWorkspace);
  } else {
    bootstrapWorkspace();
  }
})();
