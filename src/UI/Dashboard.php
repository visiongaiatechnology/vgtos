<?php
declare(strict_types=1);

namespace VGT\Omega\UI;

use VGT\Omega\System\VaultManager;

/**
 * PLATIN STATUS UI: State of the Art Dashboard.
 * Kombiniert Neo-Brutalism mit Glassmorphism.
 * VGT FIX: Synchronisiert die Ansicht zwingend mit dem In-Memory-State des RAMs.
 */
final class Dashboard {
    
    public static function init(): void {
        \add_action('admin_menu', [self::class, 'registerMenu']);
    }

    public static function registerMenu(): void {
        // Main VGT OS Menu
        \add_menu_page(
            'VGT OS Dashboard', 
            'VGT OS', 
            'activate_plugins', 
            'vgt-console', 
            [self::class, 'render'], 
            'dashicons-superhero', // Custom Icon oder Dashicon
            2
        );
    }

    public static function render(): void {
        $artifacts = VaultManager::getArtifactsList();
        $total = count($artifacts);
        
        // VGT SUPREME FIX: Abfrage des echten RAM-Status (Single-Source-of-Truth).
        // Umgeht die DB und verifiziert, ob der Schlüssel wirklich im System geladen ist.
        $active = 0;
        foreach ($artifacts as $id => $val) {
            if (VaultManager::isUnlocked($id)) {
                $active++;
            }
        }

        ?>
        <style>
            :root { 
                --v-bg: #000000; 
                --v-panel: rgba(24, 24, 27, 0.7); 
                --v-border: #27272a; 
                --v-accent: #10b981; 
                --v-danger: #ef4444; 
                --v-text: #e4e4e7; 
                --v-sub: #a1a1aa; 
            }
            .vgt-os-wrapper { 
                background: var(--v-bg); 
                color: var(--v-text); 
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
                padding: 2.5rem; 
                margin: 20px 20px 0 0; 
                border-radius: 12px; 
                border: 1px solid var(--v-border); 
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
                position: relative;
                overflow: hidden;
                text-rendering: optimizeLegibility;
                -webkit-font-smoothing: antialiased;
            }
            /* Ambient Glow */
            .vgt-os-wrapper::before {
                content: ''; position: absolute; top: -100px; left: -100px; width: 300px; height: 300px;
                background: radial-gradient(circle, rgba(16,185,129,0.15) 0%, rgba(0,0,0,0) 70%);
                z-index: 0; pointer-events: none;
            }

            .vgt-content { position: relative; z-index: 1; }
            .vgt-header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 1.5rem; margin-bottom: 2.5rem; }
            .vgt-title h1 { margin: 0; font-size: 2.2rem; font-weight: 800; letter-spacing: -0.05em; color: #ffffff; display: flex; align-items: center; gap: 12px; }
            .vgt-badge { background: rgba(16, 185, 129, 0.1); color: var(--v-accent); border: 1px solid rgba(16, 185, 129, 0.2); font-size: 0.75rem; padding: 4px 10px; border-radius: 99px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
            
            .vgt-metrics { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 3rem; }
            .vgt-metric-card { background: var(--v-panel); backdrop-filter: blur(10px); border: 1px solid var(--v-border); padding: 1.5rem; border-radius: 8px; transition: transform 0.2s, border-color 0.2s; }
            .vgt-metric-card:hover { border-color: rgba(16,185,129,0.4); transform: translateY(-2px); }
            .vgt-metric-val { font-size: 2rem; font-weight: 800; color: #fff; line-height: 1; margin-bottom: 0.5rem; }
            .vgt-metric-label { font-size: 0.8rem; color: var(--v-sub); text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em; }

            .vgt-grid { display: grid; grid-template-columns: 400px 1fr; gap: 2.5rem; }
            
            .vgt-panel { background: var(--v-panel); backdrop-filter: blur(12px); border: 1px solid var(--v-border); border-radius: 10px; overflow: hidden; }
            .vgt-panel-head { background: rgba(255,255,255,0.03); padding: 1.2rem 1.5rem; border-bottom: 1px solid var(--v-border); font-size: 0.95rem; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: 0.05em; }
            .vgt-panel-body { padding: 1.5rem; }

            .vgt-form-group { margin-bottom: 1.5rem; }
            .vgt-label { display: block; color: var(--v-sub); font-size: 0.85rem; font-weight: 500; margin-bottom: 0.5rem; }
            .vgt-input { width: 100%; background: rgba(0,0,0,0.5); border: 1px solid var(--v-border); color: #fff; padding: 0.8rem 1rem; font-family: monospace; border-radius: 6px; box-sizing: border-box; transition: all 0.2s; }
            .vgt-input:focus { border-color: var(--v-accent); outline: none; box-shadow: 0 0 0 3px rgba(16,185,129,0.1); }
            
            .vgt-btn { display: inline-flex; justify-content: center; align-items: center; width: 100%; padding: 1rem; font-weight: 700; border: none; border-radius: 6px; cursor: pointer; transition: all 0.2s; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.05em; }
            .vgt-btn-primary { background: var(--v-accent); color: #000; box-shadow: 0 4px 14px 0 rgba(16,185,129,0.39); }
            .vgt-btn-primary:hover { background: #34d399; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(16,185,129,0.5); }
            
            .vgt-artifact-list { list-style: none; padding: 0; margin: 0; }
            .vgt-artifact-item { border-bottom: 1px solid rgba(255,255,255,0.05); padding: 1.2rem 1.5rem; display: flex; justify-content: space-between; align-items: center; transition: background 0.2s; }
            .vgt-artifact-item:last-child { border-bottom: none; }
            .vgt-artifact-item:hover { background: rgba(255,255,255,0.02); }
            .vgt-artifact-id { color: #fff; font-family: monospace; font-size: 1.1rem; font-weight: 600; margin-bottom: 4px; }
            
            .vgt-status-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 0.75rem; font-weight: 600; padding: 2px 8px; border-radius: 4px; background: rgba(255,255,255,0.05); color: var(--v-sub); }
            .vgt-status-badge.active { background: rgba(16,185,129,0.1); color: var(--v-accent); }
            .dot { width: 6px; height: 6px; border-radius: 50%; }
            .dot.active { background: var(--v-accent); box-shadow: 0 0 8px var(--v-accent); }
            .dot.inactive { background: var(--v-sub); }

            .vgt-btn-danger-icon { background: rgba(239, 68, 68, 0.1); color: var(--v-danger); border: 1px solid rgba(239, 68, 68, 0.2); padding: 6px 12px; border-radius: 4px; font-weight: 600; font-size: 0.75rem; cursor: pointer; transition: all 0.2s; text-transform: uppercase; }
            .vgt-btn-danger-icon:hover { background: var(--v-danger); color: #fff; }
        </style>

        <div class="wrap" style="margin:0; padding:0;">
            <div class="vgt-os-wrapper">
                <div class="vgt-content">
                    
                    <div class="vgt-header">
                        <div class="vgt-title">
                            <h1>VGT OS <span class="vgt-badge">Core 4.0</span></h1>
                            <div style="color:var(--v-sub); font-size:0.95rem; margin-top:8px; font-weight:500;">Encrypted Artifact Vault & Bridge Infrastructure</div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:0.85rem; font-weight:700; color:var(--v-accent); letter-spacing:1px;">SYSTEM ONLINE</div>
                            <div style="font-size:0.75rem; color:var(--v-sub); margin-top:4px;">MEMORY STREAM ACTIVE</div>
                        </div>
                    </div>

                    <div class="vgt-metrics">
                        <div class="vgt-metric-card">
                            <div class="vgt-metric-val"><?php echo $total; ?></div>
                            <div class="vgt-metric-label">Mounted Artifacts</div>
                        </div>
                        <div class="vgt-metric-card">
                            <div class="vgt-metric-val" style="color:var(--v-accent);"><?php echo $active; ?></div>
                            <div class="vgt-metric-label">Decrypted Kernels</div>
                        </div>
                        <div class="vgt-metric-card">
                            <div class="vgt-metric-val" style="color:#60a5fa;">AES-GCM</div>
                            <div class="vgt-metric-label">Cipher Protocol</div>
                        </div>
                    </div>

                    <div class="vgt-grid">
                        
                        <!-- DEPLOYMENT WIDGET -->
                        <div>
                            <div class="vgt-panel">
                                <div class="vgt-panel-head">Deploy Artifact</div>
                                <div class="vgt-panel-body">
                                    <form action="<?php echo \admin_url('admin-post.php'); ?>" method="post" enctype="multipart/form-data">
                                        <input type="hidden" name="action" value="vgt_install_artifact">
                                        <?php \wp_nonce_field('vgt_action'); ?>
                                        
                                        <div class="vgt-form-group">
                                            <label class="vgt-label">Artifact Package (.zip)</label>
                                            <input type="file" name="artifact" accept=".zip" required class="vgt-input" style="padding: 10px;">
                                        </div>
                                        <div class="vgt-form-group">
                                            <label class="vgt-label">Decryption Key (Optional for unencrypted)</label>
                                            <input type="text" name="license_key" placeholder="Enter hex key..." class="vgt-input">
                                        </div>
                                        <button class="vgt-btn vgt-btn-primary">Initialize Deployment</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- VAULT WIDGET -->
                        <div class="vgt-panel">
                            <div class="vgt-panel-head">Artifact Vault</div>
                            <?php if(empty($artifacts)): ?>
                                <div style="padding: 5rem 2rem; text-align:center; color:var(--v-sub);">
                                    <div style="font-size:3rem; margin-bottom:1rem; opacity:0.5;">⛑</div>
                                    <h3 style="color:#fff; margin:0 0 10px 0;">Vault is empty</h3>
                                    <p style="margin:0;">No artifacts deployed in the memory stream.</p>
                                </div>
                            <?php else: ?>
                                <ul class="vgt-artifact-list">
                                <?php foreach($artifacts as $id => $val): 
                                    $is_unlocked = VaultManager::isUnlocked($id);
                                ?>
                                    <li class="vgt-artifact-item">
                                        <div>
                                            <div class="vgt-artifact-id"><?php echo esc_html($id); ?></div>
                                            <div class="vgt-status-badge <?php echo $is_unlocked ? 'active' : ''; ?>">
                                                <span class="dot <?php echo $is_unlocked ? 'active' : 'inactive'; ?>"></span>
                                                <?php echo $is_unlocked ? 'SECURE RUNTIME' : 'STANDARD / LOCKED'; ?>
                                            </div>
                                        </div>
                                        <div>
                                            <form action="<?php echo \admin_url('admin-post.php'); ?>" method="post" onsubmit="return confirm('Obliterate Artifact?');">
                                                <input type="hidden" name="action" value="vgt_delete_artifact">
                                                <input type="hidden" name="artifact_id" value="<?php echo esc_attr($id); ?>">
                                                <?php \wp_nonce_field('vgt_action'); ?>
                                                <button class="vgt-btn-danger-icon">Purge</button>
                                            </form>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}