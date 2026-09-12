/**
 * Proxy Operations Mini App — chunk entry.
 * Vanilla TypeScript, no framework. Registers via window.__TG_MENU_CHUNK__
 * and exposes window.TgMenu.mount(). §14.1 chunk protocol.
 */
(function () {
  const MODULE_ID = 'proxy';
  const FEATURES = ['context', 'navigation', 'resources', 'haptics', 'fullscreen'];

  // ── Chunk registration ──────────────────────────────────────────────
  window.__TG_MENU_CHUNK__ = { id: MODULE_ID, api: 1, features: [...FEATURES] };

  // ── Bridge types ────────────────────────────────────────────────────
  interface ProxyBridge {
    fetch(url: string, opts?: { method?: string; json?: unknown }): Promise<Record<string, unknown>>;
    haptic?(style: 'success' | 'error' | 'warning' | 'light' | 'medium' | 'heavy'): void;
    navigate?(target: string): void;
    setBackHandler?(handler: () => void): void;
    theme(): Record<string, string>;
    session: { botId: string; userId: number; locale: string };
    chat: { id: number; title: string; type: string } | null;
    searchResources?(type: string, opts: { q: string }): Promise<{ items: { id: string; label: string }[] }>;
    requestFullscreen?(): Promise<void>;
    exitFullscreen?(): Promise<void>;
  }

  interface ProxySnapshot {
    endpoints: number;
    states: Record<string, number>;
    quarantined: number;
  }

  interface Pool {
    id: string;
    name: string;
    kind: string;
    enabled: boolean;
    description: string | null;
    last_materialized_at: string | null;
  }

  interface AuditJob {
    id: string;
    trigger: string;
    status: string;
    result_code: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
  }

  interface Endpoint {
    id: string;
    protocol: string;
    host: string;
    port: number;
    created_at: string;
  }

  interface Settings {
    version: string;
    quotas: Record<string, number>;
    politeness: Record<string, unknown>;
    retention: Record<string, boolean>;
    export_rules: Record<string, unknown>;
    ui_flags: Record<string, boolean>;
  }

  // ── Helpers ─────────────────────────────────────────────────────────
  function el(tag: string, cls?: string, text?: string): HTMLElement {
    const e = document.createElement(tag);
    if (cls !== undefined) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function css(prop: string, val: string): string {
    return `--proxy-${prop}:${val};`;
  }

  const STATE_COLORS: Record<string, string> = {
    working: '#4caf50',
    degraded: '#ff9800',
    failing: '#f44336',
    dead: '#9e9e9e',
    new: '#2196f3',
    testing: '#9c27b0',
    retired: '#795548',
    quarantined: '#ff5722',
  };

  // ── Tab navigation ──────────────────────────────────────────────────
  type Tab = 'dashboard' | 'pools' | 'jobs' | 'endpoints' | 'settings';
  let currentTab: Tab = 'dashboard';

  function renderTabs(bridge: ProxyBridge, container: HTMLElement, onSwitch: (tab: Tab) => void): HTMLElement {
    const tabs: { id: Tab; label: string }[] = [
      { id: 'dashboard', label: 'Dashboard' },
      { id: 'pools', label: 'Pools' },
      { id: 'jobs', label: 'Jobs' },
      { id: 'endpoints', label: 'Endpoints' },
      { id: 'settings', label: 'Settings' },
    ];

    const nav = el('div', 'proxy-nav');
    for (const t of tabs) {
      const btn = el('button', `proxy-nav__btn ${t.id === currentTab ? 'proxy-nav__btn--active' : ''}`, t.label);
      btn.addEventListener('click', () => {
        currentTab = t.id;
        onSwitch(t.id);
      });
      nav.appendChild(btn);
    }
    return nav;
  }

  // ── Dashboard ───────────────────────────────────────────────────────
  async function renderDashboard(bridge: ProxyBridge, container: HTMLElement): Promise<void> {
    container.textContent = '';
    container.appendChild(el('div', 'proxy-loading', 'Loading inventory…'));

    try {
      const resp = await bridge.fetch('/inventory');
      const data = resp as unknown as ProxySnapshot;
      container.textContent = '';

      const header = el('div', 'proxy-section-header');
      header.appendChild(el('h2', 'proxy-title', 'Proxy Inventory'));
      container.appendChild(header);

      // Stats row
      const stats = el('div', 'proxy-stats');
      stats.appendChild(statCard('Endpoints', String(data.endpoints), 'default'));
      stats.appendChild(statCard('Quarantined', String(data.quarantined), data.quarantined > 0 ? 'warning' : 'default'));
      container.appendChild(stats);

      // State breakdown
      const stateSection = el('div', 'proxy-section');
      stateSection.appendChild(el('h3', 'proxy-subtitle', 'State Breakdown'));

      const stateGrid = el('div', 'proxy-state-grid');
      for (const [state, count] of Object.entries(data.states)) {
        const card = el('div', 'proxy-state-card');
        const dot = el('span', 'proxy-state-dot');
        dot.style.backgroundColor = STATE_COLORS[state] ?? '#666';
        card.appendChild(dot);
        card.appendChild(el('span', 'proxy-state-label', state.charAt(0).toUpperCase() + state.slice(1)));
        card.appendChild(el('span', 'proxy-state-value', String(count)));
        stateGrid.appendChild(card);
      }
      stateSection.appendChild(stateGrid);
      container.appendChild(stateSection);

    } catch (err) {
      container.textContent = '';
      container.appendChild(el('div', 'proxy-error', `Failed to load inventory: ${err instanceof Error ? err.message : 'unknown'}`));
    }
  }

  // ── Pools ───────────────────────────────────────────────────────────
  async function renderPools(bridge: ProxyBridge, container: HTMLElement): Promise<void> {
    container.textContent = '';
    container.appendChild(el('div', 'proxy-loading', 'Loading pools…'));

    try {
      const resp = await bridge.fetch('/pools');
      const data = resp as unknown as { pools: Pool[] };
      container.textContent = '';

      const header = el('div', 'proxy-section-header');
      header.appendChild(el('h2', 'proxy-title', 'Proxy Pools'));

      const createBtn = el('button', 'proxy-btn proxy-btn--primary', '+ New Pool');
      createBtn.addEventListener('click', () => showCreatePoolForm(bridge, container));
      header.appendChild(createBtn);
      container.appendChild(header);

      if (data.pools.length === 0) {
        container.appendChild(el('div', 'proxy-empty', 'No pools created yet.'));
        return;
      }

      const list = el('div', 'proxy-pool-list');
      for (const pool of data.pools) {
        const card = el('div', 'proxy-pool-card');

        const info = el('div', 'proxy-pool-info');
        info.appendChild(el('div', 'proxy-pool-name', pool.name));
        info.appendChild(el('div', 'proxy-pool-meta', `${pool.kind} · ${pool.enabled ? 'enabled' : 'disabled'}`));
        if (pool.description) {
          info.appendChild(el('div', 'proxy-pool-desc', pool.description));
        }
        if (pool.last_materialized_at) {
          info.appendChild(el('div', 'proxy-pool-meta', `Last materialized: ${new Date(pool.last_materialized_at).toLocaleString()}`));
        }
        card.appendChild(info);

        const actions = el('div', 'proxy-pool-actions');

        const matBtn = el('button', 'proxy-btn proxy-btn--small', 'Materialize');
        matBtn.addEventListener('click', async () => {
          matBtn.textContent = '…';
          matBtn.setAttribute('disabled', 'true');
          try {
            await bridge.fetch(`/pools/${pool.id}/materialize`, { method: 'POST', json: {} });
            bridge.haptic?.('success');
            await renderPools(bridge, container);
          } catch {
            bridge.haptic?.('error');
            matBtn.textContent = 'Error';
          }
        });
        actions.appendChild(matBtn);

        const delBtn = el('button', 'proxy-btn proxy-btn--danger proxy-btn--small', 'Delete');
        delBtn.addEventListener('click', async () => {
          if (!confirm(`Delete pool "${pool.name}"?`)) return;
          delBtn.textContent = '…';
          delBtn.setAttribute('disabled', 'true');
          try {
            await bridge.fetch(`/pools/${pool.id}`, { method: 'DELETE', json: {} });
            bridge.haptic?.('success');
            await renderPools(bridge, container);
          } catch {
            bridge.haptic?.('error');
            delBtn.textContent = 'Error';
          }
        });
        actions.appendChild(delBtn);

        card.appendChild(actions);
        list.appendChild(card);
      }
      container.appendChild(list);

    } catch (err) {
      container.textContent = '';
      container.appendChild(el('div', 'proxy-error', `Failed to load pools: ${err instanceof Error ? err.message : 'unknown'}`));
    }
  }

  function showCreatePoolForm(bridge: ProxyBridge, container: HTMLElement): void {
    const form = el('div', 'proxy-form');
    form.appendChild(el('h3', 'proxy-subtitle', 'Create Pool'));

    const nameInput = document.createElement('input');
    nameInput.className = 'proxy-input';
    nameInput.placeholder = 'Pool name';
    form.appendChild(nameInput);

    const kindSelect = document.createElement('select');
    kindSelect.className = 'proxy-select';
    for (const kind of ['static', 'dynamic', 'hybrid']) {
      const opt = document.createElement('option');
      opt.value = kind;
      opt.textContent = kind;
      kindSelect.appendChild(opt);
    }
    form.appendChild(kindSelect);

    const descInput = document.createElement('input');
    descInput.className = 'proxy-input';
    descInput.placeholder = 'Description (optional)';
    form.appendChild(descInput);

    const submitBtn = el('button', 'proxy-btn proxy-btn--primary', 'Create');
    submitBtn.addEventListener('click', async () => {
      if (!nameInput.value.trim()) return;
      submitBtn.textContent = '…';
      submitBtn.setAttribute('disabled', 'true');
      try {
        await bridge.fetch('/pools', {
          method: 'POST',
          json: { name: nameInput.value.trim(), kind: kindSelect.value, description: descInput.value || null },
        });
        bridge.haptic?.('success');
        await renderPools(bridge, container);
      } catch {
        bridge.haptic?.('error');
        submitBtn.textContent = 'Error';
      }
    });
    form.appendChild(submitBtn);

    const cancelBtn = el('button', 'proxy-btn', 'Cancel');
    cancelBtn.addEventListener('click', () => renderPools(bridge, container));
    form.appendChild(cancelBtn);

    container.appendChild(form);
  }

  // ── Jobs ────────────────────────────────────────────────────────────
  async function renderJobs(bridge: ProxyBridge, container: HTMLElement): Promise<void> {
    container.textContent = '';
    container.appendChild(el('div', 'proxy-loading', 'Loading jobs…'));

    try {
      const resp = await bridge.fetch('/jobs');
      const data = resp as unknown as { jobs: AuditJob[] };
      container.textContent = '';

      const header = el('div', 'proxy-section-header');
      header.appendChild(el('h2', 'proxy-title', 'Audit Jobs'));

      const triggerBtn = el('button', 'proxy-btn proxy-btn--primary', 'Run Audit');
      triggerBtn.addEventListener('click', async () => {
        triggerBtn.textContent = 'Starting…';
        triggerBtn.setAttribute('disabled', 'true');
        try {
          await bridge.fetch('/jobs', { method: 'POST', json: {} });
          bridge.haptic?.('success');
          await renderJobs(bridge, container);
        } catch {
          bridge.haptic?.('error');
          triggerBtn.textContent = 'Error';
        }
      });
      header.appendChild(triggerBtn);
      container.appendChild(header);

      if (data.jobs.length === 0) {
        container.appendChild(el('div', 'proxy-empty', 'No audit jobs yet.'));
        return;
      }

      const list = el('div', 'proxy-job-list');
      for (const job of data.jobs) {
        const card = el('div', 'proxy-job-card');
        card.appendChild(el('div', 'proxy-job-trigger', job.trigger));
        card.appendChild(el('div', `proxy-job-status proxy-job-status--${job.status}`, job.status));
        if (job.result_code) {
          card.appendChild(el('div', 'proxy-job-result', job.result_code));
        }
        card.appendChild(el('div', 'proxy-job-date', new Date(job.created_at).toLocaleString()));
        list.appendChild(card);
      }
      container.appendChild(list);

    } catch (err) {
      container.textContent = '';
      container.appendChild(el('div', 'proxy-error', `Failed to load jobs: ${err instanceof Error ? err.message : 'unknown'}`));
    }
  }

  // ── Endpoints ───────────────────────────────────────────────────────
  async function renderEndpoints(bridge: ProxyBridge, container: HTMLElement): Promise<void> {
    container.textContent = '';
    container.appendChild(el('div', 'proxy-loading', 'Loading endpoints…'));

    try {
      const resp = await bridge.fetch('/endpoints');
      const data = resp as unknown as { endpoints: Endpoint[] };
      container.textContent = '';

      const header = el('div', 'proxy-section-header');
      header.appendChild(el('h2', 'proxy-title', 'Proxy Endpoints'));
      container.appendChild(header);

      if (data.endpoints.length === 0) {
        container.appendChild(el('div', 'proxy-empty', 'No endpoints registered.'));
        return;
      }

      const table = el('div', 'proxy-table');
      const headerRow = el('div', 'proxy-table-row proxy-table-row--header');
      headerRow.appendChild(el('div', 'proxy-table-cell', 'Protocol'));
      headerRow.appendChild(el('div', 'proxy-table-cell', 'Host'));
      headerRow.appendChild(el('div', 'proxy-table-cell', 'Port'));
      headerRow.appendChild(el('div', 'proxy-table-cell', 'Added'));
      table.appendChild(headerRow);

      for (const ep of data.endpoints) {
        const row = el('div', 'proxy-table-row');
        row.appendChild(el('div', 'proxy-table-cell', ep.protocol));
        row.appendChild(el('div', 'proxy-table-cell proxy-table-cell--mono', ep.host));
        row.appendChild(el('div', 'proxy-table-cell', String(ep.port)));
        row.appendChild(el('div', 'proxy-table-cell', new Date(ep.created_at).toLocaleDateString()));
        table.appendChild(row);
      }
      container.appendChild(table);

    } catch (err) {
      container.textContent = '';
      container.appendChild(el('div', 'proxy-error', `Failed to load endpoints: ${err instanceof Error ? err.message : 'unknown'}`));
    }
  }

  // ── Settings ────────────────────────────────────────────────────────
  async function renderSettings(bridge: ProxyBridge, container: HTMLElement): Promise<void> {
    container.textContent = '';
    container.appendChild(el('div', 'proxy-loading', 'Loading settings…'));

    try {
      const resp = await bridge.fetch('/settings');
      const data = resp as unknown as Settings;
      container.textContent = '';

      container.appendChild(el('h2', 'proxy-title', 'Settings'));

      // Quotas
      const quotasSection = el('div', 'proxy-section');
      quotasSection.appendChild(el('h3', 'proxy-subtitle', 'Quotas'));
      const quotasGrid = el('div', 'proxy-settings-grid');
      for (const [key, val] of Object.entries(data.quotas)) {
        const row = el('div', 'proxy-settings-row');
        row.appendChild(el('label', 'proxy-settings-label', key.replace(/_/g, ' ')));
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'proxy-input proxy-input--small';
        input.value = String(val);
        input.dataset.key = key;
        row.appendChild(input);
        quotasGrid.appendChild(row);
      }
      quotasSection.appendChild(quotasGrid);
      container.appendChild(quotasSection);

      // Retention
      const retentionSection = el('div', 'proxy-section');
      retentionSection.appendChild(el('h3', 'proxy-subtitle', 'Retention'));
      const retentionGrid = el('div', 'proxy-settings-grid');
      for (const [key, val] of Object.entries(data.retention)) {
        const row = el('div', 'proxy-settings-row');
        row.appendChild(el('label', 'proxy-settings-label', key.replace(/_/g, ' ')));
        const toggle = document.createElement('input');
        toggle.type = 'checkbox';
        toggle.className = 'proxy-checkbox';
        toggle.checked = val;
        toggle.dataset.key = key;
        row.appendChild(toggle);
        retentionGrid.appendChild(row);
      }
      retentionSection.appendChild(retentionGrid);
      container.appendChild(retentionSection);

      // UI Flags
      const uiSection = el('div', 'proxy-section');
      uiSection.appendChild(el('h3', 'proxy-subtitle', 'UI Flags'));
      const uiGrid = el('div', 'proxy-settings-grid');
      for (const [key, val] of Object.entries(data.ui_flags)) {
        const row = el('div', 'proxy-settings-row');
        row.appendChild(el('label', 'proxy-settings-label', key.replace(/_/g, ' ')));
        const toggle = document.createElement('input');
        toggle.type = 'checkbox';
        toggle.className = 'proxy-checkbox';
        toggle.checked = val;
        toggle.dataset.key = key;
        row.appendChild(toggle);
        uiGrid.appendChild(row);
      }
      uiSection.appendChild(uiGrid);
      container.appendChild(uiSection);

      // Save button
      const saveBtn = el('button', 'proxy-btn proxy-btn--primary', 'Save Settings');
      saveBtn.addEventListener('click', async () => {
        saveBtn.textContent = 'Saving…';
        saveBtn.setAttribute('disabled', 'true');
        try {
          const quotas: Record<string, number> = {};
          quotasSection.querySelectorAll('input[type="number"]').forEach((inp: HTMLInputElement) => {
            quotas[inp.dataset.key!] = parseInt(inp.value, 10) || 0;
          });

          const retention: Record<string, boolean> = {};
          retentionSection.querySelectorAll('input[type="checkbox"]').forEach((inp: HTMLInputElement) => {
            retention[inp.dataset.key!] = inp.checked;
          });

          const uiFlags: Record<string, boolean> = {};
          uiSection.querySelectorAll('input[type="checkbox"]').forEach((inp: HTMLInputElement) => {
            uiFlags[inp.dataset.key!] = inp.checked;
          });

          await bridge.fetch('/settings', {
            method: 'PUT',
            json: { _method: 'PUT', quotas, retention, ui_flags: uiFlags },
          });
          bridge.haptic?.('success');
          saveBtn.textContent = 'Saved!';
          setTimeout(() => { saveBtn.textContent = 'Save Settings'; saveBtn.removeAttribute('disabled'); }, 1500);
        } catch {
          bridge.haptic?.('error');
          saveBtn.textContent = 'Error';
          saveBtn.removeAttribute('disabled');
        }
      });
      container.appendChild(saveBtn);

    } catch (err) {
      container.textContent = '';
      container.appendChild(el('div', 'proxy-error', `Failed to load settings: ${err instanceof Error ? err.message : 'unknown'}`));
    }
  }

  // ── Shared components ───────────────────────────────────────────────
  function statCard(label: string, value: string, variant: string): HTMLElement {
    const card = el('div', `proxy-stat-card proxy-stat-card--${variant}`);
    card.appendChild(el('div', 'proxy-stat-label', label));
    card.appendChild(el('div', 'proxy-stat-value', value));
    return card;
  }

  // ── Mount ───────────────────────────────────────────────────────────
  function mount(root: HTMLElement, bridgeFactory: (root: HTMLElement) => ProxyBridge): void {
    const bridge = bridgeFactory(root);

    const theme = bridge.theme();
    const bg = theme.bg_color ?? '#17212b';
    const fg = theme.text_color ?? '#ffffff';
    const accent = theme.button_color ?? '#5288c1';
    const hint = theme.hint_color ?? '#708499';

    root.textContent = '';
    root.setAttribute('data-proxy-app', 'true');
    root.setAttribute('style',
      css('bg', bg) + css('fg', fg) + css('accent', accent) + css('hint', hint) +
      `font-family:system-ui,-apple-system,sans-serif;color:${fg};padding:12px;min-height:100vh;box-sizing:border-box;`
    );

    // Title
    const title = el('div', 'proxy-header');
    title.appendChild(el('h1', '', 'Proxy Operations'));
    root.appendChild(title);

    // Content container
    const content = el('div', 'proxy-content');
    root.appendChild(content);

    // Tab bar
    const switchTab = async (tab: Tab) => {
      currentTab = tab;
      navContainer.textContent = '';
      navContainer.appendChild(renderTabs(bridge, root, switchTab));
      await renderTab(bridge, content, tab);
    };

    const navContainer = el('div', 'proxy-nav-container');
    navContainer.appendChild(renderTabs(bridge, root, switchTab));
    root.insertBefore(navContainer, content);

    // Initial render
    renderTab(bridge, content, currentTab);

    // Back handler
    bridge.setBackHandler?.(() => {
      bridge.haptic?.('light');
      bridge.navigate?.('home');
    });
  }

  async function renderTab(bridge: ProxyBridge, container: HTMLElement, tab: Tab): Promise<void> {
    switch (tab) {
      case 'dashboard': await renderDashboard(bridge, container); break;
      case 'pools': await renderPools(bridge, container); break;
      case 'jobs': await renderJobs(bridge, container); break;
      case 'endpoints': await renderEndpoints(bridge, container); break;
      case 'settings': await renderSettings(bridge, container); break;
    }
  }

  // ── Public API ──────────────────────────────────────────────────────
  (window as unknown as Record<string, unknown>).TgMenu = {
    mount,
  };
})();
