<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AdPilot AI - Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        :root {
            --bg-dark: #0f111a; --sidebar-bg: #1a1d2d; --card-bg: #1e2235;
            --text-main: #e2e8f0; --text-muted: #94a3b8; --primary: #3b82f6;
            --success: #10b981; --warning: #f59e0b; --border-color: #2d3748;
        }
        body { background-color: var(--bg-dark); color: var(--text-main); overflow-x: hidden; }

        /* ---------- Sidebar ---------- */
        .sidebar {
            width: 260px; background-color: var(--sidebar-bg); min-height: 100vh;
            position: fixed; top: 0; left: 0; z-index: 1040;
            border-right: 1px solid var(--border-color);
            transition: transform .25s ease;
        }
        .sidebar-header { padding: 20px; display: flex; align-items: center; gap: 10px; }
        .sidebar .nav-link { color: var(--text-muted); padding: 12px 20px; font-weight: 500; }
        .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.05); border-left: 3px solid var(--primary); }

        /* ---------- Main content ---------- */
        .main-content { margin-left: 260px; padding: 24px; }

        /* ---------- Mobile top bar ---------- */
        .mobile-topbar {
            display: none;
            position: sticky; top: 0; z-index: 1030;
            background: var(--sidebar-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 10px 14px;
        }

        /* ---------- Backdrop ---------- */
        .sidebar-backdrop {
            display: none;
            position: fixed; inset: 0; z-index: 1035;
            background: rgba(0,0,0,.55);
        }
        .sidebar-backdrop.show { display: block; }

        /* ---------- Cards ---------- */
        .card { background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; color: white; }
        .card-header { border-bottom: 1px solid var(--border-color); font-weight: 600; }
        .kpi-value { font-size: 2rem; font-weight: 700; line-height: 1.2; }
        .kpi-title { font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; }

        /* ---------- Table ---------- */
        .table { color: var(--text-main); font-size: 0.85rem; }
        .table th { color: var(--text-muted); border-bottom: 1px solid var(--border-color); white-space: nowrap; }
        .table td { border-bottom: 1px solid var(--border-color); white-space: nowrap; vertical-align: middle; }
        .status-paused { background: rgba(245, 158, 11, 0.2); color: var(--warning); padding: 4px 10px; border-radius: 20px; font-weight: bold; display: inline-block; }
        .status-active { background: rgba(16, 185, 129, 0.2); color: var(--success); padding: 4px 10px; border-radius: 20px; font-weight: bold; display: inline-block; }

        /* ---------- Forms ---------- */
        .form-control, .form-select { background-color: #0f111a; border-color: var(--border-color); color: white; }
        .form-control:focus, .form-select:focus { background-color: #0f111a; color: white; border-color: var(--primary); box-shadow: none; }
        .form-label { color: var(--text-muted); font-size: 0.85rem; font-weight: 600; }

        /* ---------- Recommendation rows ---------- */
        .rec-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 1rem; border-bottom: 1px solid var(--border-color);
            gap: 12px;
        }
        .rec-row:last-child { border-bottom: none !important; }

        /* ---------- Chart ---------- */
        .chart-wrap { position: relative; height: 320px; }
        .chart-wrap canvas { width: 100% !important; height: 100% !important; }

        /* ---------- Image previews ---------- */
        .img-preview {
            max-width: 100%; max-height: 100px; border-radius: 6px;
            border: 1px solid var(--border-color); margin-top: 6px; display: block;
        }
        .img-hint { font-size: 0.72rem; color: var(--text-muted); margin-top: 2px; }

        /* ---------- Mobile breakpoint ---------- */
        @media (max-width: 767.98px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 14px; padding-top: 8px; }
            .mobile-topbar { display: flex; align-items: center; justify-content: space-between; }
            .kpi-value { font-size: 1.4rem; }
            .kpi-title { font-size: 0.7rem; }
            .rec-row { flex-direction: column; align-items: stretch; }
            .rec-row .rec-action { width: 100%; }
            .rec-row .rec-action .btn { width: 100%; }
            .chart-wrap { height: 220px; }
            .page-title { font-size: 1.1rem; }
            .modal-dialog { margin: 0.5rem; }
            .modal-body { padding: 1rem; }
        }

        @media (max-width: 400px) {
            .main-content { padding: 10px; }
            .card-header { padding: 0.6rem 0.8rem; }
        }
    </style>
</head>
<body>

    <!-- Mobile top bar -->
    <div class="mobile-topbar d-md-none">
        <div class="d-flex align-items-center gap-2">
            <div class="bg-primary text-white p-1 rounded"><i class="bi bi-lightning-charge-fill"></i></div>
            <strong class="text-white">AdPilot AI</strong>
        </div>
        <button class="btn btn-sm btn-outline-light" onclick="toggleSidebar(true)" aria-label="Open menu">
            <i class="bi bi-list fs-5"></i>
        </button>
    </div>

    <!-- Backdrop -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar(false)"></div>

    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column py-3" id="appSidebar">
        <div class="sidebar-header d-flex justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <div class="bg-primary text-white p-2 rounded"><i class="bi bi-lightning-charge-fill"></i></div>
                <h5 class="fw-bold m-0 text-white">AdPilot AI</h5>
            </div>
            <button class="btn btn-sm btn-outline-light d-md-none" onclick="toggleSidebar(false)" aria-label="Close menu">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <ul class="nav flex-column mt-3">
            <li class="nav-item"><a class="nav-link active" href="/"><i class="bi bi-speedometer2 me-2"></i> Dashboards</a></li>
        </ul>
    </div>

    <!-- Main content -->
    <div class="main-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2 mb-4">
            <h3 class="fw-bold m-0 page-title">Live Performance Dashboard</h3>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createCampaignModal">
                <i class="bi bi-plus-lg"></i> Launch Campaign
            </button>
        </div>

        <!-- KPI Cards -->
        <div class="row g-3 g-md-4 mb-4">
            <div class="col-12 col-sm-6 col-md-4">
                <div class="card p-3">
                    <div class="kpi-title">Total Spend (30 Days)</div>
                    <div class="kpi-value">₹{{ number_format($totalSpend ?? 0, 2) }}</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <div class="card p-3">
                    <div class="kpi-title">Total Clicks</div>
                    <div class="kpi-value">{{ number_format($totalClicks ?? 0) }}</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <div class="card p-3">
                    <div class="kpi-title">Total Campaigns</div>
                    <div class="kpi-value">{{ count($campaigns) }}</div>
                </div>
            </div>
            
        </div>

        <!-- AI Recommendations Panel -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-info" style="border: 1px solid var(--primary);">
                    <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-robot"></i> AI Optimization Suggestions</span>
                        <span class="badge bg-light text-dark">{{ $recommendations->count() }} Pending</span>
                    </div>
                    <div class="card-body p-0">
                        @forelse($recommendations as $rec)
                            <div class="rec-row">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1 text-white">{{ $rec->title }}</h6>
                                    <small class="text-white">
                                        Type: {{ $rec->type }} | {{ $rec->impact_estimate }}
                                    </small>
                                </div>
                                <div class="rec-action d-flex gap-2">
                                    <button onclick="approveRec({{ $rec->id }}, this)" class="btn btn-sm btn-success fw-bold">
                                        <i class="bi bi-check-circle"></i>
                                        {{ $rec->type === 'KEYWORD_REPLACE' ? 'Replace Keyword' : 'Approve' }}
                                    </button>
                                    <button onclick="rejectRec({{ $rec->id }}, this)" class="btn btn-sm btn-outline-danger fw-bold">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="p-4 text-center text-muted">
                                <i class="bi bi-check2-circle fs-4"></i><br>
                                No pending recommendations. Your campaigns are fully optimized!
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header py-3">Clicks & Impressions (Last 30 Days)</div>
                    <div class="card-body">
                        <div class="chart-wrap">
                            <canvas id="performanceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Campaign Database Table -->
        <div class="card">
            <div class="card-header py-3">Live Campaigns (Google Ads)</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>CAMPAIGN NAME</th>
                            <th>BUDGET</th>
                            <th>STATUS</th>
                            <th>CLICKS</th>
                            <th>COST</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($campaigns as $campaign)
                        <tr>
                            <td>#{{ $campaign['id'] }}</td>
                            <td>{{ $campaign['name'] }}</td>
                            <td>₹{{ number_format($campaign['budget'], 2) }}</td>
                            <td>
                                @if(in_array($campaign['status'], ['ENABLED', 2]))
                                    <span class="status-active">Active</span>
                                @elseif(in_array($campaign['status'], ['PAUSED', 3]))
                                    <span class="status-paused">Paused</span>
                                @else
                                    <span class="badge bg-danger">Removed/Other</span>
                                @endif
                            </td>
                            <td>{{ number_format($campaign['clicks']) }}</td>
                            <td>₹{{ number_format($campaign['cost'], 2) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="text-center py-4">No live campaigns found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ==================== CREATE CAMPAIGN MODAL ==================== -->
    <div class="modal fade" id="createCampaignModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content bg-dark text-white border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-magic me-2"></i>AI Campaign Setup</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <!-- Left Column -->
                        <div class="col-12 col-md-6 mb-3">
                            <div class="mb-3">
                                <label class="form-label">Website URL</label>
                                <div class="input-group input-group-sm">
                                    <input id="url-input" type="url" class="form-control" placeholder="https://example.com">
                                    <button onclick="scrapeUrl(event)" id="btn-scrape" class="btn btn-primary">Fetch</button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Page Context</label>
                                <textarea id="page-content" rows="2" class="form-control form-control-sm" readonly></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Gemini API Key</label>
                                <input id="gemini-key" type="password" class="form-control form-control-sm">
                            </div>
                            <button onclick="generateAdCampaign()" id="btn-generate" class="btn btn-outline-info btn-sm w-100 fw-bold mb-3">🪄 Generate Copy</button>

                            <div id="search-fields">
                                <div class="mb-2">
                                    <label class="form-label">Keywords (Comma Separated)</label>
                                    <textarea id="final-keywords" rows="2" class="form-control form-control-sm"></textarea>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Headlines (Comma Separated)</label>
                                    <input id="g-headlines" type="text" class="form-control form-control-sm">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Descriptions (Comma Separated)</label>
                                    <input id="g-descriptions" type="text" class="form-control form-control-sm">
                                </div>
                            </div>

                            <div id="video-fields" class="d-none">
                                <div class="mb-2">
                                    <label class="form-label">YouTube Video ID (Required for Demand Gen)</label>
                                    <input id="youtube-id" type="text" class="form-control form-control-sm"
                                           placeholder="e.g. dQw4w9WgXcQ or full YouTube URL">
                                    <div class="img-hint">Paste the 11-char ID or a full youtube.com / youtu.be URL.</div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Ad Headline (Max 30 chars)</label>
                                    <input id="video-headline" type="text" maxlength="30" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>

                        <!-- Right Column -->
                        <div class="col-12 col-md-6 mb-3">
                            <div class="mb-2">
                                <label class="form-label">Meta Primary Text</label>
                                <textarea id="m-primary" rows="2" class="form-control form-control-sm"></textarea>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label">Budget</label>
                                    <input id="pub-budget" type="number" value="400" class="form-control form-control-sm">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Currency</label>
                                    <select id="pub-currency" class="form-select form-select-sm">
                                        <option value="INR">INR (₹)</option>
                                        <option value="USD">USD ($)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Campaign Type</label>
                                <select id="google-campaign-type" class="form-select form-select-sm" onchange="toggleCampaignFields()">
                                    <option value="SEARCH">Search (Text Ads)</option>
                                    <option value="DISPLAY">Display (Banner Ads)</option>
                                    <option value="DEMAND_GEN">Demand Gen (Social/Discover)</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Countries (Ctrl+Click)</label>
                                <select id="pub-countries" class="form-select form-select-sm" multiple size="3">
                                    <option value="IN" selected>India (IN)</option>
                                    <option value="US">United States (US)</option>
                                    <option value="GB">United Kingdom (GB)</option>
                                </select>
                            </div>
                            <div id="image-fields" class="mb-2">
                                <label class="form-label">Square Image (1:1, min 300x300)</label>
                                <input type="file" id="image-upload" class="form-control form-control-sm mb-1" accept="image/jpeg,image/png,image/gif">
                                <div class="img-hint">Will be auto-cropped to 1:1. Preview:</div>
                                <img id="preview-square" class="img-preview d-none" alt="Square preview">

                                <label class="form-label mt-2">Landscape Image (1.91:1, min 600x314)</label>
                                <input type="file" id="image-landscape-upload" class="form-control form-control-sm mb-1" accept="image/jpeg,image/png,image/gif">
                                <div class="img-hint">Will be auto-cropped to 1.91:1. Preview:</div>
                                <img id="preview-landscape" class="img-preview d-none" alt="Landscape preview">

                                <label class="form-label mt-2">Logo (1:1, min 128x128)</label>
                                <input type="file" id="image-logo-upload" class="form-control form-control-sm mb-1" accept="image/jpeg,image/png,image/gif">
                                <div class="img-hint">Will be auto-cropped to 1:1. Preview:</div>
                                <img id="preview-logo" class="img-preview d-none" alt="Logo preview">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" onclick="executePush()" id="btn-push" class="btn btn-success fw-bold">🚀 Launch Campaign</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ============================================================
        // Mobile sidebar toggle
        // ============================================================
        function toggleSidebar(open) {
            const sidebar = document.getElementById('appSidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            if (open) {
                sidebar.classList.add('open');
                backdrop.classList.add('show');
                document.body.style.overflow = 'hidden';
            } else {
                sidebar.classList.remove('open');
                backdrop.classList.remove('show');
                document.body.style.overflow = '';
            }
        }
        document.querySelectorAll('.sidebar .nav-link').forEach(el => {
            el.addEventListener('click', () => {
                if (window.innerWidth < 768) toggleSidebar(false);
            });
        });

        // ============================================================
        // Chart.js Logic
        // ============================================================
        const chartData = @json($chartData ?? []);
        const labels = chartData.map(item => item.date);
        const clicksData = chartData.map(item => item.clicks);
        const impressionsData = chartData.map(item => item.impressions);

        const ctx = document.getElementById('performanceChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Clicks', data: clicksData, backgroundColor: '#3b82f6', borderRadius: 4 },
                    { label: 'Impressions', data: impressionsData, backgroundColor: '#f59e0b', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#e2e8f0' } } },
                scales: {
                    x: { ticks: { color: '#94a3b8', maxRotation: 0, autoSkip: true }, grid: { color: '#2d3748' } },
                    y: { ticks: { color: '#94a3b8' }, grid: { color: '#2d3748' } }
                }
            }
        });

        // ============================================================
        // AI Recommendation Approval / Rejection
        // ============================================================
        async function approveRec(id, btnElement) {
            btnElement.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Applying...';
            btnElement.disabled = true;

            try {
                const res = await fetch(`/api/ads/recommendations/${id}/approve`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });
                const result = await res.json();

                if (result.success) {
                    btnElement.closest('.rec-row').remove();
                    alert('✅ Change applied successfully in Google Ads!');
                } else {
                    alert('❌ Failed to apply: ' + result.error);
                    btnElement.innerHTML = '<i class="bi bi-check-circle"></i> Approve';
                    btnElement.disabled = false;
                }
            } catch (err) {
                alert('❌ Network Error. Please check logs.');
                btnElement.innerHTML = '<i class="bi bi-check-circle"></i> Approve';
                btnElement.disabled = false;
            }
        }

        async function rejectRec(id, btnElement) {
            if (!confirm('Reject this recommendation? It will be hidden from the dashboard.')) return;

            btnElement.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            btnElement.disabled = true;

            try {
                const res = await fetch(`/api/ads/recommendations/${id}/reject`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });
                const result = await res.json();

                if (result.success) {
                    btnElement.closest('.rec-row').remove();
                } else {
                    alert('❌ Failed to reject: ' + result.error);
                    btnElement.innerHTML = '<i class="bi bi-x-circle"></i> Reject';
                    btnElement.disabled = false;
                }
            } catch (err) {
                alert('❌ Network Error.');
                btnElement.innerHTML = '<i class="bi bi-x-circle"></i> Reject';
                btnElement.disabled = false;
            }
        }

        // ============================================================
        // Dynamic UI Toggle
        // ============================================================
        function toggleCampaignFields() {
            const type = document.getElementById('google-campaign-type').value;
            const searchFields = document.getElementById('search-fields');
            const videoFields = document.getElementById('video-fields');
            const imageFields = document.getElementById('image-fields');

            if (type === 'SEARCH') {
                searchFields.classList.remove('d-none');
                videoFields.classList.add('d-none');
                imageFields.classList.add('d-none');
            } else if (type === 'DISPLAY') {
                searchFields.classList.remove('d-none');
                videoFields.classList.add('d-none');
                imageFields.classList.remove('d-none');
            } else if (type === 'DEMAND_GEN') {
                searchFields.classList.remove('d-none');
                videoFields.classList.remove('d-none');   // ✅ visible for DEMAND_GEN
                imageFields.classList.remove('d-none');
            }
        }
        toggleCampaignFields();

        // ============================================================
        // YouTube ID extraction
        // ============================================================
        function extractYouTubeId(input) {
            if (!input) return '';
            input = input.trim();

            // Already an ID?
            if (/^[a-zA-Z0-9_-]{11}$/.test(input)) return input;

            // Try to extract from common URL forms
            const patterns = [
                /(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/,
            ];
            for (const p of patterns) {
                const m = input.match(p);
                if (m) return m[1];
            }
            return input;
        }

        // ============================================================
        // Image handling — auto-crop in browser + preview
        // ============================================================
        let b64_square = '';
        let b64_landscape = '';
        let b64_logo = '';

        function cropImageToRatio(dataUri, targetRatio) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => {
                    const w = img.width, h = img.height;
                    const currentRatio = w / h;

                    if (Math.abs(currentRatio - targetRatio) / targetRatio <= 0.01) {
                        return resolve(dataUri);
                    }

                    let srcX = 0, srcY = 0, cropW = w, cropH = h;
                    if (currentRatio > targetRatio) {
                        cropW = Math.round(h * targetRatio);
                        srcX  = Math.round((w - cropW) / 2);
                    } else {
                        cropH = Math.round(w / targetRatio);
                        srcY  = Math.round((h - cropH) / 2);
                    }

                    const canvas = document.createElement('canvas');
                    canvas.width  = cropW;
                    canvas.height = cropH;
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, cropW, cropH);
                    ctx.drawImage(img, srcX, srcY, cropW, cropH, 0, 0, cropW, cropH);
                    resolve(canvas.toDataURL('image/jpeg', 0.9));
                };
                img.onerror = () => reject('Could not read image');
                img.src = dataUri;
            });
        }

        function readFileAsDataUrl(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = e => resolve(e.target.result);
                reader.onerror = reject;
                reader.readAsDataURL(file);
            });
        }

        function showPreview(imgEl, dataUri) {
            imgEl.src = dataUri;
            imgEl.classList.remove('d-none');
        }

        document.getElementById('image-upload').addEventListener('change', async e => {
            if (!e.target.files[0]) return;
            if (!['image/jpeg', 'image/png', 'image/gif'].includes(e.target.files[0].type)) {
                return alert('Please upload JPEG, PNG, or GIF only.');
            }
            try {
                const raw = await readFileAsDataUrl(e.target.files[0]);
                b64_square = await cropImageToRatio(raw, 1.0);
                showPreview(document.getElementById('preview-square'), b64_square);
            } catch (err) {
                alert('Square image: ' + err);
            }
        });

        document.getElementById('image-landscape-upload').addEventListener('change', async e => {
            if (!e.target.files[0]) return;
            if (!['image/jpeg', 'image/png', 'image/gif'].includes(e.target.files[0].type)) {
                return alert('Please upload JPEG, PNG, or GIF only.');
            }
            try {
                const raw = await readFileAsDataUrl(e.target.files[0]);
                b64_landscape = await cropImageToRatio(raw, 1.91);
                showPreview(document.getElementById('preview-landscape'), b64_landscape);
            } catch (err) {
                alert('Landscape image: ' + err);
            }
        });

        document.getElementById('image-logo-upload').addEventListener('change', async e => {
            if (!e.target.files[0]) return;
            if (!['image/jpeg', 'image/png', 'image/gif'].includes(e.target.files[0].type)) {
                return alert('Please upload JPEG, PNG, or GIF only.');
            }
            try {
                const raw = await readFileAsDataUrl(e.target.files[0]);
                b64_logo = await cropImageToRatio(raw, 1.0);
                showPreview(document.getElementById('preview-logo'), b64_logo);
            } catch (err) {
                alert('Logo image: ' + err);
            }
        });

        // ============================================================
        // URL scrape
        // ============================================================
        async function scrapeUrl(event) {
            const urlInput = document.getElementById('url-input').value;
            if (!urlInput) return alert('Enter URL');
            const btn = event.target; btn.innerText = '...'; btn.disabled = true;
            try {
                const res = await fetch('/api/ads/scrape', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ url: urlInput })
                });
                const result = await res.json();
                if (result.success) {
                    document.getElementById('page-content').value =
                        result.data.title + '\n' + result.data.description;
                } else {
                    alert('Error: ' + (result.error || 'unknown'));
                }
            } catch (err) {
                alert('Failed');
            } finally {
                btn.innerText = 'Fetch'; btn.disabled = false;
            }
        }

        // ============================================================
        // Gemini ad-copy generation (gemini-3.5-flash-lite — as required)
        // ============================================================
        async function generateAdCampaign() {
            const apiKey = document.getElementById('gemini-key').value;
            const content = document.getElementById('page-content').value;
            if (!apiKey || !content) return alert('Provide Key & URL');

            const btn = document.getElementById('btn-generate');
            btn.innerText = 'Generating...'; btn.disabled = true;

            const prompt = `Return JSON: { "googleHeadlines": ["h1","h2","h3"], "googleDescriptions": ["d1","d2"], "metaPrimary": "caption", "keywords": ["k1"..."k35"] } based on: ${content}`;
            const url = `https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent?key=${apiKey}`;

            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        contents: [{ parts: [{ text: prompt }] }],
                        generationConfig: { responseMimeType: 'application/json', temperature: 0.4 }
                    })
                });
                const data = await res.json();
                if (data.error) throw new Error(data.error.message);

                let raw = data.candidates?.[0]?.content?.parts?.[0]?.text ?? '';
                raw = raw.trim().replace(/^```json\s*/i, '').replace(/^```\s*/i, '').replace(/\s*```$/i, '');
                const json = JSON.parse(raw);

                document.getElementById('final-keywords').value = (json.keywords || []).join(', ');
                document.getElementById('g-headlines').value = (json.googleHeadlines || []).join(', ');
                document.getElementById('g-descriptions').value = (json.googleDescriptions || []).join(', ');
                document.getElementById('m-primary').value = json.metaPrimary || '';
                if (json.googleHeadlines && json.googleHeadlines[0]) {
                    document.getElementById('video-headline').value = json.googleHeadlines[0];
                }
            } catch (e) {
                alert('Gemini failed: ' + e.message);
            } finally {
                btn.innerText = '🪄 Generate Copy'; btn.disabled = false;
            }
        }

        // ============================================================
        // Non-blocking ratio warning
        // ============================================================
        function warnAspectRatio(base64, expectedRatio, fieldName) {
            return new Promise((resolve) => {
                const img = new Image();
                img.onload = () => {
                    const ratio = img.width / img.height;
                    const diff = Math.abs(ratio - expectedRatio) / expectedRatio;
                    if (diff > 0.05) {
                        resolve(confirm(
                            `${fieldName} ratio mismatch:\n` +
                            `  Expected ~${expectedRatio.toFixed(2)}:1\n` +
                            `  Got ${ratio.toFixed(2)}:1 (${img.width}x${img.height})\n\n` +
                            `The server will auto-crop it. Continue?`
                        ));
                    } else {
                        resolve(true);
                    }
                };
                img.onerror = () => resolve(false);
                img.src = base64;
            });
        }

        // ============================================================
        // Publish campaign
        // ============================================================
        async function executePush() {
            const btn = document.getElementById('btn-push');
            btn.innerText = 'Launching...';
            btn.disabled = true;

            const type = document.getElementById('google-campaign-type').value;

            const headlines = document.getElementById('g-headlines').value
                .split(',').map(s => s.trim()).filter(s => s);
            const descriptions = document.getElementById('g-descriptions').value
                .split(',').map(s => s.trim()).filter(s => s);

            // ---- HARD GUARD: DEMAND_GEN requires a valid YouTube ID ----
            let youtubeIdValue = '';
            if (type === 'DEMAND_GEN') {
                const rawYt = (document.getElementById('youtube-id')?.value || '').trim();
                if (!rawYt) {
                    alert('Demand Gen campaigns require a YouTube Video ID.\n\nPaste the 11-character video ID (e.g. dQw4w9WgXcQ) or a full YouTube URL.');
                    btn.innerText = '🚀 Launch Campaign'; btn.disabled = false;
                    return;
                }
                const parsed = extractYouTubeId(rawYt);
                if (!/^[a-zA-Z0-9_-]{11}$/.test(parsed)) {
                    alert('Invalid YouTube Video ID: "' + parsed + '"\n\nExpected exactly 11 characters (A-Z, a-z, 0-9, -, _).\n\nExample: dQw4w9WgXcQ');
                    btn.innerText = '🚀 Launch Campaign'; btn.disabled = false;
                    return;
                }
                youtubeIdValue = parsed;
            } else {
                // Non-DEMAND_GEN: send empty string, backend `nullable` accepts it
                youtubeIdValue = '';
            }

            // ---- Image ratio warnings ----
            if (type === 'DISPLAY' || type === 'DEMAND_GEN') {
                if (!b64_square) {
                    alert('Square image is required.');
                    btn.innerText = '🚀 Launch Campaign'; btn.disabled = false; return;
                }
                if (!b64_landscape && type === 'DISPLAY') {
                    alert('Landscape image is required.');
                    btn.innerText = '🚀 Launch Campaign'; btn.disabled = false; return;
                }
                if (!(await warnAspectRatio(b64_square, 1.0, 'Square Image'))) {
                    btn.innerText = '🚀 Launch Campaign'; btn.disabled = false; return;
                }
                if (b64_landscape && type === 'DISPLAY') {
                    if (!(await warnAspectRatio(b64_landscape, 1.91, 'Landscape Image'))) {
                        btn.innerText = '🚀 Launch Campaign'; btn.disabled = false; return;
                    }
                }
                if (b64_logo) {
                    if (!(await warnAspectRatio(b64_logo, 1.0, 'Logo'))) {
                        btn.innerText = '🚀 Launch Campaign'; btn.disabled = false; return;
                    }
                }
            }

            const payload = {
                platform: 'google',
                campaign_name: type + '_Campaign_' + Date.now(),
                campaign_type: type,
                target_url: document.getElementById('url-input').value,
                daily_budget: parseFloat(document.getElementById('pub-budget').value),
                currency: document.getElementById('pub-currency').value,
                target_countries: Array.from(document.getElementById('pub-countries').selectedOptions).map(o => o.value),

                keywords: document.getElementById('final-keywords').value
                    .split(',').map(s => s.trim()).filter(s => s),
                headlines: headlines.length ? headlines : [document.getElementById('video-headline').value],
                descriptions: descriptions,

                // ✅ always a string; empty for non-DEMAND_GEN
                youtube_video_id: youtubeIdValue,

                image_base64: b64_square,
                image_landscape_b64: b64_landscape,
                image_logo_b64: b64_logo,
            };

            console.log('Publishing payload:', payload); // debug aid

            try {
                const res = await fetch('/api/ads/publish', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();

                if (result.success) {
                    if (result.errors) {
                        alert('⚠️ Partially launched. Errors: ' + JSON.stringify(result.errors));
                    } else {
                        alert('✅ Campaign Pushed Successfully!');
                    }
                    window.location.reload();
                } else {
                    // Show Laravel validation errors nicely
                    if (result.errors) {
                        const lines = Object.entries(result.errors)
                            .map(([field, msgs]) => `• ${field}: ${Array.isArray(msgs) ? msgs.join(', ') : msgs}`);
                        throw new Error('Validation failed:\n' + lines.join('\n'));
                    }
                    throw new Error(result.error || result.message || 'Unknown error');
                }
            } catch (err) {
                alert('❌ Failed: ' + err.message);
            } finally {
                btn.innerText = '🚀 Launch Campaign';
                btn.disabled = false;
            }
        }
    </script>
</body>
</html>