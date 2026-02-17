<?php
/**
 * Filename: settings.php
 * Description: Application settings management with sectioned forms for API keys, discovery, countries, email, outreach, and site settings
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

session_start();
if (!isset($_SESSION['admin_user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../templates/layout.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cost-tracker.php';

// ---------------------------------------------------------------------------
// Helper: get or create a setting
// ---------------------------------------------------------------------------
function getSetting(string $key, string $default = ''): string
{
    try {
        $row = Database::fetch("SELECT `setting_value` FROM `settings` WHERE `setting_key` = ?", [$key]);
        if ($row && $row['setting_value'] !== null) {
            return $row['setting_value'];
        }
    } catch (\Throwable $e) {
        // Ignore
    }
    return $default;
}

function saveSetting(string $key, string $value, string $type = 'string', string $description = ''): void
{
    $existing = Database::fetch("SELECT `id` FROM `settings` WHERE `setting_key` = ?", [$key]);
    if ($existing) {
        Database::update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
    } else {
        Database::insert('settings', [
            'setting_key'   => $key,
            'setting_value' => $value,
            'setting_type'  => $type,
            'description'   => $description,
        ]);
    }
}

// ---------------------------------------------------------------------------
// Handle POST actions
// ---------------------------------------------------------------------------
$flashMessage = '';
$flashType    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';

    try {
        switch ($section) {
            case 'api_keys':
                $apiKey = trim($_POST['google_places_api_key'] ?? '');
                if ($apiKey !== '') {
                    saveSetting('google_places_api_key', $apiKey, 'string', 'Google Places API key for property discovery');
                }
                $flashMessage = 'API keys saved successfully.';
                $flashType    = 'success';
                Logger::info('admin', 'API keys updated');
                break;

            case 'discovery':
                $dailyBudget = trim($_POST['daily_api_budget'] ?? '10.00');
                $scrapeDelay = trim($_POST['scrape_delay_ms'] ?? '3000');
                $searchTerms = trim($_POST['discovery_search_terms'] ?? '');

                saveSetting('daily_api_budget', $dailyBudget, 'float', 'Maximum daily spend on third-party APIs (USD)');
                saveSetting('scrape_delay_ms', $scrapeDelay, 'int', 'Minimum delay between scrape requests in milliseconds');
                saveSetting('discovery_search_terms', $searchTerms, 'json', 'Discovery search terms, one per line');

                $flashMessage = 'Discovery settings saved successfully.';
                $flashType    = 'success';
                Logger::info('admin', 'Discovery settings updated');
                break;

            case 'countries':
                $countryTiers  = $_POST['country_tier'] ?? [];
                $countryActive = $_POST['country_active'] ?? [];

                foreach ($countryTiers as $countryId => $tier) {
                    $countryId = (int) $countryId;
                    $tier      = (int) $tier;
                    $isActive  = isset($countryActive[$countryId]) ? 1 : 0;

                    Database::update('countries', [
                        'tier'      => $tier,
                        'is_active' => $isActive,
                    ], 'id = ?', [$countryId]);
                }

                $flashMessage = 'Country priorities saved successfully.';
                $flashType    = 'success';
                Logger::info('admin', 'Country priorities updated');
                break;

            case 'email':
                saveSetting('smtp_host', trim($_POST['smtp_host'] ?? ''), 'string', 'SMTP mail server hostname');
                saveSetting('smtp_port', trim($_POST['smtp_port'] ?? '587'), 'int', 'SMTP mail server port');
                saveSetting('smtp_user', trim($_POST['smtp_user'] ?? ''), 'string', 'SMTP authentication username');

                // Only update password if a new one is provided
                $smtpPassword = $_POST['smtp_password'] ?? '';
                if ($smtpPassword !== '' && $smtpPassword !== '********') {
                    saveSetting('smtp_password', $smtpPassword, 'string', 'SMTP authentication password');
                }

                saveSetting('sender_name', trim($_POST['sender_name'] ?? ''), 'string', 'Email sender display name');
                saveSetting('sender_email', trim($_POST['sender_email'] ?? ''), 'string', 'Email sender address');

                $flashMessage = 'Email settings saved successfully.';
                $flashType    = 'success';
                Logger::info('admin', 'Email settings updated');
                break;

            case 'outreach':
                saveSetting('max_outreach_per_day', trim($_POST['max_outreach_per_day'] ?? '50'), 'int', 'Maximum outreach emails to send per day');
                $flashMessage = 'Outreach settings saved successfully.';
                $flashType    = 'success';
                break;

            case 'site':
                saveSetting('site_name', trim($_POST['site_name'] ?? 'Safari Traveller'), 'string', 'Website display name');
                saveSetting('site_url', trim($_POST['site_url'] ?? ''), 'string', 'Primary website URL');
                saveSetting('admin_email', trim($_POST['admin_email'] ?? ''), 'string', 'Primary administrator email address');
                $flashMessage = 'Site settings saved successfully.';
                $flashType    = 'success';
                Logger::info('admin', 'Site settings updated');
                break;
        }
    } catch (\Throwable $e) {
        $flashMessage = 'Save failed: ' . $e->getMessage();
        $flashType    = 'error';
        Logger::error('admin', 'Settings save failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Load current settings
// ---------------------------------------------------------------------------
try {
    // API Keys
    $googleApiKey = getSetting('google_places_api_key');

    // Discovery
    $dailyBudget   = getSetting('daily_api_budget', '10.00');
    $scrapeDelay   = getSetting('scrape_delay_ms', '3000');
    $searchTerms   = getSetting('discovery_search_terms', "safari lodge\ngame lodge\nbush camp\ntented camp\nluxury safari");

    // Countries
    $countries = Database::fetchAll("SELECT `id`, `name`, `iso_code`, `tier`, `is_active` FROM `countries` ORDER BY `name`");

    // Email
    $smtpHost     = getSetting('smtp_host');
    $smtpPort     = getSetting('smtp_port', '587');
    $smtpUser     = getSetting('smtp_user');
    $smtpPassword = getSetting('smtp_password');
    $senderName   = getSetting('sender_name', 'Safari Traveller');
    $senderEmail  = getSetting('sender_email');

    // Outreach
    $maxOutreach = getSetting('max_outreach_per_day', '50');

    // Site
    $siteName   = getSetting('site_name', 'Safari Traveller');
    $siteUrl    = getSetting('site_url', 'https://safari-traveller.com');
    $adminEmail = getSetting('admin_email');

} catch (\Throwable $e) {
    $googleApiKey = '';
    $dailyBudget = '10.00';
    $scrapeDelay = '3000';
    $searchTerms = '';
    $countries = [];
    $smtpHost = $smtpPort = $smtpUser = $smtpPassword = $senderName = $senderEmail = '';
    $maxOutreach = '50';
    $siteName = 'Safari Traveller';
    $siteUrl = '';
    $adminEmail = '';
    if (!$flashMessage) {
        $flashMessage = 'Error loading settings: ' . $e->getMessage();
        $flashType = 'error';
    }
}

// Mask the API key for display
$maskedApiKey = '';
if ($googleApiKey !== '') {
    $maskedApiKey = substr($googleApiKey, 0, 8) . str_repeat('*', max(0, strlen($googleApiKey) - 12)) . substr($googleApiKey, -4);
}

// ---------------------------------------------------------------------------
// Render page
// ---------------------------------------------------------------------------
renderAdminHeader('Settings');
?>

<?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<!-- Section 1: API Keys -->
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <h3 style="font-size:1rem;margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:2px solid var(--color-border-light);">API Keys</h3>
    <form method="post">
        <input type="hidden" name="section" value="api_keys">

        <div class="form-group" style="margin-bottom:1rem;">
            <label class="form-label">Google Places API Key</label>
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <input type="password" name="google_places_api_key" value="<?= htmlspecialchars($googleApiKey, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;" id="apiKeyInput" placeholder="Enter API key">
                <button type="button" class="btn btn-secondary btn-sm" onclick="togglePassword('apiKeyInput', this)" style="white-space:nowrap;">Show</button>
            </div>
            <?php if ($maskedApiKey): ?>
                <div class="form-help" style="margin-top:0.25rem;">Current: <?= htmlspecialchars($maskedApiKey, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Save API Keys</button>
    </form>
</div>

<!-- Section 2: Discovery Settings -->
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <h3 style="font-size:1rem;margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:2px solid var(--color-border-light);">Discovery Settings</h3>
    <form method="post">
        <input type="hidden" name="section" value="discovery">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Daily API Budget (USD)</label>
                <input type="number" name="daily_api_budget" value="<?= htmlspecialchars($dailyBudget, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;" step="0.01" min="0">
                <div class="form-help">Maximum daily spend on third-party APIs</div>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Scrape Delay (ms)</label>
                <input type="number" name="scrape_delay_ms" value="<?= htmlspecialchars($scrapeDelay, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;" min="1000" step="500">
                <div class="form-help">Minimum delay between scrape requests</div>
            </div>
        </div>

        <div class="form-group" style="margin-bottom:1rem;">
            <label class="form-label">Discovery Search Terms</label>
            <textarea name="discovery_search_terms" class="form-control" style="font-size:0.85rem;min-height:120px;" placeholder="One search term per line"><?= htmlspecialchars($searchTerms, ENT_QUOTES, 'UTF-8') ?></textarea>
            <div class="form-help">One search term per line. These are used when discovering properties via Google Places.</div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Save Discovery Settings</button>
    </form>
</div>

<!-- Section 3: Country Priority -->
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <h3 style="font-size:1rem;margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:2px solid var(--color-border-light);">Country Priority</h3>
    <form method="post">
        <input type="hidden" name="section" value="countries">

        <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
            <table class="table">
                <thead style="position:sticky;top:0;background:var(--color-bg);z-index:1;">
                    <tr>
                        <th>Country</th>
                        <th>ISO</th>
                        <th style="width:120px;">Tier</th>
                        <th style="width:80px;">Active</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($countries as $c): ?>
                        <tr>
                            <td style="font-weight:500;font-size:0.85rem;"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="font-size:0.85rem;color:var(--color-text-muted);"><?= htmlspecialchars($c['iso_code'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <select name="country_tier[<?= (int) $c['id'] ?>]" class="form-control" style="padding:0.3rem 0.5rem;font-size:0.8rem;">
                                    <?php for ($t = 1; $t <= 5; $t++): ?>
                                        <option value="<?= $t ?>" <?= (int) $c['tier'] === $t ? 'selected' : '' ?>>Tier <?= $t ?></option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                            <td style="text-align:center;">
                                <input type="checkbox" name="country_active[<?= (int) $c['id'] ?>]" value="1" <?= $c['is_active'] ? 'checked' : '' ?> style="accent-color:var(--color-primary);">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:1rem;">
            <button type="submit" class="btn btn-primary btn-sm">Save Country Priorities</button>
        </div>
    </form>
</div>

<!-- Section 4: Email Settings -->
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <h3 style="font-size:1rem;margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:2px solid var(--color-border-light);">Email Settings</h3>
    <form method="post">
        <input type="hidden" name="section" value="email">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">SMTP Host</label>
                <input type="text" name="smtp_host" value="<?= htmlspecialchars($smtpHost, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;" placeholder="smtp.example.com">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">SMTP Port</label>
                <input type="number" name="smtp_port" value="<?= htmlspecialchars($smtpPort, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;" placeholder="587">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">SMTP Username</label>
                <input type="text" name="smtp_user" value="<?= htmlspecialchars($smtpUser, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;" placeholder="user@example.com">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">SMTP Password</label>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <input type="password" name="smtp_password" value="<?= $smtpPassword !== '' ? '********' : '' ?>" class="form-control" style="font-size:0.85rem;" id="smtpPassInput" placeholder="Enter password">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="togglePassword('smtpPassInput', this)" style="white-space:nowrap;">Show</button>
                </div>
                <div class="form-help">Leave as ******** to keep current password</div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Sender Name</label>
                <input type="text" name="sender_name" value="<?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;" placeholder="Safari Traveller">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Sender Email</label>
                <input type="email" name="sender_email" value="<?= htmlspecialchars($senderEmail, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;" placeholder="noreply@safari-traveller.com">
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Save Email Settings</button>
    </form>
</div>

<!-- Section 5: Outreach Settings -->
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <h3 style="font-size:1rem;margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:2px solid var(--color-border-light);">Outreach Settings</h3>
    <form method="post">
        <input type="hidden" name="section" value="outreach">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Max Emails Per Day</label>
                <input type="number" name="max_outreach_per_day" value="<?= htmlspecialchars($maxOutreach, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;" min="1" max="500">
                <div class="form-help">Maximum outreach emails to send per day</div>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Default Template</label>
                <a href="outreach.php" class="btn btn-secondary btn-sm" style="margin-top:0.25rem;">Edit Template on Outreach Page</a>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Save Outreach Settings</button>
    </form>
</div>

<!-- Section 6: Site Settings -->
<div style="background:var(--color-white);border-radius:var(--radius-lg);padding:1.5rem;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;">
    <h3 style="font-size:1rem;margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:2px solid var(--color-border-light);">Site Settings</h3>
    <form method="post">
        <input type="hidden" name="section" value="site">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Site Name</label>
                <input type="text" name="site_name" value="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Site URL</label>
                <input type="url" name="site_url" value="<?= htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;" placeholder="https://safari-traveller.com">
            </div>
        </div>

        <div class="form-group" style="margin-bottom:1rem;">
            <label class="form-label">Admin Email</label>
            <input type="email" name="admin_email" value="<?= htmlspecialchars($adminEmail, ENT_QUOTES, 'UTF-8') ?>" class="form-control" style="font-size:0.85rem;max-width:400px;" placeholder="admin@safari-traveller.com">
            <div class="form-help">Primary administrator email for system notifications</div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Save Site Settings</button>
    </form>
</div>

<script>
function togglePassword(inputId, button) {
    var input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = 'Hide';
    } else {
        input.type = 'password';
        button.textContent = 'Show';
    }
}
</script>

<?php
renderAdminFooter();
