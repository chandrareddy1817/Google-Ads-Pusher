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
        .sidebar { width: 260px; background-color: var(--sidebar-bg); min-height: 100vh; position: fixed; border-right: 1px solid var(--border-color); }
        .sidebar-header { padding: 20px; display: flex; align-items: center; gap: 10px; }
        .sidebar .nav-link { color: var(--text-muted); padding: 12px 20px; font-weight: 500; }
        .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.05); border-left: 3px solid var(--primary); }
        .main-content { margin-left: 260px; padding: 24px; }
        
        .card { background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; color: white; }
        .card-header { border-bottom: 1px solid var(--border-color); font-weight: 600; }
        .kpi-value { font-size: 2rem; font-weight: 700; }
        .kpi-title { font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; }
        .table { color: var(--text-main); font-size: 0.85rem; }
        .table th { color: var(--text-muted); border-bottom: 1px solid var(--border-color); }
        .table td { border-bottom: 1px solid var(--border-color); }
        .status-paused { background: rgba(245, 158, 11, 0.2); color: var(--warning); padding: 4px 10px; border-radius: 20px; font-weight: bold; }
        .status-active { background: rgba(16, 185, 129, 0.2); color: var(--success); padding: 4px 10px; border-radius: 20px; font-weight: bold; }
        
        .form-control, .form-select { background-color: #0f111a; border-color: var(--border-color); color: white; }
        .form-control:focus, .form-select:focus { background-color: #0f111a; color: white; border-color: var(--primary); box-shadow: none; }
        .form-label { color: var(--text-muted); font-size: 0.85rem; font-weight: 600; }
    </style>
</head>
<body>

    <div class="sidebar d-flex flex-column py-3">
        <div class="sidebar-header">
            <div class="bg-primary text-white p-2 rounded"><i class="bi bi-lightning-charge-fill"></i></div>
            <h5 class="fw-bold m-0 text-white">AdPilot AI</h5>
        </div>
        <ul class="nav flex-column mt-3">
            <li class="nav-item"><a class="nav-link active" href="/"><i class="bi bi-speedometer2 me-2"></i> Dashboards</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold m-0">Live Performance Dashboard</h3>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createCampaignModal">
                <i class="bi bi-plus-lg"></i> Launch Campaign
            </button>
        </div>

        <!-- KPI Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card p-3">
                    <div class="kpi-title">Total Spend (30 Days)</div>
                    <div class="kpi-value">₹{{ number_format($totalSpend ?? 0, 2) }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-3">
                    <div class="kpi-title">Total Clicks</div>
                    <div class="kpi-value">{{ number_format($totalClicks ?? 0) }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-3">
                    <div class="kpi-title">Total Campaigns</div>
                    <div class="kpi-value">{{ count($campaigns) }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-3">
                    <div class="kpi-title">System Status</div>
                    <div class="kpi-value text-success" style="font-size: 1.5rem;"><i class="bi bi-check-circle-fill"></i> Connected</div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row g-4 mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header py-3">Clicks & Impressions (Last 30 Days)</div>
                    <div class="card-body">
                        <canvas id="performanceChart" height="80"></canvas>
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
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content bg-dark text-white border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-magic me-2"></i>AI Campaign Setup</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <!-- Left Column -->
                        <div class="col-md-6 mb-3">
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
                                    <label class="form-label">YouTube Video ID (Required for Video)</label>
                                    <input id="youtube-id" type="text" class="form-control form-control-sm" placeholder="e.g. dQw4w9WgXcQ">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Ad Headline (Max 30 chars)</label>
                                    <input id="video-headline" type="text" maxlength="30" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column -->
                        <div class="col-md-6 mb-3">
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
                                <input type="file" id="image-upload" class="form-control form-control-sm mb-2" accept="image/jpeg,image/png,image/gif">
                                <label class="form-label">Landscape Image (1.91:1, min 600x314)</label>
                                <input type="file" id="image-landscape-upload" class="form-control form-control-sm mb-2">
                                <label class="form-label">Logo (1:1, min 128x128)</label>
                                <input type="file" id="image-logo-upload" class="form-control form-control-sm">
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
        // Chart.js Logic
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
                plugins: { legend: { labels: { color: '#e2e8f0' } } },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: '#2d3748' } },
                    y: { ticks: { color: '#94a3b8' }, grid: { color: '#2d3748' } }
                }
            }
        });

        // Dynamic UI Toggle
        function toggleCampaignFields() {
            const type = document.getElementById('google-campaign-type').value;
            const searchFields = document.getElementById('search-fields');
            const videoFields = document.getElementById('video-fields');
            const imageFields = document.getElementById('image-fields');

            if (type === 'SEARCH') {
                searchFields.classList.remove('d-none');
                videoFields.classList.add('d-none');
                imageFields.classList.add('d-none'); 
            } else if (type === 'VIDEO') {
                searchFields.classList.add('d-none');
                videoFields.classList.remove('d-none');
                imageFields.classList.add('d-none'); 
            } else if (type === 'DISPLAY' || type === 'DEMAND_GEN') {
                searchFields.classList.remove('d-none'); 
                videoFields.classList.add('d-none');
                imageFields.classList.remove('d-none'); 
            }
        }
        
        // Run on load
        toggleCampaignFields();

        // Form Logic
        let b64_square = '';
        let b64_landscape = '';
        let b64_logo = '';

        function toBase64(file, cb) { 
            const r = new FileReader(); 
            r.onload = e => cb(e.target.result); 
            r.readAsDataURL(file); 
        }

        document.getElementById('image-upload').addEventListener('change', e => { 
            if(e.target.files[0]) {
                if (!['image/jpeg', 'image/png', 'image/gif'].includes(e.target.files[0].type)) return alert('Please upload JPEG, PNG, or GIF only.');
                toBase64(e.target.files[0], b => b64_square = b); 
            }
        });

        document.getElementById('image-landscape-upload').addEventListener('change', e => { 
            if(e.target.files[0]) toBase64(e.target.files[0], b => b64_landscape = b); 
        });

        document.getElementById('image-logo-upload').addEventListener('change', e => { 
            if(e.target.files[0]) toBase64(e.target.files[0], b => b64_logo = b); 
        });

        async function scrapeUrl(event) {
            const urlInput = document.getElementById('url-input').value;
            if (!urlInput) return alert('Enter URL');
            const btn = event.target; btn.innerText = '...'; btn.disabled = true;
            try {
                const res = await fetch('/api/ads/scrape', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ url: urlInput }) });
                const result = await res.json();
                if (result.success) document.getElementById('page-content').value = result.data.title + '\n' + result.data.description;
                else alert('Error');
            } catch (err) { alert('Failed'); }
            finally { btn.innerText = 'Fetch'; btn.disabled = false; }
        }

        async function generateAdCampaign() {
            const apiKey = document.getElementById('gemini-key').value;
            const content = document.getElementById('page-content').value;
            if (!apiKey || !content) return alert('Provide Key & URL');
            const btn = document.getElementById('btn-generate'); btn.innerText = 'Generating...'; btn.disabled = true;
            const prompt = `Return JSON: { "googleHeadlines": ["h1","h2","h3"], "googleDescriptions": ["d1","d2"], "metaPrimary": "caption", "keywords": ["k1"..."k35"] } based on: ${content}`;
            try {
                const res = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent?key=${apiKey}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ contents: [{ parts: [{ text: prompt }] }] }) });
                const data = await res.json();
                if (data.error) throw new Error(data.error.message);
                const json = JSON.parse(data.candidates[0].content.parts[0].text.trim().replace(/^```json\s*/i, '').replace(/\s*```$/i, ''));
                document.getElementById('final-keywords').value = json.keywords.join(', ');
                document.getElementById('g-headlines').value = json.googleHeadlines.join(', ');
                document.getElementById('g-descriptions').value = json.googleDescriptions.join(', ');
                document.getElementById('m-primary').value = json.metaPrimary;
                if(json.googleHeadlines[0]) document.getElementById('video-headline').value = json.googleHeadlines[0];
            } catch (e) { alert(e.message); }
            finally { btn.innerText = '🪄 Generate Copy'; btn.disabled = false; }
        }

        function checkAspectRatio(base64, expectedRatio, fieldName) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => {
                    const ratio = img.width / img.height;
                    const diff = Math.abs(ratio - expectedRatio) / expectedRatio;
                    if (diff > 0.02) { // Allow 2% error for manual crops
                        reject(`${fieldName}: Expected ratio ${expectedRatio}, got ${ratio.toFixed(2)} (${img.width}x${img.height})`);
                    } else {
                        resolve(true);
                    }
                };
                img.onerror = () => reject(`Could not read ${fieldName}`);
                img.src = base64;
            });
        }

        async function executePush() {
            const btn = document.getElementById('btn-push'); 
            btn.innerText = 'Launching...'; 
            btn.disabled = true;
            
            const type = document.getElementById('google-campaign-type').value;
            
            const headlines = document.getElementById('g-headlines').value.split(',').map(s => s.trim()).filter(s => s);
            const descriptions = document.getElementById('g-descriptions').value.split(',').map(s => s.trim()).filter(s => s);

            // VALIDATE IMAGE RATIOS BEFORE SENDING
            if (type === 'DISPLAY' || type === 'DEMAND_GEN') {
                if (!b64_square) { alert('Square image is required.'); btn.innerText = '🚀 Launch Campaign'; btn.disabled = false; return; }
                if (!b64_landscape && type === 'DISPLAY') { alert('Landscape image is required.'); btn.innerText = '🚀 Launch Campaign'; btn.disabled = false; return; }
                
                try {
                    await checkAspectRatio(b64_square, 1.0, 'Square Image');
                    if (b64_landscape) await checkAspectRatio(b64_landscape, 1.91, 'Landscape Image');
                    if (b64_logo) await checkAspectRatio(b64_logo, 1.0, 'Logo'); // Adjusted to 1.0 for Demand Gen, you can change to 4.0 for Display
                } catch (err) {
                    alert("Image Ratio Error: " + err);
                    btn.innerText = '🚀 Launch Campaign'; btn.disabled = false; return;
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
                
                keywords: document.getElementById('final-keywords').value.split(',').map(s=>s.trim()),
                headlines: headlines.length ? headlines : [document.getElementById('video-headline').value],
                descriptions: descriptions,
                youtube_video_id: document.getElementById('youtube-id').value,
                
                image_base64: b64_square,
                image_landscape_b64: b64_landscape,
                image_logo_b64: b64_logo,
            };

            try {
                const res = await fetch('/api/ads/publish', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(payload) });
                const result = await res.json();
                if (result.success) {
                    alert('✅ Campaign Pushed Successfully!');
                    window.location.reload();
                } else throw new Error(result.error);
            } catch (err) { alert('❌ Failed: ' + err.message); }
            finally { btn.innerText = '🚀 Launch Campaign'; btn.disabled = false; }
        }
    </script>
</body>
</html>