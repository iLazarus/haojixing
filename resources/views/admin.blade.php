<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>haojixing 后台管理</title>
    <style>
        :root {
            --bg-a: #f6f7ef;
            --bg-b: #e6edf8;
            --card: rgba(255, 255, 255, 0.9);
            --line: rgba(15, 23, 42, 0.14);
            --text: #172033;
            --muted: #5b6475;
            --brand: #0f766e;
            --brand-2: #0e4f71;
            --danger: #be123c;
            --ok: #166534;
            --warn: #92400e;
            --shadow: 0 16px 38px rgba(16, 36, 60, 0.14);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Noto Sans SC", "Source Han Sans SC", "PingFang SC", "Microsoft YaHei", sans-serif;
            color: var(--text);
            background:
                radial-gradient(1200px 520px at -8% -10%, rgba(14, 79, 113, 0.22), transparent 68%),
                radial-gradient(900px 400px at 112% 0%, rgba(15, 118, 110, 0.26), transparent 64%),
                linear-gradient(125deg, var(--bg-a), var(--bg-b));
            min-height: 100vh;
        }

        .shell {
            max-width: 1200px;
            margin: 0 auto;
            padding: 22px 16px 34px;
        }

        .hero {
            margin-bottom: 14px;
            padding: 18px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: var(--card);
            box-shadow: var(--shadow);
            backdrop-filter: blur(6px);
            animation: rise .45s ease-out;
        }

        .title {
            margin: 0;
            font-size: 26px;
            letter-spacing: .4px;
        }

        .desc {
            margin: 8px 0 0;
            color: var(--muted);
            line-height: 1.55;
        }

        .tabs {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 8px;
            margin: 12px 0 12px;
        }

        .tab {
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.75);
            color: var(--text);
            border-radius: 11px;
            padding: 9px 10px;
            cursor: pointer;
            font-weight: 700;
        }

        .tab.active {
            background: linear-gradient(140deg, var(--brand), var(--brand-2));
            color: #fff;
            border-color: transparent;
        }

        .top-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 12px;
        }

        .card {
            border: 1px solid var(--line);
            border-radius: 18px;
            background: var(--card);
            box-shadow: var(--shadow);
            backdrop-filter: blur(6px);
            padding: 16px;
            margin-bottom: 14px;
            animation: rise .52s ease-out;
        }

        .field { display: grid; gap: 6px; }

        label { font-size: 13px; color: var(--muted); }

        input, select, textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 11px;
            font-size: 14px;
            color: var(--text);
            background: rgba(255,255,255,0.95);
        }

        textarea {
            min-height: 110px;
            resize: vertical;
            font-family: "JetBrains Mono", "Fira Code", monospace;
        }

        input:disabled,
        select:disabled,
        textarea:disabled {
            background: #f1f5f9;
            color: #64748b;
            border-color: #cbd5e1;
            cursor: not-allowed;
            pointer-events: none;
        }

        .ops {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        button {
            border: 0;
            border-radius: 10px;
            padding: 9px 12px;
            font-weight: 700;
            cursor: pointer;
            transition: transform .12s ease, opacity .12s ease;
        }

        button:hover { transform: translateY(-1px); }
        button:disabled { opacity: .55; cursor: not-allowed; transform: none; }

        .btn-main { background: linear-gradient(140deg, var(--brand), var(--brand-2)); color: #fff; }
        .btn-sub { background: #fff; border: 1px solid var(--line); color: var(--text); }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-warn { background: #f59e0b; color: #111827; }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 6px 0 10px;
            align-items: end;
        }

        .toolbar .field { min-width: 190px; }

        .table-wrap {
            margin-top: 12px;
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(255,255,255,0.9);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 780px;
        }

        th, td {
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            text-align: left;
            padding: 8px 9px;
            font-size: 13px;
            vertical-align: top;
        }

        th {
            background: rgba(15, 23, 42, 0.04);
            position: sticky;
            top: 0;
            z-index: 1;
        }

        td code {
            font-family: "JetBrains Mono", "Fira Code", monospace;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 12px;
        }

        .inline-actions {
            display: flex;
            gap: 6px;
        }

        .pager {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 10px 0 0;
            color: var(--muted);
            font-size: 13px;
            flex-wrap: wrap;
        }

        .foot {
            color: var(--muted);
            font-size: 12px;
            margin-top: 4px;
        }

        .modal-mask {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.42);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 60;
            padding: 14px;
        }

        .modal-mask.show { display: flex; }

        .modal-card {
            width: min(900px, 100%);
            max-height: 88vh;
            overflow: auto;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: #fff;
            box-shadow: var(--shadow);
            padding: 14px;
        }

        .modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .modal-title {
            margin: 0;
            font-size: 18px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .full { grid-column: 1 / -1; }

        .mono {
            font-family: "JetBrains Mono", "Fira Code", monospace;
            font-size: 12px;
            white-space: pre-wrap;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: rgba(248,250,252,.9);
            padding: 10px;
            max-height: 300px;
            overflow: auto;
        }

        .toast-wrap {
            position: fixed;
            right: 14px;
            bottom: 14px;
            display: grid;
            gap: 8px;
            z-index: 80;
        }

        .toast {
            min-width: 260px;
            max-width: 420px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #fff;
            box-shadow: var(--shadow);
            padding: 10px 12px;
            font-size: 13px;
            line-height: 1.45;
            animation: rise .22s ease-out;
            white-space: pre-wrap;
        }

        .toast.ok { border-left: 4px solid var(--ok); }
        .toast.err { border-left: 4px solid var(--danger); }
        .toast.info { border-left: 4px solid var(--brand-2); }
        .toast.warn { border-left: 4px solid var(--warn); }

        @keyframes rise {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 980px) {
            .tabs { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="shell">
    <section class="hero">
        <h1 class="title">haojixing 数据后台</h1>
        <p class="desc">切换模块会自动拉取列表，默认每页 100 条；新增/编辑使用弹层，删除在每行首列；所有结果在右下角通知。</p>
    </section>

    <nav id="tabs" class="tabs"></nav>

    <div class="top-actions">
        <button class="btn-main" id="openCreateBtn">新增记录</button>
        <button class="btn-sub" id="reloadBtn">刷新列表</button>
        <button class="btn-warn" id="openMatchBtn">Rule Match 调试</button>
    </div>

    <section class="card">
        <div class="toolbar">
            <div class="field">
                <label>关键词过滤（当前列表）</label>
                <input id="keywordInput" placeholder="输入关键词后点应用过滤">
            </div>
            <div class="field" style="max-width:120px;">
                <label>每页条数</label>
                <select id="pageSizeSelect">
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100" selected>100</option>
                    <option value="200">200</option>
                </select>
            </div>
            <div class="field" id="gidFilterWrap" style="display:none; max-width:180px;">
                <label>tg_gid（模块参数）</label>
                <input id="gidFilterInput" placeholder="例如 900001">
            </div>
            <div class="ops">
                <button class="btn-sub" id="applyFilterBtn">应用过滤</button>
                <button class="btn-sub" id="resetFilterBtn">重置过滤</button>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr id="headRow"></tr>
                </thead>
                <tbody id="bodyRows"></tbody>
            </table>
        </div>

        <div class="pager">
            <button class="btn-sub" id="prevPageBtn">上一页</button>
            <button class="btn-sub" id="nextPageBtn">下一页</button>
            <span id="pagerInfo">第 1 / 1 页</span>
        </div>

        <div class="foot">提示：筛选和分页在当前模块内即时生效。</div>
    </section>
</div>

<div id="modalMask" class="modal-mask" hidden>
    <div class="modal-card">
        <div class="modal-head">
            <h3 id="modalTitle" class="modal-title">弹窗</h3>
            <button class="btn-sub" id="closeModalBtn">关闭</button>
        </div>
        <div id="modalBody"></div>
    </div>
</div>

<div id="toastWrap" class="toast-wrap"></div>

<script>
(() => {
    const modules = {
        groups: {
            label: '群组',
            columns: ['tg_gid', 'tg_oid', 'tg_g_name', 'tg_o_nickname', 'is_open', 'base_currency', 'quote_currency', 'exchange_rate', 'fee_rate', 'period_point', 'period_duration', 'updated_at'],
            listPath: '/api/v1/groups',
            createPath: () => '/api/v1/groups',
            updatePath: (f) => `/api/v1/groups/${f.tg_gid}`,
            deletePath: (f) => `/api/v1/groups/${f.tg_gid}`,
            createFields: ['tg_gid', 'tg_oid', 'tg_g_name', 'tg_o_nickname', 'is_open', 'base_currency', 'quote_currency', 'exchange_rate', 'fee_rate', 'period_point', 'period_duration'],
            createOmitFields: [],
            updateFields: ['tg_gid', 'tg_g_name', 'tg_o_nickname', 'fee_rate', 'period_point', 'period_duration', 'is_open']
        },
        users: {
            label: '用户',
            columns: ['tg_uid', 'tg_username', 'tg_nickname', 'updated_at'],
            listPath: '/api/v1/users',
            createPath: () => '/api/v1/users',
            updatePath: (f) => `/api/v1/users/${f.tg_uid}`,
            deletePath: (f) => `/api/v1/users/${f.tg_uid}`,
            createFields: ['tg_uid', 'tg_username', 'tg_nickname'],
            createOmitFields: [],
            updateFields: ['tg_uid', 'tg_username', 'tg_nickname']
        },
        members: {
            label: '成员',
            columns: ['tg_gid', 'tg_g_name', 'tg_uid', 'tg_nickname', 'role', 'is_active', 'updated_at'],
            listPath: '/api/v1/members',
            createPath: () => '/api/v1/members',
            updatePath: (f) => `/api/v1/groups/${f.tg_gid}/members/${f.tg_uid}`,
            deletePath: (f) => `/api/v1/groups/${f.tg_gid}/members/${f.tg_uid}`,
            createFields: ['tg_gid', 'tg_g_name', 'tg_uid', 'tg_nickname', 'role', 'is_active'],
            createOmitFields: [],
            updateFields: ['tg_gid', 'tg_uid', 'tg_g_name', 'tg_nickname', 'role', 'is_active']
        },
        rules: {
            label: '规则',
            columns: ['id', 'remark', 'regular', 'method', 'api', 'is_active', 'is_default', 'updated_at'],
            listPath: '/api/v1/rules',
            createPath: () => '/api/v1/rules',
            updatePath: (f) => `/api/v1/rules/${f.id}`,
            deletePath: (f) => `/api/v1/rules/${f.id}`,
            createFields: ['remark', 'regular', 'method', 'api', 'data_map', 'is_active', 'is_default'],
            createOmitFields: [],
            updateFields: ['id', 'remark', 'regular', 'method', 'api', 'data_map', 'is_active', 'is_default']
        },
        groupRules: {
            label: '群规则',
            columns: ['tg_gid', 'app_rule_id', 'priority', 'stop_on_match', 'is_active', 'updated_at'],
            listPath: '/api/v1/group-rules',
            createPath: (f) => `/api/v1/groups/${f.tg_gid}/rules`,
            updatePath: (f) => `/api/v1/groups/${f.tg_gid}/rules/${f.app_rule_id}`,
            deletePath: (f) => `/api/v1/groups/${f.tg_gid}/rules/${f.app_rule_id}`,
            createFields: ['tg_gid', 'app_rule_id', 'priority', 'stop_on_match', 'is_active'],
            createOmitFields: ['tg_gid'],
            updateFields: ['tg_gid', 'app_rule_id', 'priority', 'stop_on_match', 'is_active']
        },
        ledgers: {
            label: '账单',
            columns: ['id', 'tg_gid', 'tg_g_name', 'tg_uid', 'tg_nickname', 'tg_belong_uid', 'tg_belong_nickname', 'tg_msg_id', 'amount', 'currency_type', 'is_delete', 'updated_at'],
            listPath: '/api/v1/ledgers',
            createPath: () => '/api/v1/ledgers',
            updatePath: (f) => `/api/v1/ledgers/${f.id}`,
            deletePath: (f) => `/api/v1/ledgers/${f.id}`,
            createFields: ['tg_gid', 'tg_g_name', 'tg_uid', 'tg_nickname', 'tg_belong_uid', 'tg_belong_nickname', 'tg_msg_id', 'amount', 'currency_type', 'is_delete'],
            createOmitFields: [],
            updateFields: ['id', 'amount', 'currency_type', 'tg_g_name', 'tg_nickname', 'tg_belong_nickname', 'is_delete']
        }
    };

    const tabs = document.getElementById('tabs');
    const headRow = document.getElementById('headRow');
    const bodyRows = document.getElementById('bodyRows');
    const keywordInput = document.getElementById('keywordInput');
    const pageSizeSelect = document.getElementById('pageSizeSelect');
    const gidFilterWrap = document.getElementById('gidFilterWrap');
    const gidFilterInput = document.getElementById('gidFilterInput');
    const applyFilterBtn = document.getElementById('applyFilterBtn');
    const resetFilterBtn = document.getElementById('resetFilterBtn');
    const prevPageBtn = document.getElementById('prevPageBtn');
    const nextPageBtn = document.getElementById('nextPageBtn');
    const pagerInfo = document.getElementById('pagerInfo');
    const reloadBtn = document.getElementById('reloadBtn');
    const openCreateBtn = document.getElementById('openCreateBtn');
    const openMatchBtn = document.getElementById('openMatchBtn');
    const modalMask = document.getElementById('modalMask');
    const modalTitle = document.getElementById('modalTitle');
    const modalBody = document.getElementById('modalBody');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const toastWrap = document.getElementById('toastWrap');

    let activeKey = 'groups';
    let latestRows = [];
    let filteredRows = [];
    let currentPageRows = [];
    let currentPage = 1;
    let pageSize = Number(pageSizeSelect.value || 100);
    const moduleParams = {
        tg_gid: localStorage.getItem('admin_module_tg_gid') || '900001',
    };

    const columnTitleMap = {
        groups: {
            actions: '操作',
            tg_gid: '群ID',
            tg_oid: '群主ID',
            tg_g_name: '群名称',
            tg_o_nickname: '群主昵称',
            is_open: '开启记账',
            base_currency: '本币',
            quote_currency: '外币',
            exchange_rate: '外币汇率',
            fee_rate: '费率',
            period_point: '账期时点',
            period_duration: '账期时长',
            updated_at: '更新时间',
        },
        users: {
            actions: '操作',
            tg_uid: '用户ID',
            tg_username: '用户名',
            tg_nickname: '昵称',
            updated_at: '更新时间',
        },
        members: {
            actions: '操作',
            tg_gid: '群ID',
            tg_g_name: '群名称',
            tg_uid: '用户ID',
            tg_nickname: '用户昵称',
            role: '角色',
            is_active: '启用',
            updated_at: '更新时间',
        },
        rules: {
            actions: '操作',
            id: '规则ID',
            remark: '备注',
            regular: '匹配PCRE正则',
            api: 'API地址',
            is_active: '启用',
            is_default: '系统默认规则',
            updated_at: '更新时间',
        },
        groupRules: {
            actions: '操作',
            tg_gid: '群ID',
            app_rule_id: '规则ID',
            priority: '优先级',
            stop_on_match: '命中即停',
            is_active: '启用',
            updated_at: '更新时间',
        },
        ledgers: {
            actions: '操作',
            id: '账单ID',
            tg_gid: '群ID',
            tg_g_name: '群名称',
            tg_uid: '用户ID',
            tg_nickname: '记账人昵称',
            tg_belong_uid: '归属用户ID',
            tg_belong_nickname: '归属用户昵称',
            tg_msg_id: '消息ID',
            amount: '金额(分)',
            currency_type: '币种类型',
            is_delete: '软删除',
            updated_at: '更新时间',
        },
    };

    function getColumnTitle(moduleKey, columnKey) {
        const map = columnTitleMap[moduleKey] || {};
        return map[columnKey] || columnKey;
    }

    function getFieldTitle(moduleKey, fieldKey) {
        const translated = getColumnTitle(moduleKey, fieldKey);
        if (translated !== fieldKey) {
            return translated;
        }

        return fieldKey.replace(/_/g, ' ');
    }

    function isLockedEditField(key) {
        return ['id', 'tg_gid', 'tg_uid', 'tg_oid'].includes(key);
    }

    function notify(type, msg, ttl = 2600) {
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.textContent = msg;
        toastWrap.appendChild(el);
        window.setTimeout(() => el.remove(), ttl);
    }

    function safeJsonParse(text) {
        try {
            return JSON.parse(text);
        } catch (_) {
            return null;
        }
    }

    function moduleSupportsTgGidFilter(key = activeKey) {
        return false;
    }

    function getModulePathValue() {
        const tgGid = String(gidFilterInput.value || moduleParams.tg_gid || '').trim();
        if (tgGid) {
            moduleParams.tg_gid = tgGid;
            localStorage.setItem('admin_module_tg_gid', tgGid);
        }
        return { tg_gid: tgGid };
    }

    function castValue(key, val) {
        if (val === '' || val == null) {
            return null;
        }

        if (['is_open', 'is_active', 'is_default', 'stop_on_match', 'is_delete', 'execute_api'].includes(key)) {
            return ['1', 'true', 'yes', 'on'].includes(String(val).toLowerCase());
        }

        if (/^(id|tg_gid|tg_uid|tg_oid|app_rule_id|priority|tg_msg_id|period_point|period_duration|amount|tg_belong_uid)$/.test(key)) {
            return Number(val);
        }

        if (/^(exchange_rate|fee_rate|amount_yuan)$/.test(key)) {
            return Number(val);
        }

        return val;
    }

    function readFields(prefix, fields) {
        const data = {};
        fields.forEach((k) => {
            const input = document.querySelector(`[name="${prefix}_${k}"]`);
            if (!input) return;
            const val = input.value;
            if (val === '') return;
            data[k] = castValue(k, val);
        });
        return data;
    }

    function openModal(title, builder) {
        modalTitle.textContent = title;
        modalBody.innerHTML = '';
        builder(modalBody);
        modalMask.hidden = false;
        modalMask.classList.add('show');
    }

    function closeModal() {
        modalMask.classList.remove('show');
        modalMask.hidden = true;
        modalBody.innerHTML = '';
    }

    function bindSingleFlight(button, handler) {
        button.addEventListener('click', async () => {
            if (button.disabled) return;
            button.disabled = true;
            try {
                await handler();
            } finally {
                button.disabled = false;
            }
        });
    }

    async function apiCall(method, path, payload) {
        const init = { method, headers: { Accept: 'application/json' } };
        if (payload && method !== 'GET') {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(payload);
        }

        const resp = await fetch(path, init);
        const text = await resp.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (_) {
            data = { raw: text };
        }

        if (!resp.ok || (typeof data.code !== 'undefined' && data.code !== 0)) {
            const msg = data && data.message ? data.message : `HTTP ${resp.status}`;
            throw new Error(`${msg}\n${JSON.stringify(data, null, 2)}`);
        }

        return data;
    }

    function renderTabs() {
        tabs.innerHTML = '';
        Object.entries(modules).forEach(([key, mod], idx) => {
            const btn = document.createElement('button');
            btn.className = `tab ${key === activeKey ? 'active' : ''}`;
            btn.textContent = mod.label;
            btn.style.animation = `rise .45s ease-out ${idx * 0.04}s both`;
            btn.addEventListener('click', () => {
                activeKey = key;
                latestRows = [];
                filteredRows = [];
                currentPageRows = [];
                currentPage = 1;
                renderTabs();
                syncModuleParamUI();
                renderTable([]);
                renderPager(0);
                notify('info', `已切换到 ${mod.label}`);
                void loadList();
            });
            tabs.appendChild(btn);
        });
    }

    function syncModuleParamUI() {
        if (moduleSupportsTgGidFilter()) {
            gidFilterWrap.style.display = 'grid';
            gidFilterInput.value = moduleParams.tg_gid;
        } else {
            gidFilterWrap.style.display = 'none';
        }
    }

    function renderPager(total) {
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;
        pagerInfo.textContent = `第 ${currentPage} / ${totalPages} 页，共 ${total} 条`;
        prevPageBtn.disabled = currentPage <= 1;
        nextPageBtn.disabled = currentPage >= totalPages;
    }

    function applyFilterAndRender() {
        const q = String(keywordInput.value || '').trim().toLowerCase();
        filteredRows = latestRows.filter((row) => {
            if (!q) return true;
            return JSON.stringify(row).toLowerCase().includes(q);
        });

        const start = (currentPage - 1) * pageSize;
        renderTable(filteredRows.slice(start, start + pageSize));
        renderPager(filteredRows.length);
    }

    function createFieldEl(prefix, key, value, options = {}) {
        const wrap = document.createElement('div');
        wrap.className = 'field';

        const label = document.createElement('label');
        label.textContent = getFieldTitle(activeKey, key);
        wrap.appendChild(label);

        const readonly = Boolean(options.readonly);

        if (['is_open', 'is_active', 'is_default', 'stop_on_match', 'is_delete', 'execute_api'].includes(key)) {
            const select = document.createElement('select');
            select.name = `${prefix}_${key}`;

            const optTrue = document.createElement('option');
            optTrue.value = 'true';
            optTrue.textContent = '是';

            const optFalse = document.createElement('option');
            optFalse.value = 'false';
            optFalse.textContent = '否';

            const isTrue = String(value).toLowerCase() === 'true' || value === true;
            if (isTrue) {
                optTrue.selected = true;
            } else {
                optFalse.selected = true;
            }

            select.appendChild(optTrue);
            select.appendChild(optFalse);
            if (readonly) {
                select.disabled = true;
                select.title = '该字段不可编辑';
            }
            wrap.appendChild(select);
            return wrap;
        }

        if (key === 'method') {
            const select = document.createElement('select');
            select.name = `${prefix}_${key}`;

            ['PATCH', 'POST', 'GET', 'DELETE'].forEach((method) => {
                const option = document.createElement('option');
                option.value = method;
                option.textContent = method;
                if (String(value || '').toUpperCase() === method) {
                    option.selected = true;
                }
                select.appendChild(option);
            });

            if (!String(value || '').trim()) {
                select.value = 'POST';
            }

            if (readonly) {
                select.disabled = true;
                select.title = '该字段不可编辑';
            }
            wrap.appendChild(select);
            return wrap;
        }

        if (key === 'data_map' || key === 'context') {
            wrap.classList.add('full');
            const textarea = document.createElement('textarea');
            textarea.name = `${prefix}_${key}`;
            textarea.value = value == null ? '' : String(value);
            if (readonly) {
                textarea.disabled = true;
                textarea.title = '该字段不可编辑';
            }
            wrap.appendChild(textarea);
            return wrap;
        }

        const input = document.createElement('input');
        input.name = `${prefix}_${key}`;
        input.value = value == null ? '' : String(value);
        if (readonly) {
            input.disabled = true;
            input.title = '该字段不可编辑';
        }
        wrap.appendChild(input);
        return wrap;
    }

    function pickPayload(obj, allowFields) {
        const payload = {};
        allowFields.forEach((k) => {
            if (Object.prototype.hasOwnProperty.call(obj, k)) {
                payload[k] = obj[k];
            }
        });
        return payload;
    }

    function renderCrudModal(mode, row = {}) {
        const mod = modules[activeKey];
        const fields = mode === 'create' ? mod.createFields : mod.updateFields;
        const lockedEditFields = ['id', 'tg_gid', 'tg_uid', 'tg_oid'];

        openModal(`${mod.label} ${mode === 'create' ? '新增' : '编辑'}`, (container) => {
            const grid = document.createElement('div');
            grid.className = 'grid';
            fields.forEach((k) => {
                const isReadonly = mode === 'update' && isLockedEditField(k);
                grid.appendChild(createFieldEl('modal', k, row[k] ?? '', { readonly: isReadonly }));
            });

            const ops = document.createElement('div');
            ops.className = 'ops';

            const submitBtn = document.createElement('button');
            submitBtn.className = 'btn-main';
            submitBtn.type = 'button';
            submitBtn.textContent = '提交';

            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn-sub';
            cancelBtn.type = 'button';
            cancelBtn.textContent = '取消';
            cancelBtn.addEventListener('click', closeModal);

            ops.appendChild(submitBtn);
            ops.appendChild(cancelBtn);

            container.appendChild(grid);
            container.appendChild(ops);

            bindSingleFlight(submitBtn, async () => {
                try {
                    const source = readFields('modal', fields);
                    if (mode === 'create') {
                        const omit = Array.isArray(mod.createOmitFields) ? mod.createOmitFields : [];
                        const payload = pickPayload(source, mod.createFields.filter(k => !omit.includes(k)));
                        const path = typeof mod.createPath === 'function' ? mod.createPath(source) : mod.createPath;
                        const res = await apiCall('POST', path, payload);
                        notify('ok', `新增成功\n${JSON.stringify(res.data ?? {}, null, 2)}`);
                    } else {
                        lockedEditFields.forEach((k) => {
                            if (Object.prototype.hasOwnProperty.call(row, k)) {
                                source[k] = row[k];
                            }
                        });

                        const payload = pickPayload(source, mod.updateFields.filter(k => !['tg_gid', 'tg_uid', 'app_rule_id', 'id', 'tg_oid'].includes(k)));
                        const res = await apiCall('PATCH', mod.updatePath(source), payload);
                        notify('ok', `更新成功\n${JSON.stringify(res.data ?? {}, null, 2)}`);
                    }
                    closeModal();
                    await loadList(false);
                } catch (err) {
                    notify('err', `提交失败\n${String(err.message || err)}`, 3800);
                }
            });
        });
    }

    function renderMatchModal() {
        openModal('Rule Match 调试', (container) => {
            const grid = document.createElement('div');
            grid.className = 'grid';
            grid.appendChild(createFieldEl('match', 'tg_gid', moduleParams.tg_gid || '900001'));
            grid.appendChild(createFieldEl('match', 'tg_msg_id', ''));
            grid.appendChild(createFieldEl('match', 'execute_api', false));

            const msgWrap = document.createElement('div');
            msgWrap.className = 'field full';
            const msgLabel = document.createElement('label');
            msgLabel.textContent = 'message';
            const msgInput = document.createElement('input');
            msgInput.name = 'match_message';
            msgInput.placeholder = '例如 买 12.34';
            msgWrap.appendChild(msgLabel);
            msgWrap.appendChild(msgInput);
            grid.appendChild(msgWrap);

            grid.appendChild(createFieldEl('match', 'context', '{"sender":"900002"}'));

            const ops = document.createElement('div');
            ops.className = 'ops';
            const runBtn = document.createElement('button');
            runBtn.className = 'btn-main';
            runBtn.type = 'button';
            runBtn.textContent = '执行 Match';
            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn-sub';
            cancelBtn.type = 'button';
            cancelBtn.textContent = '取消';
            cancelBtn.addEventListener('click', closeModal);
            ops.appendChild(runBtn);
            ops.appendChild(cancelBtn);

            const result = document.createElement('pre');
            result.className = 'mono';
            result.textContent = '等待执行...';

            container.appendChild(grid);
            container.appendChild(ops);
            container.appendChild(result);

            bindSingleFlight(runBtn, async () => {
                try {
                    const source = readFields('match', ['tg_gid', 'tg_msg_id', 'execute_api', 'message', 'context']);
                    const tgGid = Number(source.tg_gid || 0);
                    if (!tgGid || !source.tg_msg_id || !source.message) {
                        throw new Error('tg_gid、tg_msg_id、message 为必填。');
                    }

                    const parsedContext = safeJsonParse(String(source.context || '{}'));
                    if (parsedContext === null || Array.isArray(parsedContext) || typeof parsedContext !== 'object') {
                        throw new Error('context 必须是 JSON 对象。');
                    }

                    moduleParams.tg_gid = String(tgGid);
                    localStorage.setItem('admin_module_tg_gid', moduleParams.tg_gid);

                    result.textContent = '执行中...';
                    const res = await apiCall('POST', `/api/v1/groups/${tgGid}/rules/match`, {
                        tg_msg_id: Number(source.tg_msg_id),
                        message: String(source.message),
                        execute_api: Boolean(source.execute_api),
                        context: parsedContext,
                    });

                    result.textContent = JSON.stringify(res, null, 2);
                    notify('ok', 'Rule Match 执行成功');
                } catch (err) {
                    result.textContent = `执行失败\n${String(err.message || err)}`;
                    notify('err', `Rule Match 失败\n${String(err.message || err)}`, 3800);
                }
            });
        });
    }

    function renderDeleteModal(row) {
        const mod = modules[activeKey];
        openModal(`${mod.label} 删除确认`, (container) => {
            const p = document.createElement('p');
            p.style.margin = '0 0 10px';
            p.style.color = 'var(--muted)';
            p.textContent = `确认删除当前 ${mod.label} 记录？此操作不可撤销。`;

            const preview = document.createElement('pre');
            preview.className = 'mono';
            preview.textContent = JSON.stringify(row, null, 2);

            const ops = document.createElement('div');
            ops.className = 'ops';

            const confirmBtn = document.createElement('button');
            confirmBtn.className = 'btn-danger';
            confirmBtn.type = 'button';
            confirmBtn.textContent = '确认删除';

            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn-sub';
            cancelBtn.type = 'button';
            cancelBtn.textContent = '取消';
            cancelBtn.addEventListener('click', closeModal);

            ops.appendChild(confirmBtn);
            ops.appendChild(cancelBtn);
            container.appendChild(p);
            container.appendChild(preview);
            container.appendChild(ops);

            bindSingleFlight(confirmBtn, async () => {
                try {
                    const res = await apiCall('DELETE', mod.deletePath(row));
                    notify('ok', `删除完成\n${JSON.stringify(res.data ?? {}, null, 2)}`);
                    closeModal();
                    await loadList(false);
                } catch (err) {
                    notify('err', `删除失败\n${String(err.message || err)}`, 3600);
                }
            });
        });
    }

    function renderTable(rows) {
        currentPageRows = rows;
        const mod = modules[activeKey];
        const columns = ['actions', ...mod.columns];
        headRow.innerHTML = columns.map((c) => `<th>${getColumnTitle(activeKey, c)}</th>`).join('');

        bodyRows.innerHTML = '';
        rows.forEach((row, idx) => {
            const tr = document.createElement('tr');

            const actionTd = document.createElement('td');
            const actionWrap = document.createElement('div');
            actionWrap.className = 'inline-actions';

            const delBtn = document.createElement('button');
            delBtn.className = 'btn-danger';
            delBtn.type = 'button';
            delBtn.textContent = '删除';
            const rowData = currentPageRows[idx] || {};
            if (activeKey === 'rules' && Boolean(rowData.is_default)) {
                delBtn.disabled = true;
                delBtn.title = '默认规则不可删除';
                delBtn.style.background = '#9ca3af';
            } else {
                delBtn.addEventListener('click', () => renderDeleteModal(rowData));
            }

            const editBtn = document.createElement('button');
            editBtn.className = 'btn-sub';
            editBtn.type = 'button';
            editBtn.textContent = '编辑';
            editBtn.addEventListener('click', () => renderCrudModal('update', currentPageRows[idx] || {}));

            actionWrap.appendChild(delBtn);
            actionWrap.appendChild(editBtn);
            actionTd.appendChild(actionWrap);
            tr.appendChild(actionTd);

            mod.columns.forEach((col) => {
                const td = document.createElement('td');
                const code = document.createElement('code');
                let val = formatTableValue(col, row[col]);
                if (typeof val === 'object' && val !== null) {
                    val = JSON.stringify(val);
                }
                code.textContent = String(val ?? '');
                td.appendChild(code);
                tr.appendChild(td);
            });

            bodyRows.appendChild(tr);
        });
    }

    function formatTableValue(column, value) {
        if (!['exchange_rate', 'fee_rate'].includes(column)) {
            return value;
        }

        if (value == null) {
            return value;
        }

        const text = String(value).trim();
        if (!/^-?\d+(?:\.\d+)?$/.test(text)) {
            return value;
        }

        return text
            .replace(/(\.\d*?[1-9])0+$/, '$1')
            .replace(/\.0+$/, '');
    }

    async function loadList(showToast = true) {
        const mod = modules[activeKey];
        try {
            const filters = moduleSupportsTgGidFilter() ? getModulePathValue() : {};

            const path = typeof mod.listPath === 'function' ? mod.listPath(filters) : mod.listPath;
            const res = await apiCall('GET', path);
            const rows = Array.isArray(res.data) ? res.data : (res.data ? [res.data] : []);
            latestRows = rows;
            currentPage = 1;
            applyFilterAndRender();
            if (showToast) {
                notify('ok', `已加载 ${mod.label} 列表，共 ${rows.length} 条。`);
            }
        } catch (err) {
            notify('err', `列表加载失败\n${String(err.message || err)}`, 3600);
        }
    }

    applyFilterBtn.addEventListener('click', () => {
        currentPage = 1;
        applyFilterAndRender();
        notify('info', `过滤后 ${filteredRows.length} 条。`);
    });

    resetFilterBtn.addEventListener('click', () => {
        keywordInput.value = '';
        pageSizeSelect.value = '100';
        pageSize = 100;
        currentPage = 1;
        applyFilterAndRender();
        notify('info', '过滤条件已重置。');
    });

    pageSizeSelect.addEventListener('change', () => {
        pageSize = Number(pageSizeSelect.value || 100);
        currentPage = 1;
        applyFilterAndRender();
        notify('info', `每页条数已切换到 ${pageSize}`);
    });

    prevPageBtn.addEventListener('click', () => {
        if (currentPage <= 1) return;
        currentPage -= 1;
        applyFilterAndRender();
    });

    nextPageBtn.addEventListener('click', () => {
        const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        if (currentPage >= totalPages) return;
        currentPage += 1;
        applyFilterAndRender();
    });

    gidFilterInput.addEventListener('change', () => {
        moduleParams.tg_gid = String(gidFilterInput.value || '').trim();
        localStorage.setItem('admin_module_tg_gid', moduleParams.tg_gid);
        void loadList(false);
    });

    reloadBtn.addEventListener('click', async () => {
        await loadList();
    });

    openCreateBtn.addEventListener('click', () => {
        renderCrudModal('create');
    });

    openMatchBtn.addEventListener('click', () => {
        renderMatchModal();
    });

    closeModalBtn.addEventListener('click', closeModal);
    modalMask.addEventListener('click', (e) => {
        if (e.target === modalMask) {
            notify('warn', '请使用弹窗内的关闭或取消按钮。');
        }
    });

    renderTabs();
    syncModuleParamUI();
    renderPager(0);
    notify('info', '已加载后台页面。');
    void loadList(false);
})();
</script>
</body>
</html>
