<style>
    :root {
        --endtrack-primary: #6366f1;
        --endtrack-secondary: #06b6d4;
        --endtrack-bg: #f0f2f5;
        --endtrack-card: rgba(255, 255, 255, 0.65);
        --endtrack-card-solid: #ffffff;
        --endtrack-text: #1a1a2e;
        --endtrack-text-muted: #6b7280;
        --endtrack-border: rgba(255, 255, 255, 0.5);
        --endtrack-accent: #818CF8;
        --endtrack-glass: rgba(255, 255, 255, 0.45);
        --endtrack-glass-border: rgba(255, 255, 255, 0.6);
        --endtrack-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
        --endtrack-blur: blur(20px);
    }

    /* Loader Overlay */
    .endtrack-loader-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.8);
        backdrop-filter: blur(4px);
        z-index: 99999;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        color: white;
        text-align: center;
    }

    .endtrack-loader-content {
        max-width: 400px;
        padding: 40px;
    }

    .pizza-loader {
        font-size: 80px;
        margin-bottom: 20px;
        animation: rotate 2s linear infinite;
        display: inline-block;
    }

    @keyframes rotate {
        from {
            transform: rotate(0deg);
        }

        to {
            transform: rotate(360deg);
        }
    }

    .loader-text h3 {
        font-size: 24px;
        margin: 0 0 10px 0;
        color: white;
    }

    .loader-text p {
        font-size: 16px;
        opacity: 0.8;
        line-height: 1.5;
    }

    .endtrack-wrap {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        color: var(--endtrack-text);
        max-width: 1400px;
        margin: 20px auto;
        background: var(--endtrack-bg);
        border-radius: 24px;
        padding: 30px;
        border: none;
        box-shadow: none;
    }

    .endtrack-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        border-bottom: none;
        padding-bottom: 20px;
    }

    .endtrack-header img {
        max-width: 250px;
        display: block;
    }

    .endtrack-header h1 {
        font-size: 28px;
        font-weight: 800;
        background: linear-gradient(to right, var(--endtrack-primary), var(--endtrack-secondary));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin: 0;
    }

    .endtrack-nav {
        display: flex;
        gap: 10px;
        margin-bottom: 30px;
    }

    .endtrack-nav a {
        text-decoration: none;
        padding: 10px 20px;
        border-radius: 14px;
        font-weight: 600;
        color: var(--endtrack-text-muted);
        transition: all 0.3s ease;
        background: var(--endtrack-glass);
        backdrop-filter: var(--endtrack-blur);
        border: 1px solid var(--endtrack-glass-border);
    }

    .endtrack-nav a:hover {
        background: rgba(99, 102, 241, 0.1);
        color: var(--endtrack-primary);
    }

    .endtrack-nav a.active {
        background: var(--endtrack-primary);
        color: white;
        box-shadow: 0 4px 15px rgba(99, 102, 241, 0.35);
        border-color: transparent;
    }

    .endtrack-card {
        background: var(--endtrack-card);
        backdrop-filter: var(--endtrack-blur);
        -webkit-backdrop-filter: var(--endtrack-blur);
        border-radius: 20px;
        padding: 24px;
        border: 1px solid var(--endtrack-glass-border);
        box-shadow: var(--endtrack-shadow);
        margin-bottom: 24px;
    }

    .endtrack-card h2 {
        font-size: 1.25rem;
        font-weight: 700;
        margin-top: 0;
        margin-bottom: 20px;
        color: var(--endtrack-text);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .endtrack-table {
        width: 100%;
        border-collapse: collapse;
    }

    .endtrack-table th {
        text-align: left;
        padding: 12px 16px;
        background: rgba(241, 245, 249, 0.6);
        backdrop-filter: blur(10px);
        font-weight: 600;
        color: var(--endtrack-text-muted);
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
    }

    .endtrack-table td {
        padding: 16px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.5);
    }

    .endtrack-input {
        width: 100%;
        padding: 8px 12px;
        border-radius: 12px;
        border: 1px solid rgba(0, 0, 0, 0.08);
        background: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(10px);
        transition: all 0.3s ease;
    }

    .endtrack-input:focus {
        outline: none;
        border-color: var(--endtrack-primary);
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.12);
        background: rgba(255, 255, 255, 0.9);
    }

    .btn-primary {
        background: var(--endtrack-primary);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 2px 10px rgba(99, 102, 241, 0.25);
    }

    .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 15px rgba(99, 102, 241, 0.35);
    }

    .btn-outline {
        border: 1.5px solid rgba(99, 102, 241, 0.3);
        color: var(--endtrack-primary);
        background: var(--endtrack-glass);
        backdrop-filter: blur(10px);
        padding: 10px 20px;
        border-radius: 14px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
    }

    .btn-outline:hover {
        background: rgba(99, 102, 241, 0.08);
        border-color: var(--endtrack-primary);
    }

    .instruction-step {
        display: flex;
        gap: 16px;
        margin-bottom: 20px;
    }

    .step-number {
        background: var(--endtrack-primary);
        color: white;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        flex-shrink: 0;
    }

    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 700;
        background: #E0E7FF;
        color: var(--endtrack-primary);
        margin-right: 5px;
    }

    .badge-registration {
        background: #DBEAFE;
        color: #1E40AF;
    }

    .badge-direct {
        background: #D1FAE5;
        color: #065F46;
    }

    /* Emergency Button Styles */
    .btn-emergency-container {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        padding: 40px;
        gap: 60px;
    }

    .btn-creation-fields {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 25px;
    }

    .btn-emergency {
        position: relative;
        display: inline-block;
        width: 220px;
        height: 220px;
        background: #dc2626;
        border: 10px solid #991b1b;
        border-radius: 50%;
        color: white;
        font-family: 'Inter', sans-serif;
        font-size: 24px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 1px;
        cursor: pointer;
        box-shadow:
            0 15px 0 #991b1b,
            0 20px 25px -5px rgba(0, 0, 0, 0.5);
        transition: all 0.1s;
        outline: none;
        user-select: none;
        margin-bottom: 25px;
    }

    .btn-emergency:hover {
        background: #ef4444;
    }

    .btn-emergency:active {
        box-shadow:
            0 5px 0 #991b1b,
            0 5px 15px -5px rgba(0, 0, 0, 0.5);
        transform: translateY(10px);
    }

    .btn-emergency::before {
        content: "";
        position: absolute;
        top: 10%;
        left: 15%;
        width: 70%;
        height: 35%;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50% 50% 40% 40%;
    }

    .btn-emergency-base {
        background: #4b5563;
        padding: 15px 30px;
        border-radius: 12px;
        border-bottom: 8px solid #1f2937;
        display: inline-flex;
        flex-direction: column;
        align-items: center;
    }

    /* Futuristic Modal */
    .endtrack-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.9);
        backdrop-filter: blur(10px);
        z-index: 100000;
        align-items: center;
        justify-content: center;
    }

    .endtrack-modal {
        background: #0f172a;
        width: 100%;
        max-width: 500px;
        padding: 40px;
        border-radius: 24px;
        border: 1px solid rgba(129, 140, 248, 0.3);
        box-shadow: 0 0 50px rgba(79, 70, 229, 0.2), inset 0 0 20px rgba(79, 70, 229, 0.1);
        position: relative;
        overflow: hidden;
    }

    .endtrack-modal::before {
        content: "";
        position: absolute;
        top: -100px;
        left: -100px;
        width: 200px;
        height: 200px;
        background: radial-gradient(circle, rgba(79, 70, 229, 0.4) 0%, transparent 70%);
        z-index: 0;
    }

    .endtrack-modal-header {
        position: relative;
        z-index: 2;
        margin-bottom: 25px;
    }

    .endtrack-modal-header h2 {
        color: white;
        margin: 0;
        font-size: 24px;
        text-transform: uppercase;
        letter-spacing: 2px;
        font-weight: 800;
        text-shadow: 0 0 10px rgba(79, 70, 229, 0.5);
    }

    .endtrack-modal-content {
        position: relative;
        z-index: 2;
    }

    .endtrack-modal-footer {
        margin-top: 30px;
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        position: relative;
        z-index: 2;
    }

    .endtrack-btn-neon {
        background: var(--endtrack-primary);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1px;
        cursor: pointer;
        box-shadow: 0 0 15px rgba(79, 70, 229, 0.4);
        transition: all 0.3s;
    }

    .endtrack-btn-neon:hover {
        transform: translateY(-2px);
        box-shadow: 0 0 25px rgba(79, 70, 229, 0.6);
    }

    .endtrack-btn-cancel {
        background: transparent;
        color: #94a3b8;
        border: 1px solid #334155;
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
    }

    /* Modern Settings Layout */
    .settings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 24px;
        margin-top: 20px;
    }

    .settings-section-card {
        background: var(--endtrack-card);
        backdrop-filter: var(--endtrack-blur);
        border: 1px solid var(--endtrack-glass-border);
        border-radius: 20px;
        overflow: hidden;
        box-shadow: var(--endtrack-shadow);
        display: flex;
        flex-direction: column;
    }

    .settings-section-header {
        background: rgba(248, 250, 252, 0.5);
        backdrop-filter: blur(10px);
        padding: 16px 20px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.4);
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .settings-section-header h3 {
        margin: 0 !important;
        font-size: 16px !important;
        font-weight: 700 !important;
        color: var(--endtrack-text) !important;
        border-bottom: none !important;
        padding-bottom: 0 !important;
    }

    .settings-section-content {
        padding: 20px;
        flex-grow: 1;
    }

    .settings-row {
        margin-bottom: 20px;
    }

    .settings-row:last-child {
        margin-bottom: 0;
    }

    .settings-row label {
        display: block;
        font-weight: 600;
        margin-bottom: 8px;
        color: var(--endtrack-text-muted);
        font-size: 13px;
    }

    .settings-row .description {
        margin-top: 4px;
        font-size: 12px;
        color: var(--endtrack-text-muted);
    }

    .full-width-editor {
        grid-column: 1 / -1;
    }

    /* Rainbow Border Effect */
    .rainbow-border-wrap {
        position: relative;
        padding: 3px;
        border-radius: 12px;
        overflow: hidden;
        width: 100%;
        display: flex;
        box-sizing: border-box;
    }

    .rainbow-border-wrap::before {
        content: '';
        position: absolute;
        top: -150%;
        left: -150%;
        width: 400%;
        height: 400%;
        background: conic-gradient(#fffb00, #FF72FF, #69aff2, #aa69e7);
        animation: rotate-rainbow 3s linear infinite;
        z-index: 0;
    }

    @keyframes rotate-rainbow {
        100% {
            transform: rotate(360deg);
        }
    }

    .endtrack-input {
        box-sizing: border-box;
    }

    /* Toggle Switch */
    .switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 24px;
    }

    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 16px;
        width: 16px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        transition: .4s;
    }

    input:checked+.slider {
        background-color: #10b981;
    }

    input:focus+.slider {
        box-shadow: 0 0 1px #10b981;
    }

    input:checked+.slider:before {
        transform: translateX(26px);
    }

    .slider.round {
        border-radius: 24px;
    }

    .slider.round:before {
        border-radius: 50%;
    }

    /* Modern Full Screen Loader */
    #endtrack-creation-loader {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: conic-gradient(#fffb00, #FF72FF, #69aff2, #aa69e7);
        z-index: 999999;
        display: none;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        background-size: 200% 200%;
        animation: gradient-spin 4s linear infinite;
    }

    @keyframes gradient-spin {
        0% {
            background-position: 0% 50%;
        }

        50% {
            background-position: 100% 50%;
        }

        100% {
            background-position: 0% 50%;
        }
    }

    /* To make the conic gradient actually "move" nicely, standard conic-gradient is hard to animate directly with background-position in some browsers without pseudo-elements, 
       but let's try a rotating pseudo-element or just the raw gradient if the user accepts a simple spin. 
       Actually, the user asked for a "moving" loader. Let's make the background itself obscurely moving 
       or add a spinning ring.
       Let's stick to the requested colors in a conic gradient and maybe rotate the whole background?
       Or better: Use a CSS animation on the background image if it was linear, but for conic we usually rotate the container or a pseudo.
       Let's try a rotating background effect.
    */

    #endtrack-creation-loader::before {
        content: "";
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: conic-gradient(#fffb00, #FF72FF, #69aff2, #aa69e7);
        animation: spin-conic 4s linear infinite;
        z-index: -1;
    }

    @keyframes spin-conic {
        from {
            transform: rotate(0deg);
        }

        to {
            transform: rotate(360deg);
        }
    }

    #endtrack-creation-loader h1 {
        color: white;
        font-size: 3.5rem;
        font-weight: 900;
        text-transform: uppercase;
        max-width: 90%;
        line-height: 1.2;
        text-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        position: relative;
        z-index: 10;
        margin: 0;
        padding: 20px;
    }

    /* A simple spinner icon for extra movement */
    .pizza-spinner {
        font-size: 60px;
        animation: bounce 1s infinite alternate;
        margin-bottom: 30px;
        z-index: 10;
    }

    @keyframes bounce {
        from {
            transform: translateY(0);
        }

        to {
            transform: translateY(-20px);
        }
    }

    .btn-ai-page {
        background: #F1F5F9;
        color: var(--endtrack-text);
        border: none;
        width: 28px;
        height: 28px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        margin-left: 5px;
        vertical-align: middle;
    }

    .btn-ai-page:hover {
        background: var(--endtrack-primary);
        color: white;
    }

    .btn-ai-page .dashicons {
        font-size: 16px;
        width: 16px;
        height: 16px;
    }
</style>

<!-- Custom Loader Overlay -->
<div class="endtrack-loader-overlay" id="endtrackLoader">
    <div class="endtrack-loader-content">
        <div class="pizza-loader">🍕</div>
        <div class="loader-text">
            <h3>Trabajando en ello...</h3>
            <p>En menos que Irene te pone 10 tareas de Notion se harán los cambios. ¡Paciencia!</p>
        </div>
    </div>
</div>

<!-- Futuristic AI Popup -->
<div class="endtrack-modal-overlay" id="endtrackAIModal">
    <div class="endtrack-modal">
        <div class="endtrack-modal-header">
            <h2 id="endtrackModalTitle">AI DESIGN & COPY ENGINE</h2>
        </div>
        <div class="endtrack-modal-content">
            <label
                style="color: #cbd5e1; display: block; margin-bottom: 12px; font-weight: 600; font-size: 14px;">INTRODUCE
                ÓRDENES DE DISEÑO O TEXTO:</label>
            <textarea id="endtrackAIPromptInput" class="endtrack-input" rows="5"
                style="background: #1e293b; border-color: #334155; color: white; padding: 15px; font-size: 15px;"
                placeholder="Ej: Modifica el bloque 'Ventas' con fondo azul y añade un botón rojo debajo del título..."></textarea>
        </div>
        <div class="endtrack-modal-footer">
            <button type="button" class="endtrack-btn-cancel" id="closeAIModal">CANCELAR</button>
            <button type="button" class="endtrack-btn-neon" id="confirmAIPrompt">EJECUTAR IA</button>
        </div>
    </div>
</div>

<div class="endtrack-wrap">
    <header class="endtrack-header">
        <?php
$logo_src = !empty($texts['logo_admin_panel']) ? esc_url($texts['logo_admin_panel']) : '';
if ($logo_src): ?>
        <img src="<?php echo $logo_src; ?>" alt="Logo">
        <?php
else: ?>
        <h2 style="margin:0; color:black;">ENDTrack</h2>
        <?php
endif; ?>
        <div style="display: flex; gap: 15px; align-items: center;">
            <a href="<?php echo site_url('/endtrack-panel-admin-afiliado/'); ?>" target="_blank" class="btn-outline">
                <span class="dashicons dashicons-chart-bar" style="margin-top: 4px;"></span>
                Panel de Administración de Afiliados
            </a>
            <a href="<?php echo home_url('/endtrack-estadisticas/'); ?>" target="_blank" class="btn-outline"
                style="border-color: #6366f1; color: #6366f1;">
                <span class="dashicons dashicons-chart-line" style="margin-top: 4px;"></span>
                Estadísticas Pro
            </a>
            <!-- <a href="<?php echo home_url('/endtrack-panel-admin-afiliado/'); ?>" target="_blank" class="btn-outline"
                style="border-color: #4F46E5; color: #4F46E5;">
                <span class="dashicons dashicons-editor-expand" style="margin-top: 4px;"></span>
                Panel Afiliados
            </a> -->
            <!-- Grafana buttons removed -->

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin: 0;">
                <input type="hidden" name="action" value="endtrack_flush_rewrites">
                <?php wp_nonce_field('endtrack_flush_rewrites_action', 'endtrack_flush_rewrites_nonce'); ?>
                <!-- <button type="submit" class="btn-outline"
                    style="padding: 5px 10px; font-size: 11px; border-color: #94a3b8; color: #64748b;"
                    title="Si los enlaces -endtrack dan 404, pulsa aquí">
                    Corregir Enlaces 404
                </button> -->
            </form>
        </div>
    </header>

    <?php
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'launches';
$launches = get_option('endtrack_launches', array());
$launch_configs = get_option('endtrack_launch_configs', array());
$texts = get_option('endtrack_texts', array());
?>

    <nav class="endtrack-nav">
        <a href="<?php echo add_query_arg('tab', 'launches'); ?>"
            class="<?php echo $active_tab == 'launches' ? 'active' : ''; ?>">Lanzamientos</a>
        <a href="<?php echo add_query_arg('tab', 'texts'); ?>"
            class="<?php echo $active_tab == 'texts' ? 'active' : ''; ?>">Textos y
            Configuración</a>
        <a href="<?php echo add_query_arg('tab', 'integrations'); ?>"
            class="<?php echo $active_tab == 'integrations' ? 'active' : ''; ?>">Integraciones</a>
        <a href="<?php echo add_query_arg('tab', 'help'); ?>"
            class="<?php echo $active_tab == 'help' ? 'active' : ''; ?>">Instrucciones y
            Ayuda</a>
    </nav>

    <?php if (isset($_GET['message'])): ?>
    <div style="margin: 20px 0; padding: 15px; border-radius: 12px; border-left: 6px solid; font-weight: 500; font-size: 15px; 
            <?php
    $m = $_GET['message'];
    if (strpos($m, 'error') !== false || strpos($m, 'fail') !== false) {
        echo 'background: #fef2f2; border-color: #ef4444; color: #991b1b; box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.1);';
    }
    else {
        echo 'background: #f0fdf4; border-color: #22c55e; color: #166534; box-shadow: 0 4px 6px -1px rgba(34, 197, 94, 0.1);';
    }
?>">
        <?php
    switch ($_GET['message']) {
        case 'launch_created':
            echo '✅ ¡Lanzamiento creado con éxito! Las páginas se han generado y organizado.';
            break;
        case 'launch_created_grafana_error':
            echo '⚠️ Lanzamiento creado, pero la conexión con Grafana ha fallado. Revisa tu URL y Token en la pestaña "Integraciones".';
            break;
        case 'integrations_saved':
            echo '✅ Integraciones guardadas correctamente.';
            break;
        case 'texts_saved':
            echo '✅ Configuración de textos y IDs guardada.';
            break;
        case 'launch_deleted':
            echo '🗑️ Lanzamiento eliminado correctamente.';
            break;
        case 'rewrites_flushed':
            echo '🔗 Permalinks de WordPress actualizados correctamente.';
            break;
        default:
            echo 'ℹ️ ' . esc_html($_GET['message']);
            break;
    }
?>
    </div>
    <?php
endif; ?>

    <?php if ($active_tab == 'launches'): ?>
<style>/* et-launches-table-css-v1 */
/* et-launch-cards-v1 */
.et-launches-grid { display: flex; flex-direction: column; gap: 18px; margin-top: 10px; }
.et-launch-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px 24px; box-shadow: 0 2px 8px rgba(15,23,42,.04); transition: box-shadow .15s; }
.et-launch-card:hover { box-shadow: 0 6px 16px rgba(15,23,42,.06); }
.et-lc-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; padding-bottom: 14px; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; }
.et-lc-title { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.et-lc-title h3 { margin: 0; font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: -.3px; }
.et-lc-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.et-toggle-wrap { display: flex; align-items: center; gap: 8px; }
.et-toggle-label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: .5px; }
.et-lc-stats-btn { background: #6366f1 !important; border-color: #6366f1 !important; display: inline-flex !important; align-items: center; gap: 4px; padding: 6px 12px !important; height: auto !important; line-height: 1.4 !important; }
.et-lc-delete { color: #ef4444 !important; text-decoration: none; padding: 6px; line-height: 1; }
.et-lc-delete:hover { color: #dc2626 !important; }
.et-lc-mini-grid { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr) minmax(0, .9fr); gap: 14px; margin-bottom: 16px; }
.et-mini-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; }
.et-mini-card h4 { margin: 0 0 8px; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #475569; font-weight: 700; }
.et-lc-pages-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.et-pages-col h5 { margin: 0 0 8px; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #475569; font-weight: 700; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 6px; }
.et-pages-col h5 small { color: #94a3b8; font-size: 10px; font-weight: 600; }
.et-pages-col .et-page-list { max-width: none !important; }
.et-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
@media (max-width: 1200px) { .et-lc-pages-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 1200px) { .et-lc-mini-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }
@media (max-width: 720px) { .et-lc-mini-grid, .et-lc-pages-grid { grid-template-columns: 1fr; } }

#endtrack-creds-modal { color: #0f172a; }
#endtrack-creds-modal h3, #endtrack-creds-modal h4, #endtrack-creds-modal p, #endtrack-creds-modal label, #endtrack-creds-modal small, #endtrack-creds-modal code { color: #0f172a !important; }
#endtrack-creds-modal label { color: #334155 !important; font-weight: 700; }
#endtrack-creds-modal small { color: #64748b !important; font-weight: 400; }
#endtrack-creds-modal input { color: #0f172a !important; background: #fff !important; border-color: #cbd5e1 !important; }
#endtrack-creds-modal input::placeholder { color: #94a3b8; }
#endtrack-creds-modal #endtrack-creds-status { color: #64748b; }
.endtrack-table { table-layout: auto; }
.endtrack-table th, .endtrack-table td { vertical-align: top; padding: 10px 12px !important; }
.endtrack-table thead th { position: sticky; top: 0; background: #f8fafc; z-index: 5; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #64748b; }
.endtrack-table tbody tr { transition: background .15s; }
.endtrack-table tbody tr:hover { background: #f8fafc; }
.endtrack-table tbody tr td { border-bottom: 1px solid #e2e8f0; }
.endtrack-table .badge { font-size: 10px; padding: 2px 8px; border-radius: 99px; font-weight: 600; }
.endtrack-table .badge-direct { background: rgba(16,185,129,.12); color: #047857; }
.endtrack-table .badge-registration { background: rgba(99,102,241,.12); color: #4338ca; }
.et-page-list li:hover { background: #f8fafc !important; }
.btn-edit-creds { background: linear-gradient(135deg,#6366f1,#8b5cf6) !important; background-color: #6366f1 !important; color: #fff !important; border: none !important; padding: 5px 11px !important; border-radius: 6px !important; font-weight: 600; }
.btn-edit-creds:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(99,102,241,.3); }
.endtrack-products-input { font-size: 10px !important; line-height: 1.3 !important; }
.btn-save-products { padding: 3px 9px !important; font-size: 11px !important; }
</style>



    <?php if (!empty($launches)): ?>
    <div style="margin-bottom: 24px; text-align: right;">
        <button type="button" id="endtrack-toggle-create"
            onclick="var f=document.getElementById('endtrack-create-form');var b=this;if(f.style.display==='none'){f.style.display='block';b.innerHTML='<span class=\'dashicons dashicons-no\' style=\'margin-right:6px;vertical-align:middle;\'></span>Cerrar';}else{f.style.display='none';b.innerHTML='<span class=\'dashicons dashicons-plus-alt2\' style=\'margin-right:6px;vertical-align:middle;\'></span>Nuevo Lanzamiento';}"
            style="background: var(--endtrack-primary); color: white; border: none; padding: 12px 24px; border-radius: 14px; font-weight: 700; font-size: 15px; cursor: pointer; box-shadow: 0 4px 15px rgba(99,102,241,0.3); transition: all 0.3s;">
            <span class="dashicons dashicons-plus-alt2" style="margin-right: 6px; vertical-align: middle;"></span>Nuevo
            Lanzamiento
        </button>
    </div>
    <?php
    endif; ?>

    <div id="endtrack-create-form" class="endtrack-card"
        style="<?php echo !empty($launches) ? 'display:none;' : ''; ?> background: var(--endtrack-glass); border: 1px solid var(--endtrack-glass-border);">
        <h2 style="justify-content: center; font-size: 1.5rem; margin-bottom: 30px;">
            <span class="dashicons dashicons-rocket"
                style="color: var(--endtrack-primary); font-size: 24px; width: 24px; height: 24px;"></span>
            NUEVO LANZAMIENTO
        </h2>

        <!-- Loader Container -->
        <div id="endtrack-creation-loader">
            <div class="pizza-spinner">🍕</div>
            <h1>se está creando todo el lanzamiento en menos de lo que Juan se come un trozo de pizza.</h1>
        </div>

        <script>
            function showEndtrackLoader() {
                const nameInput = document.querySelector('input[name="launch_name"]');
                if (nameInput && nameInput.value.trim() !== '') {
                    document.getElementById('endtrack-creation-loader').style.display = 'flex';
                }
            }
        </script>

        <form method="post" onsubmit="showEndtrackLoader()" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="endtrack_create_launch">
            <?php wp_nonce_field('endtrack_create_launch_action', 'endtrack_create_launch_nonce'); ?>

            <div class="btn-emergency-container">
                <div class="btn-creation-fields">
                    <div style="display: flex; gap: 20px; width: 100%;">
                        <div style="flex: 2;">
                            <label
                                style="display: block; text-align: left; font-weight: 700; margin-bottom: 8px; color: #475569;">NOMBRE
                                DEL LANZAMIENTO</label>
                            <div class="rainbow-border-wrap" style="height: 60px;">
                                <input type="text" name="launch_name" class="endtrack-input"
                                    placeholder="ej. PGmarzo2025"
                                    style="padding: 0 15px; font-size: 18px; font-weight: 700; text-transform: lowercase; height: 100%; border: none; width: 100%; box-sizing: border-box; position: relative; z-index: 1; background: #fff;"
                                    required>
                            </div>
                        </div>
                        <div style="flex: 1;">
                            <label
                                style="display: block; text-align: left; font-weight: 700; margin-bottom: 8px; color: #475569;">TIPO</label>
                            <select name="launch_type" class="endtrack-input"
                                style="padding: 0 15px; font-size: 16px; height: 60px; width: 100%; box-sizing: border-box;"
                                required>
                                <option value="1">Venta Directa</option>
                                <option value="2">Con Registro</option>
                                <option value="3">Sin crear páginas</option>
                            </select>
                        </div>
                    </div>

                    <!-- URLs de referencia para imitar diseño -->
                    <div id="endtrack-url-fields"
                        style="width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div id="url-field-registro" style="display: none;">
                            <label
                                style="display: block; text-align: left; font-weight: 700; margin-bottom: 8px; color: #475569;">
                                URL DISEÑO — PÁGINA DE SUSCRIPCIÓN
                            </label>
                            <input type="url" name="url_registro" class="endtrack-input"
                                placeholder="https://ejemplo.com/registro" style="padding: 0 15px; height: 50px;">
                            <small style="color: #64748b;">La IA imitará la estructura de esta URL.</small>
                        </div>
                        <div id="url-field-gracias-registro" style="display: none;">
                            <label
                                style="display: block; text-align: left; font-weight: 700; margin-bottom: 8px; color: #475569;">
                                URL DISEÑO — GRACIAS POR REGISTRARTE
                            </label>
                            <input type="url" name="url_gracias_registro" class="endtrack-input"
                                placeholder="https://ejemplo.com/gracias-registro"
                                style="padding: 0 15px; height: 50px;">
                            <small style="color: #64748b;">La IA imitará la estructura de esta URL.</small>
                        </div>
                        <div id="url-field-venta" style="display: none;">
                            <label
                                style="display: block; text-align: left; font-weight: 700; margin-bottom: 8px; color: #475569;">
                                URL DISEÑO — PÁGINA DE VENTA
                            </label>
                            <input type="url" name="url_venta" class="endtrack-input"
                                placeholder="https://ejemplo.com/venta" style="padding: 0 15px; height: 50px;">
                            <small style="color: #64748b;">La IA imitará la estructura de esta URL.</small>
                        </div>
                        <div id="url-field-gracias-compra" style="display: none;">
                            <label
                                style="display: block; text-align: left; font-weight: 700; margin-bottom: 8px; color: #475569;">
                                URL DISEÑO — GRACIAS POR COMPRAR
                            </label>
                            <input type="url" name="url_gracias_compra" class="endtrack-input"
                                placeholder="https://ejemplo.com/gracias-compra" style="padding: 0 15px; height: 50px;">
                            <small style="color: #64748b;">La IA imitará la estructura de esta URL.</small>
                        </div>
                    </div>

                    <!-- AI Prompt Field -->
                    <div style="width: 100%;">
                        <label
                            style="display: block; text-align: left; font-weight: 700; margin-bottom: 8px; color: #475569;">
                            🤖 PROMPT DE IA (OPCIONAL)
                        </label>
                        <textarea name="ai_prompt" class="endtrack-input" rows="4"
                            placeholder="Ej: Genera copy para un curso de marketing digital dirigido a emprendedores..."
                            style="padding: 15px; font-size: 14px; line-height: 1.6; resize: vertical; font-family: inherit;"></textarea>
                        <small style="color: #64748b; display: block; margin-top: 8px;">
                            Si completas este campo, la IA generará automáticamente el copy.
                        </small>
                    </div>

                    <script>
                        (function () { var s = document.querySelector('select[name="launch_type"]'), r = document.getElementById('url-field-registro'), gr = document.getElementById('url-field-gracias-registro'), v = document.getElementById('url-field-venta'), gc = document.getElementById('url-field-gracias-compra'), w = document.getElementById('endtrack-url-fields'); function u() { var t = s.value; r.style.display = t === '2' ? 'block' : 'none'; gr.style.display = t === '2' ? 'block' : 'none'; v.style.display = (t === '1' || t === '2') ? 'block' : 'none'; gc.style.display = (t === '1' || t === '2') ? 'block' : 'none'; w.style.display = t === '3' ? 'none' : 'grid'; } s.addEventListener('change', u); u(); })();
                    </script>

                    <p class="description"
                        style="margin-top: 10px; font-style: italic; text-align: left; color: #94a3b8;">
                        <strong>ATENCIÓN:</strong> Al pulsar el botón gigante se crearán categorías, carpetas y páginas.
                    </p>
                </div>

                <div class="btn-emergency-base" style="transform: scale(1.1);">
                    <button type="submit" class="btn-emergency">CREAR</button>
                    <span style="color: white; font-weight: 800; font-size: 14px; letter-spacing: 2px;">PRESIONA PARA
                        LANZAR</span>
                </div>
            </div>
        </form>
    </div>

    <div class="endtrack-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0;">Lanzamientos Activos y Enlaces</h2>
            <div
                style="background: rgba(255, 251, 235, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(254, 243, 199, 0.6); color: #92400E; padding: 10px 15px; border-radius: 14px; font-size: 13px; max-width: 500px;">
                <span class="dashicons dashicons-info"
                    style="font-size: 18px; margin-right: 5px; vertical-align: text-bottom;"></span>
                Los checkbox que se marquen en las páginas serán los que aparezcan en el panel de afiliados.
            </div>
        </div>
        <?php
    if (!empty($launches)): ?>
        <div class="et-launches-grid">
                <?php foreach ($launches as $launch):
            $type_id = isset($launch_configs[$launch]['type']) ? $launch_configs[$launch]['type'] : 1;

            if ($type_id == 3) {
                $type_label = 'Sin crear páginas';
                $type_class = 'badge-direct'; // Or create a new style
            }
            elseif ($type_id == 2) {
                $type_label = 'Con Registro';
                $type_class = 'badge-registration';
            }
            else {
                $type_label = 'Venta Directa';
                $type_class = 'badge-direct';
            }

            // Helper to get pages for this launch + specific type
            // Mapping logic: launch name -> category slug (should be stored in option, but fallback to sanitize)
            $mapping = get_option('endtrack_launches_mapping', array());
            $launch_cat_slug = isset($mapping[$launch]) ? $mapping[$launch] : sanitize_title($launch);

            // Ensure launch category exists before querying
            $launch_cat = get_category_by_slug($launch_cat_slug);

            $get_pages_by_type = function ($type_slug) use ($launch_cat_slug) {
                if (!$launch_cat_slug)
                    return array();

                $args = array(
                    'post_type' => 'page',
                    'posts_per_page' => -1,
                    'tax_query' => array(
                        'relation' => 'AND',
                            array(
                            'taxonomy' => 'category',
                            'field' => 'slug',
                            'terms' => $launch_cat_slug,
                        ),
                            array(
                            'taxonomy' => 'category',
                            'field' => 'slug',
                            'terms' => $type_slug, // registro, venta, gracias
                        ),
                    ),
                );
                return get_posts($args);
            };

            $registros = $get_pages_by_type('registro');
            $gracias_reg = $get_pages_by_type('gracias-registro');
            $ventas = $get_pages_by_type('venta');
            $gracias = $get_pages_by_type('gracias');

            $render_page_list = function ($pages, $type_slug) {
                if (empty($pages))
                    return '<span style="color:#cbd5e1; font-size:11px; font-style:italic;">—</span>';

                $type_colors = array(
                    'registro' => '#6366f1',
                    'gracias-registro' => '#0ea5e9',
                    'venta' => '#10b981',
                    'gracias' => '#f59e0b',
                );
                $accent = isset($type_colors[$type_slug]) ? $type_colors[$type_slug] : '#64748b';
                $html = '<ul class="et-page-list" style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:3px;max-width:240px;">';
                foreach ($pages as $p) {
                    $edit_url = admin_url('post.php?post=' . $p->ID . '&action=elementor');
                    $view_url = get_permalink($p->ID);
                    $is_affiliate = get_post_meta($p->ID, '_endtrack_is_affiliate_link', true);
                    $checked = $is_affiliate ? 'checked' : '';
                    $title = esc_html($p->post_title);

                    $html .= '<li style="display:flex;align-items:center;gap:6px;background:#fff;padding:4px 7px;border-radius:6px;border-left:3px solid ' . $accent . ';border-top:1px solid #f1f5f9;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;font-size:11px;line-height:1.25;">';
                    $html .= '<input type="checkbox" class="endtrack-affiliate-toggle" data-post-id="' . $p->ID . '" ' . $checked . ' title="Mostrar en panel de afiliados" style="margin:0;flex-shrink:0;width:14px;height:14px;cursor:pointer;">';
                    $html .= '<span style="flex:1;min-width:0;font-weight:600;color:#334155;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' . esc_attr($p->post_title) . '">' . $title . '</span>';
                    $html .= '<a href="' . esc_url($view_url) . '" target="_blank" title="Ver" style="flex-shrink:0;color:#64748b;text-decoration:none;padding:2px 4px;border-radius:4px;background:#f8fafc;font-size:10px;">👁</a>';
                    $html .= '<a href="' . esc_url($edit_url) . '" target="_blank" title="Editar en Elementor" style="flex-shrink:0;color:#64748b;text-decoration:none;padding:2px 4px;border-radius:4px;background:#f8fafc;font-size:10px;">✏️</a>';
                    $html .= '</li>';
                }
                $html .= '</ul>';
                return $html;
            };
?>
                <article class="et-launch-card">
                    <header class="et-lc-head">
                        <div class="et-lc-title">
                            <h3><?php echo esc_html($launch); ?></h3>
                            <span class="badge <?php echo $type_class; ?>"><?php echo $type_label; ?></span>
                            <?php if (!$launch_cat): ?>
                            <span style="color:#ef4444; font-size:11px;">⚠️ Cat. no encontrada: <?php echo esc_html($launch_cat_slug); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="et-lc-actions">
                            <?php
            $visibility_map = get_option('endtrack_launch_visibility', array());
            $is_visible = isset($visibility_map[$launch]) ? $visibility_map[$launch] : false;
?>
                            <label class="et-toggle-wrap" title="Mostrar/Ocultar a Afiliados">
                                <span class="et-toggle-label">Visible</span>
                                <label class="switch">
                                    <input type="checkbox" class="endtrack-visibility-toggle"
                                        data-launch="<?php echo esc_attr($launch); ?>" <?php checked($is_visible, true); ?>>
                                    <span class="slider round"></span>
                                </label>
                            </label>
                            <a href="<?php echo esc_url(add_query_arg(array('launch' => $launch), home_url('/endtrack-estadisticas/'))); ?>"
                                target="_blank" class="button button-primary et-lc-stats-btn"
                                title="Ver Estadísticas Pro">
                                <span class="dashicons dashicons-chart-area"></span> Estadísticas
                            </a>
                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=endtrack_delete_launch&launch=' . urlencode($launch)), 'endtrack_delete_launch_action', 'endtrack_delete_launch_nonce'); ?>"
                                class="btn-delete-launch et-lc-delete" title="Borrar Lanzamiento">
                                <span class="dashicons dashicons-trash"></span>
                            </a>
                        </div>
                    </header>

                    <div class="et-lc-mini-grid">
                        <section class="et-mini-card">
                            <h4>Productos PDC</h4>
                            <?php
            $_safe_l = ($launch === 'legacy') ? 'legacy' : preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
            $_pgroups = function_exists('endtrack_get_launch_product_groups')
                ? endtrack_get_launch_product_groups($launch)
                : array('general' => array(), 'downsell' => array(), 'crosssell' => array());
            $_products_str  = implode(', ', array_map('strval', $_pgroups['general']));
            $_downsell_str  = implode(', ', array_map('strval', $_pgroups['downsell']));
            $_crosssell_str = implode(', ', array_map('strval', $_pgroups['crosssell']));
?>
                            <label style="display:block; font-size:10px; font-weight:700; color:#6366f1; text-transform:uppercase; margin-bottom:2px;">Principal (general)</label>
                            <textarea class="endtrack-products-input" data-launch="<?php echo esc_attr($_safe_l); ?>"
                                placeholder="prod_xxxx, prod_yyyy"
                                style="width:100%; min-height:46px; padding:8px; font-family:monospace; font-size:11px; border:1px solid #cbd5e1; border-radius:6px; resize:vertical;"><?php echo esc_textarea($_products_str); ?></textarea>
                            <div style="display:flex; align-items:center; gap:8px; margin-top:6px;">
                                <button class="btn-save-products button button-small" data-launch="<?php echo esc_attr($_safe_l); ?>" style="font-size:11px;">Guardar</button>
                                <span class="products-save-status" data-launch="<?php echo esc_attr($_safe_l); ?>" style="font-size:11px;"></span>
                            </div>

                            <label style="display:block; font-size:10px; font-weight:700; color:#f59e0b; text-transform:uppercase; margin:12px 0 2px;">Downsell</label>
                            <textarea class="endtrack-products-input-group" data-launch="<?php echo esc_attr($_safe_l); ?>" data-group="downsell"
                                placeholder="prod_xxxx, prod_yyyy"
                                style="width:100%; min-height:38px; padding:8px; font-family:monospace; font-size:11px; border:1px solid #cbd5e1; border-radius:6px; resize:vertical;"><?php echo esc_textarea($_downsell_str); ?></textarea>
                            <div style="display:flex; align-items:center; gap:8px; margin-top:6px;">
                                <button class="btn-save-products-group button button-small" data-launch="<?php echo esc_attr($_safe_l); ?>" data-group="downsell" style="font-size:11px;">Guardar</button>
                                <span class="products-group-status" data-launch="<?php echo esc_attr($_safe_l); ?>" data-group="downsell" style="font-size:11px;"></span>
                            </div>

                            <label style="display:block; font-size:10px; font-weight:700; color:#06b6d4; text-transform:uppercase; margin:12px 0 2px;">Cross-sell</label>
                            <textarea class="endtrack-products-input-group" data-launch="<?php echo esc_attr($_safe_l); ?>" data-group="crosssell"
                                placeholder="prod_xxxx, prod_yyyy"
                                style="width:100%; min-height:38px; padding:8px; font-family:monospace; font-size:11px; border:1px solid #cbd5e1; border-radius:6px; resize:vertical;"><?php echo esc_textarea($_crosssell_str); ?></textarea>
                            <div style="display:flex; align-items:center; gap:8px; margin-top:6px;">
                                <button class="btn-save-products-group button button-small" data-launch="<?php echo esc_attr($_safe_l); ?>" data-group="crosssell" style="font-size:11px;">Guardar</button>
                                <span class="products-group-status" data-launch="<?php echo esc_attr($_safe_l); ?>" data-group="crosssell" style="font-size:11px;"></span>
                                <small style="color:#94a3b8; margin-left:auto; font-size:10px;">IDs separados por coma</small>
                            </div>
                        </section>

                        <section class="et-mini-card">
                            <h4>Credenciales</h4>
                            <?php
            $_safe_l_cr = ($launch === 'legacy') ? 'legacy' : preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
            $_lcreds = get_option('endtrack_launch_credentials_' . $_safe_l_cr, array());
            if (!is_array($_lcreds)) $_lcreds = array();
            $_has_pdc = !empty($_lcreds['host']) || !empty($_lcreds['user']) || !empty($_lcreds['name']);
            $_has_stripe = !empty($_lcreds['stripe_key']);
?>
                            <div style="display:flex; align-items:center; gap:12px;">
                                <button type="button" class="btn-edit-creds button button-small"
                                    onclick="endtrackOpenCredsModal(this); return false;"
                                    data-launch="<?php echo esc_attr($_safe_l_cr); ?>"
                                    data-host="<?php echo esc_attr(isset($_lcreds['host']) ? $_lcreds['host'] : ''); ?>"
                                    data-user="<?php echo esc_attr(isset($_lcreds['user']) ? $_lcreds['user'] : ''); ?>"
                                    data-pass="<?php echo esc_attr(isset($_lcreds['pass']) ? $_lcreds['pass'] : ''); ?>"
                                    data-name="<?php echo esc_attr(isset($_lcreds['name']) ? $_lcreds['name'] : ''); ?>"
                                    data-stripe="<?php echo esc_attr(isset($_lcreds['stripe_key']) ? $_lcreds['stripe_key'] : ''); ?>"
                                    style="font-size:12px;">
                                    🔑 Editar
                                </button>
                                <div style="display:flex; flex-direction:column; gap:3px; font-size:11px;">
                                    <span style="color:<?php echo $_has_pdc ? '#10b981' : '#94a3b8'; ?>;"><?php echo $_has_pdc ? '● PDC override' : '○ PDC global'; ?></span>
                                    <span style="color:<?php echo $_has_stripe ? '#10b981' : '#94a3b8'; ?>;"><?php echo $_has_stripe ? '● Stripe override' : '○ Stripe global'; ?></span>
                                </div>
                            </div>
                        </section>

                        <section class="et-mini-card">
                            <h4>Comisión general</h4>
                            <?php
            $_safe_l_cm = ($launch === 'legacy') ? 'legacy' : preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
            $_comm = get_option('endtrack_launch_commission_' . $_safe_l_cm, '');
?>
                            <label style="display:block; font-size:10px; font-weight:700; color:#10b981; text-transform:uppercase; margin-bottom:2px;">% afiliados por defecto</label>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <input type="number" class="endtrack-commission-input" data-launch="<?php echo esc_attr($_safe_l_cm); ?>"
                                    step="0.5" min="0" max="100" value="<?php echo esc_attr($_comm); ?>" placeholder="ej. 30"
                                    style="width:90px; padding:6px 8px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
                                <button class="btn-save-commission button button-small" data-launch="<?php echo esc_attr($_safe_l_cm); ?>" style="font-size:11px;">Guardar</button>
                                <span class="commission-save-status" data-launch="<?php echo esc_attr($_safe_l_cm); ?>" style="font-size:11px;"></span>
                            </div>
                            <p style="font-size:10px; color:#94a3b8; margin:8px 0 0; line-height:1.35;">Se muestra en la página de captación de afiliados y se aplica a los afiliados sin comisión propia. Vacío = no fijada.</p>
                        </section>
                    </div>

                    <div class="et-lc-pages-grid">
                        <section class="et-pages-col">
                            <h5><span class="et-dot" style="background:#6366f1"></span> Registro <small>(<?php echo count($registros); ?>)</small></h5>
                            <?php echo $render_page_list($registros, 'registro'); ?>
                        </section>
                        <section class="et-pages-col">
                            <h5><span class="et-dot" style="background:#0ea5e9"></span> Gracias Registro <small>(<?php echo count($gracias_reg); ?>)</small></h5>
                            <?php echo $render_page_list($gracias_reg, 'gracias-registro'); ?>
                        </section>
                        <section class="et-pages-col">
                            <h5><span class="et-dot" style="background:#10b981"></span> Venta <small>(<?php echo count($ventas); ?>)</small></h5>
                            <?php echo $render_page_list($ventas, 'venta'); ?>
                        </section>
                        <section class="et-pages-col">
                            <h5><span class="et-dot" style="background:#f59e0b"></span> Gracias Compra <small>(<?php echo count($gracias); ?>)</small></h5>
                            <?php echo $render_page_list($gracias, 'gracias'); ?>
                        </section>
                    </div>
                </article>
                <?php
        endforeach; ?>

        </div>
        <p class="description" style="margin-top:20px;">
            <strong>Nota:</strong> Las páginas aparecen aquí automáticamente si tienen asignadas DOS categorías: la del
            <strong>Lanzamiento</strong> y el <strong>Tipo</strong> (registro, venta, gracias).
        </p>
        <script>
            // Bulletproof opener for credentials modal (no jQuery binding race)
            window.endtrackOpenCredsModal = function (btn) {
                try {
                    var modal = document.getElementById('endtrack-creds-modal');
                    if (!modal) { alert('Modal no encontrado. Recarga la página.'); return; }
                    document.getElementById('endtrack-creds-launch-name').textContent = btn.dataset.launch || '';
                    document.getElementById('endtrack-creds-host').value = btn.dataset.host || '';
                    document.getElementById('endtrack-creds-name').value = btn.dataset.name || '';
                    document.getElementById('endtrack-creds-user').value = btn.dataset.user || '';
                    document.getElementById('endtrack-creds-pass').value = btn.dataset.pass || '';
                    document.getElementById('endtrack-creds-stripe').value = btn.dataset.stripe || '';
                    modal.dataset.launch = btn.dataset.launch || '';
                    document.getElementById('endtrack-creds-status').textContent = '';
                    modal.style.display = 'flex';
                } catch (e) {
                    console.error('Error opening creds modal:', e);
                    alert('Error: ' + e.message);
                }
            };


            jQuery(document).ready(function ($) {
                var loader = $('#endtrackLoader');
                var aiModal = $('#endtrackAIModal');
                var aiInput = $('#endtrackAIPromptInput');
                var aiConfirmBtn = $('#confirmAIPrompt');
                var aiCancelBtn = $('#closeAIModal');
                var aiModalTitle = $('#endtrackModalTitle');
                var currentAIResolve = null;

                function openAIPromptModal(title, defaultValue = '') {
                    aiModalTitle.text(title);
                    aiInput.val(defaultValue);
                    aiModal.css('display', 'flex');
                    aiInput.focus();

                    return new Promise((resolve) => {
                        currentAIResolve = resolve;
                    });
                }

                aiConfirmBtn.on('click', function () {
                    var val = aiInput.val();
                    aiModal.hide();
                    if (currentAIResolve) currentAIResolve(val);
                });

                aiCancelBtn.on('click', function () {
                    aiModal.hide();
                    if (currentAIResolve) currentAIResolve(null);
                });

                $('.btn-delete-launch').on('click', function (e) {
                    if (!confirm('¿Estás seguro de que deseas eliminar permanentemente este lanzamiento? Se borrarán todos los datos de seguimiento asociados en la base de datos.')) {
                        e.preventDefault();
                    }
                });

                // Granular AI Regeneration (Single Page)
                $(document).on('click', '.btn-ai-page', async function (e) {
                    var self = $(this);
                    var postId = self.data('post-id');
                    var pageType = self.data('type');
                    var pageTitle = self.data('title');

                    var userPrompt = await openAIPromptModal("PÁGINA: " + pageTitle);

                    if (userPrompt === null) return;

                    loader.css('display', 'flex');

                    $.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        data: {
                            action: 'endtrack_regenerate_copy',
                            post_id: postId,
                            page_type: pageType,
                            custom_prompt: userPrompt,
                            nonce: '<?php echo wp_create_nonce("endtrack_regenerate_copy_nonce"); ?>'
                        },
                        success: function (response) {
                            loader.hide();
                            if (response.success) {
                                alert(response.data.message);
                                location.reload();
                            } else {
                                alert('Error: ' + response.data);
                            }
                        },
                        error: function () {
                            loader.hide();
                            alert('Error de conexión.');
                        }
                    });
                });

                $('.endtrack-affiliate-toggle').on('change', function () {
                    var self = $(this);
                    var postId = self.data('post-id');
                    var isActive = self.is(':checked') ? 1 : 0;

                    self.prop('disabled', true);

                    $.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        data: {
                            action: 'endtrack_toggle_affiliate_link',
                            post_id: postId,
                            active: isActive
                        },
                        success: function (response) {
                            self.prop('disabled', false);
                            if (!response.success) {
                                alert('Error: ' + response.data);
                            }
                        },
                        error: function () {
                            self.prop('disabled', false);
                            alert('Error de conexión.');
                        }
                    });
                });

                $('.endtrack-visibility-toggle').on('change', function () {
                    var self = $(this);
                    var launchName = self.data('launch');
                    var isVisible = self.is(':checked') ? 1 : 0;

                    self.prop('disabled', true);

                    $.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        data: {
                            action: 'endtrack_toggle_visibility',
                            launch: launchName,
                            visible: isVisible,
                            nonce: '<?php echo wp_create_nonce('endtrack_toggle_visibility_nonce'); ?>'
                        },
                        success: function (response) {
                            self.prop('disabled', false);
                            if (!response.success) {
                                alert('Error: ' + response.data);
                                self.prop('checked', !isVisible); // Revert
                            }
                        },
                        error: function () {
                            self.prop('disabled', false);
                            alert('Error de conexión.');
                            self.prop('checked', !isVisible); // Revert
                        }
                    });
                });


                // Save PDC product IDs per launch
                $('.btn-save-products').on('click', function () {
                    var btn = $(this);
                    var launchName = btn.data('launch');
                    var $input = $('.endtrack-products-input[data-launch="' + launchName + '"]');
                    var $status = $('.products-save-status[data-launch="' + launchName + '"]');
                    var rawValue = $input.val();

                    btn.prop('disabled', true);
                    $status.text('Guardando…').css('color', '#64748b');

                    $.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        data: {
                            action: 'endtrack_save_launch_products',
                            launch: launchName,
                            products: rawValue,
                            nonce: '<?php echo wp_create_nonce('endtrack_save_launch_products_nonce'); ?>'
                        },
                        success: function (response) {
                            btn.prop('disabled', false);
                            if (response.success) {
                                $status.text('✓ ' + (response.data.count || 0) + ' guardados').css('color', '#10b981');
                                setTimeout(function () { $status.text(''); }, 3000);
                            } else {
                                $status.text('Error: ' + response.data).css('color', '#ef4444');
                            }
                        },
                        error: function () {
                            btn.prop('disabled', false);
                            $status.text('Error de conexión.').css('color', '#ef4444');
                        }
                    });
                });

                // Save Downsell / Cross-sell product IDs per launch
                $('.btn-save-products-group').on('click', function () {
                    var btn = $(this);
                    var launchName = btn.data('launch');
                    var group = btn.data('group');
                    var $input = $('.endtrack-products-input-group[data-launch="' + launchName + '"][data-group="' + group + '"]');
                    var $status = $('.products-group-status[data-launch="' + launchName + '"][data-group="' + group + '"]');

                    btn.prop('disabled', true);
                    $status.text('Guardando…').css('color', '#64748b');

                    $.ajax({
                        type: 'POST',
                        url: ajaxurl,
                        data: {
                            action: 'endtrack_save_launch_products_group',
                            launch: launchName,
                            group: group,
                            products: $input.val(),
                            nonce: '<?php echo wp_create_nonce('endtrack_save_products_group_nonce'); ?>'
                        },
                        success: function (response) {
                            btn.prop('disabled', false);
                            if (response.success) {
                                $status.text('✓ ' + (response.data.count || 0) + ' guardados').css('color', '#10b981');
                                setTimeout(function () { $status.text(''); }, 3000);
                            } else {
                                $status.text('Error: ' + response.data).css('color', '#ef4444');
                            }
                        },
                        error: function () {
                            btn.prop('disabled', false);
                            $status.text('Error de conexión.').css('color', '#ef4444');
                        }
                    });
                });

                // Per-launch credentials modal
                var $credsModal = $('#endtrack-creds-modal');
                $('.btn-edit-creds').on('click', function () {
                    var btn = $(this);
                    $('#endtrack-creds-launch-name').text(btn.data('launch'));
                    $('#endtrack-creds-host').val(btn.data('host') || '');
                    $('#endtrack-creds-name').val(btn.data('name') || '');
                    $('#endtrack-creds-user').val(btn.data('user') || '');
                    $('#endtrack-creds-pass').val(btn.data('pass') || '');
                    $('#endtrack-creds-stripe').val(btn.data('stripe') || '');
                    $credsModal.data('launch', btn.data('launch')).data('row-btn', btn[0]);
                    $('#endtrack-creds-status').text('').css('color', '');
                    $credsModal.css('display', 'flex');
                });
                $('#endtrack-creds-close, #endtrack-creds-modal').on('click', function (e) {
                    if (e.target === this) $credsModal.hide();
                });
                $('#endtrack-creds-save').on('click', function () {
                    var launch = $credsModal.data('launch');
                    var btn = $(this);
                    btn.prop('disabled', true);
                    $('#endtrack-creds-status').text('Guardando…').css('color', '#64748b');
                    $.ajax({
                        type: 'POST', url: ajaxurl,
                        data: {
                            action: 'endtrack_save_launch_credentials',
                            launch: launch,
                            host: $('#endtrack-creds-host').val(),
                            name: $('#endtrack-creds-name').val(),
                            user: $('#endtrack-creds-user').val(),
                            pass: $('#endtrack-creds-pass').val(),
                            stripe_key: $('#endtrack-creds-stripe').val(),
                            nonce: '<?php echo wp_create_nonce('endtrack_save_launch_credentials_nonce'); ?>'
                        },
                        success: function (r) {
                            btn.prop('disabled', false);
                            if (r.success) {
                                $('#endtrack-creds-status').text('✓ Guardado').css('color', '#10b981');
                                // Update row button data attributes
                                var rowBtn = $credsModal.data('row-btn');
                                if (rowBtn) {
                                    $(rowBtn).data('host', $('#endtrack-creds-host').val())
                                            .data('name', $('#endtrack-creds-name').val())
                                            .data('user', $('#endtrack-creds-user').val())
                                            .data('pass', $('#endtrack-creds-pass').val())
                                            .data('stripe', $('#endtrack-creds-stripe').val());
                                }
                                setTimeout(function () { $credsModal.hide(); }, 600);
                            } else {
                                $('#endtrack-creds-status').text('Error: ' + r.data).css('color', '#ef4444');
                            }
                        },
                        error: function () {
                            btn.prop('disabled', false);
                            $('#endtrack-creds-status').text('Error de conexión').css('color', '#ef4444');
                        }
                    });
                });
                $('#endtrack-creds-clear').on('click', function () {
                    if (!confirm('¿Borrar los overrides de este lanzamiento y volver a usar las credenciales globales?')) return;
                    var launch = $credsModal.data('launch');
                    var btn = $(this);
                    btn.prop('disabled', true);
                    $.ajax({
                        type: 'POST', url: ajaxurl,
                        data: {
                            action: 'endtrack_save_launch_credentials',
                            launch: launch, clear: 1,
                            nonce: '<?php echo wp_create_nonce('endtrack_save_launch_credentials_nonce'); ?>'
                        },
                        success: function (r) {
                            btn.prop('disabled', false);
                            if (r.success) {
                                ['host','name','user','pass','stripe'].forEach(function (k) {
                                    $('#endtrack-creds-' + k).val('');
                                });
                                $('#endtrack-creds-status').text('✓ Restaurado a global').css('color', '#10b981');
                            } else {
                                $('#endtrack-creds-status').text('Error: ' + r.data).css('color', '#ef4444');
                            }
                        }
                    });
                });

                // Comisión general (%) por lanzamiento (INSC: página de captación / fallback afiliados)
                $('.btn-save-commission').on('click', function () {
                    var btn = $(this);
                    var launchName = btn.data('launch');
                    var $input = $('.endtrack-commission-input[data-launch="' + launchName + '"]');
                    var $status = $('.commission-save-status[data-launch="' + launchName + '"]');
                    btn.prop('disabled', true);
                    $status.text('Guardando…').css('color', '#64748b');
                    $.ajax({
                        type: 'POST', url: ajaxurl,
                        data: {
                            action: 'endtrack_save_launch_commission',
                            launch: launchName,
                            commission: $input.val(),
                            nonce: '<?php echo wp_create_nonce('endtrack_save_launch_commission_nonce'); ?>'
                        },
                        success: function (r) {
                            btn.prop('disabled', false);
                            if (r.success) {
                                $status.text(r.data.commission === '' ? '✓ Sin fijar' : '✓ ' + r.data.commission + ' %').css('color', '#10b981');
                                setTimeout(function () { $status.text(''); }, 3000);
                            } else {
                                $status.text('Error: ' + r.data).css('color', '#ef4444');
                            }
                        },
                        error: function () { btn.prop('disabled', false); $status.text('Error de conexión.').css('color', '#ef4444'); }
                    });
                });
            });
        </script>


    <!-- Modal: Credenciales por lanzamiento -->
    <div id="endtrack-creds-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.72); z-index:99999; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:14px; padding:24px 28px; width:min(560px, 92vw); max-height:90vh; overflow-y:auto; position:relative; box-shadow:0 25px 50px rgba(0,0,0,.3);">
            <button type="button" id="endtrack-creds-close" style="position:absolute; top:12px; right:16px; background:none; border:none; font-size:22px; cursor:pointer; color:#64748b; line-height:1;">✕</button>
            <h3 style="margin:0 0 4px; color:#0f172a; font-size:18px;">
                🔑 Credenciales por lanzamiento
            </h3>
            <p style="margin:0 0 18px; color:#64748b; font-size:13px;">
                Lanzamiento: <strong id="endtrack-creds-launch-name" style="color:#4f46e5;"></strong>.
                Si dejas un campo vacío se usa el valor global definido en Integraciones.
            </p>

            <h4 style="margin:10px 0 8px; color:#334155; font-size:13px; text-transform:uppercase; letter-spacing:.5px;">Base de datos PDC</h4>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">Host</label>
                    <input type="text" id="endtrack-creds-host" placeholder="(global)" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-family:monospace; font-size:12px;">
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">Nombre DB</label>
                    <input type="text" id="endtrack-creds-name" placeholder="(global)" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-family:monospace; font-size:12px;">
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">Usuario</label>
                    <input type="text" id="endtrack-creds-user" placeholder="(global)" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-family:monospace; font-size:12px;">
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">Contraseña</label>
                    <input type="password" id="endtrack-creds-pass" placeholder="(global)" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-family:monospace; font-size:12px;">
                </div>
            </div>

            <h4 style="margin:20px 0 8px; color:#334155; font-size:13px; text-transform:uppercase; letter-spacing:.5px;">Stripe</h4>
            <div>
                <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">Secret Key (una o varias — una por línea)</label>
                <textarea id="endtrack-creds-stripe" rows="3" placeholder="sk_live_… o sk_test_… (global si vacío)&#10;Varias cuentas Stripe: pon una clave por línea" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-family:monospace; font-size:12px; resize:vertical;"></textarea>
                <small style="display:block; margin-top:6px; color:#64748b; font-size:11px;">Se usa para el botón "Verificar con Stripe" en el panel de ventas de este lanzamiento. Si pones varias claves, el pago se busca en todas las cuentas hasta encontrarlo.</small>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:24px; padding-top:16px; border-top:1px solid #e2e8f0;">
                <span id="endtrack-creds-status" style="margin-right:auto; align-self:center; font-size:12px;"></span>
                <button type="button" id="endtrack-creds-clear" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; padding:8px 14px; border-radius:6px; cursor:pointer; font-size:12px;">Borrar (usar global)</button>
                <button type="button" id="endtrack-creds-save" style="background:#4f46e5; color:#fff; border:none; padding:8px 18px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600;">Guardar</button>
            </div>
        </div>
    </div>


        <?php
    else: ?>
        <div style="padding: 40px; text-align: center; color: #64748B;">
            <span class="dashicons dashicons-calendar-alt"
                style="font-size: 48px; width: 48px; height: 48px; margin-bottom: 16px; opacity: 0.5;"></span>
            <p style="font-size: 16px; margin: 0;">No hay lanzamientos activos.</p>
            <p style="font-size: 14px; margin-top: 8px;">Crea uno arriba para empezar.</p>
        </div>
        <?php
    endif; ?>
    </div>

    <?php
elseif ($active_tab == 'texts'): ?>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <input type="hidden" name="action" value="endtrack_save_texts">
        <?php wp_nonce_field('endtrack_save_texts_action', 'endtrack_save_texts_nonce'); ?>
        <?php
    $texts = get_option('endtrack_texts', array());
    $defaults = array(
        'welcome_title' => 'Hola {user}, bienvenid@ a la página de admin de afiliados',
        'welcome_subtitle' => 'Tu correo de afiliación de admin es: {email}',
        'panel_welcome_title' => 'Hola {user}, bienvenid@ a tu área de afiliado',
        'panel_welcome_subtitle' => 'Tu email de afiliado: {email}',
        'panel_txt_links_reg' => 'Enlace de Registro',
        'panel_txt_links_venta' => 'Enlace de Venta Directa',
        'panel_txt_leads' => 'Leads Conseguidos',
        'panel_txt_sales' => 'Ventas',
        'panel_txt_commissions' => 'Comisiones (€)',
        'panel_txt_links' => 'Tus Enlaces',
        'wf_taxonomy' => 'wf_page_folders',
        'template_venta' => '',
        'template_gracias_compra' => '',
        'template_registro' => '',
        'template_gracias_reg' => '',
        'logo_admin_panel' => '',
        'logo_user_panel' => '',
    );
    foreach ($defaults as $key => $val) {
        if (!isset($texts[$key]))
            $texts[$key] = $val;
    }

    $templates = get_posts(array(
        'post_type' => 'elementor_library',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ));
?>

        <div class="settings-grid">
            <!-- Section: Admin Panel -->
            <div class="settings-section-card">
                <div class="settings-section-header">
                    <span class="dashicons dashicons-admin-generic" style="color: var(--endtrack-primary);"></span>
                    <h3>Panel de Administración (Tuyo)</h3>
                </div>
                <div class="settings-section-content">
                    <div class="settings-row">
                        <label>Bienvenida (Título)</label>
                        <input type="text" name="texts[welcome_title]"
                            value="<?php echo esc_attr($texts['welcome_title']); ?>" class="endtrack-input">
                    </div>
                    <div class="settings-row">
                        <label>Bienvenida (Subtítulo)</label>
                        <input type="text" name="texts[welcome_subtitle]"
                            value="<?php echo esc_attr($texts['welcome_subtitle']); ?>" class="endtrack-input">
                    </div>
                    <div class="settings-row">
                        <label>Logo Admin (URL)</label>
                        <input type="text" name="texts[logo_admin_panel]"
                            value="<?php echo esc_attr($texts['logo_admin_panel']); ?>" class="endtrack-input"
                            placeholder="https://...">
                    </div>
                </div>
            </div>

            <!-- Section: User Panel -->
            <div class="settings-section-card">
                <div class="settings-section-header">
                    <span class="dashicons dashicons-groups" style="color: var(--endtrack-primary);"></span>
                    <h3>Panel de Usuario (Afiliados)</h3>
                </div>
                <div class="settings-section-content">
                    <div class="settings-row">
                        <label>Bienvenida (Título)</label>
                        <input type="text" name="texts[panel_welcome_title]"
                            value="<?php echo esc_attr($texts['panel_welcome_title']); ?>" class="endtrack-input">
                    </div>
                    <div class="settings-row">
                        <label>Bienvenida (Subtítulo)</label>
                        <input type="text" name="texts[panel_welcome_subtitle]"
                            value="<?php echo esc_attr($texts['panel_welcome_subtitle']); ?>" class="endtrack-input">
                    </div>
                    <div class="settings-row">
                        <label>Logo Usuario (URL)</label>
                        <input type="text" name="texts[logo_user_panel]"
                            value="<?php echo esc_attr($texts['logo_user_panel']); ?>" class="endtrack-input"
                            placeholder="https://...">
                    </div>
                </div>
            </div>

            <!-- Section: Labels -->
            <div class="settings-section-card">
                <div class="settings-section-header">
                    <span class="dashicons dashicons-translation" style="color: var(--endtrack-primary);"></span>
                    <h3>Etiquetas y Textos del Panel</h3>
                </div>
                <div class="settings-section-content">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="settings-row">
                            <label>Label: Registro</label>
                            <input type="text" name="texts[panel_txt_links_reg]"
                                value="<?php echo esc_attr($texts['panel_txt_links_reg']); ?>" class="endtrack-input">
                        </div>
                        <div class="settings-row">
                            <label>Label: Venta</label>
                            <input type="text" name="texts[panel_txt_links_venta]"
                                value="<?php echo esc_attr($texts['panel_txt_links_venta']); ?>" class="endtrack-input">
                        </div>
                        <div class="settings-row">
                            <label>Label: Leads</label>
                            <input type="text" name="texts[panel_txt_leads]"
                                value="<?php echo esc_attr($texts['panel_txt_leads']); ?>" class="endtrack-input">
                        </div>
                        <div class="settings-row">
                            <label>Label: Ventas</label>
                            <input type="text" name="texts[panel_txt_sales]"
                                value="<?php echo esc_attr($texts['panel_txt_sales']); ?>" class="endtrack-input">
                        </div>
                        <div class="settings-row">
                            <label>Label: Comisiones</label>
                            <input type="text" name="texts[panel_txt_commissions]"
                                value="<?php echo esc_attr($texts['panel_txt_commissions']); ?>" class="endtrack-input">
                        </div>
                        <div class="settings-row">
                            <label>Título Enlaces</label>
                            <input type="text" name="texts[panel_txt_links]"
                                value="<?php echo esc_attr($texts['panel_txt_links']); ?>" class="endtrack-input">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Full Width Sections -->
        <div class="settings-section-card full-width-editor" style="margin-top: 24px;">
            <div class="settings-section-header">
                <span class="dashicons dashicons-format-aside" style="color: var(--endtrack-primary);"></span>
                <h3>Contenido Personalizado (Creatividades)</h3>
            </div>
            <div class="settings-section-content">
                <p class="description" style="margin-bottom: 20px;">Define las creatividades que verán los afiliados.
                    Puedes definir unas globales o específicas por lanzamiento.</p>

                <div class="creative-tabs-container"
                    style="border: 1px solid var(--endtrack-border); border-radius: 12px; overflow: hidden;">
                    <?php
    // Lanzamiento especial "Evento": no está en endtrack_launches (sus ventas viven en la
    // BBDD de eventos), pero sus creatividades se editan aquí como las de cualquier otro.
    $launches_creatives = $launches;
    if (function_exists('endtrack_eventos_launch_id') && !in_array(endtrack_eventos_launch_id(), $launches_creatives, true)) {
        $launches_creatives[] = endtrack_eventos_launch_id();
    }
    $all_launches_creatives = array_merge(['global'], $launches_creatives);
    $active_creative_tab = isset($_GET['creative_tab']) ? sanitize_text_field($_GET['creative_tab']) : 'global';
?>
                    <div class="creative-tabs-nav"
                        style="display: flex; background: #f1f5f9; border-bottom: 1px solid var(--endtrack-border);">
                        <?php foreach ($all_launches_creatives as $lc):
        $is_active = ($active_creative_tab == $lc);
        $label = ($lc == 'global') ? '🌍 Global/Fallback' : '🚀 ' . ucfirst($lc);
        $tab_url = add_query_arg('creative_tab', $lc);
?>
                        <a href="<?php echo esc_url($tab_url); ?>#creative-editor"
                            style="padding: 12px 20px; text-decoration: none; font-weight: 600; color: <?php echo $is_active ? 'var(--endtrack-primary)' : 'var(--endtrack-text-muted)'; ?>; border-bottom: 2px solid <?php echo $is_active ? 'var(--endtrack-primary)' : 'transparent'; ?>; background: <?php echo $is_active ? '#fff' : 'transparent'; ?>;">
                            <?php echo esc_html($label); ?>
                        </a>
                        <?php
    endforeach; ?>
                    </div>

                    <div id="creative-editor" style="padding: 20px;">
                        <label style="display: block; margin-bottom: 10px;"><strong>Editando creatividades para:
                                <?php echo ($active_creative_tab == 'global') ? 'Global' : ucfirst($active_creative_tab); ?>
                            </strong></label>
                        <?php
    $editor_id = ($active_creative_tab == 'global') ? 'content_creatividades' : 'content_creatividades_' . $active_creative_tab;
    $content = isset($texts[$editor_id]) ? $texts[$editor_id] : '';
    wp_editor($content, $editor_id, array('textarea_name' => "texts[$editor_id]", 'media_buttons' => true, 'textarea_rows' => 12));
?>
                    </div>
                </div>
            </div>
        </div>

        <div class="settings-section-card full-width-editor" style="margin-top: 24px;">
            <div class="settings-section-header">
                <span class="dashicons dashicons-money-alt" style="color: var(--endtrack-primary);"></span>
                <h3>Métodos de Facturación</h3>
            </div>
            <div class="settings-section-content">
                <p class="description" style="margin-bottom: 20px;">Define las instrucciones de facturación que verán
                    los afiliados.
                    Puedes definir unas globales o específicas por lanzamiento.</p>

                <div class="billing-tabs-container"
                    style="border: 1px solid var(--endtrack-border); border-radius: 12px; overflow: hidden;">
                    <?php
    // Reuse launches array
    // Igual que en creatividades: el lanzamiento "Evento" también tiene su pestaña aquí.
    $launches_billing = $launches;
    if (function_exists('endtrack_eventos_launch_id') && !in_array(endtrack_eventos_launch_id(), $launches_billing, true)) {
        $launches_billing[] = endtrack_eventos_launch_id();
    }
    $all_launches_billing = array_merge(['global'], $launches_billing);
    $active_billing_tab = isset($_GET['billing_tab']) ? sanitize_text_field($_GET['billing_tab']) : 'global';
?>
                    <div class="billing-tabs-nav"
                        style="display: flex; background: #f1f5f9; border-bottom: 1px solid var(--endtrack-border);">
                        <?php foreach ($all_launches_billing as $lb):
        $is_active = ($active_billing_tab == $lb);
        $label = ($lb == 'global') ? '🌍 Global/Fallback' : '🚀 ' . ucfirst($lb);
        $tab_url = add_query_arg('billing_tab', $lb);
?>
                        <a href="<?php echo esc_url($tab_url); ?>#billing-editor"
                            style="padding: 12px 20px; text-decoration: none; font-weight: 600; color: <?php echo $is_active ? 'var(--endtrack-primary)' : 'var(--endtrack-text-muted)'; ?>; border-bottom: 2px solid <?php echo $is_active ? 'var(--endtrack-primary)' : 'transparent'; ?>; background: <?php echo $is_active ? '#fff' : 'transparent'; ?>;">
                            <?php echo esc_html($label); ?>
                        </a>
                        <?php
    endforeach; ?>
                    </div>

                    <div id="billing-editor" style="padding: 20px;">
                        <label style="display: block; margin-bottom: 10px;"><strong>Editando facturación para:
                                <?php echo ($active_billing_tab == 'global') ? 'Global' : ucfirst($active_billing_tab); ?>
                            </strong></label>
                        <?php
    $billing_editor_id = ($active_billing_tab == 'global') ? 'content_billing_methods' : 'content_billing_methods_' . $active_billing_tab;
    $content_billing = isset($texts[$billing_editor_id]) ? $texts[$billing_editor_id] : '';
    wp_editor($content_billing, $billing_editor_id, array('textarea_name' => "texts[$billing_editor_id]", 'media_buttons' => true, 'textarea_rows' => 12));
?>
                    </div>
                </div>
            </div>
        </div>

        <div class="settings-section-card full-width-editor" style="margin-top: 24px;">
            <div class="settings-section-header">
                <span class="dashicons dashicons-editor-help" style="color: var(--endtrack-primary);"></span>
                <h3>Pestaña "Asignación / Ayuda" (Común)</h3>
            </div>
            <div class="settings-section-content">
                <?php wp_editor($texts['content_asignacion'], 'content_asignacion', array('textarea_name' => 'texts[content_asignacion]', 'media_buttons' => true, 'textarea_rows' => 10)); ?>
            </div>
        </div>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--endtrack-border);">
            <button type="submit" class="btn-primary" style="padding: 15px 40px; font-size: 16px;">Guardar Toda la
                Configuración</button>
        </div>
    </form>

    <?php
elseif ($active_tab == 'integrations'): ?>
    <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
        <input type="hidden" name="action" value="endtrack_save_integrations">
        <?php wp_nonce_field('endtrack_save_integrations_action', 'endtrack_save_integrations_nonce'); ?>

        <div class="endtrack-card">
            <h2><span class="dashicons dashicons-admin-plugins" style="margin-right: 8px;"></span>Integraciones Externas
            </h2>

            <!-- Claude (Anthropic) Integration -->
            <div
                style="background: #fef7f0; padding: 20px; border-radius: 8px; margin-bottom: 30px; border-left: 4px solid #d97706;">
                <h3 style="margin-top: 0; color: #92400e;">
                    <span class="dashicons dashicons-superhero" style="margin-right: 8px;"></span>
                    Claude AI (Anthropic) — Edición Inteligente de Páginas
                </h3>
                <p style="color: #64748b; margin-bottom: 20px;">
                    Conecta con Claude para generar copy, editar diseño y modificar páginas de Elementor de forma
                    inteligente.
                    <a href="https://console.anthropic.com/settings/keys" target="_blank" style="color: #d97706;">Obtén
                        tu API
                        Key aquí →</a>
                </p>

                <div class="form-group">
                    <label for="anthropic_key">
                        <strong>Anthropic API Key</strong>
                        <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="password" id="anthropic_key" name="anthropic_key"
                        value="<?php echo esc_attr($texts['anthropic_key'] ?? ''); ?>" placeholder="sk-ant-..."
                        class="endtrack-input" style="font-family: monospace;">
                    <small style="color: #64748b;">
                        Tu API key se almacena de forma segura. Formato: sk-ant-...
                    </small>
                </div>

                <div class="form-group" style="margin-top: 20px;">
                    <label for="claude_model"><strong>Modelo de Claude</strong></label>
                    <select id="claude_model" name="claude_model" class="endtrack-input">
                        <?php
    $current_model = $texts['claude_model'] ?? 'claude-haiku-4-5-20251001';
    $models = array(
        'claude-haiku-4-5-20251001' => 'Haiku 4.5 — Más económico (~4x más barato)',
        'claude-sonnet-4-5-20250514' => 'Sonnet 4.5 — Equilibrado',
        'claude-sonnet-4-20250514' => 'Sonnet 4 — Más potente (más caro)',
    );
    foreach ($models as $value => $label) {
        $selected = selected($current_model, $value, false);
        echo "<option value=\"{$value}\" {$selected}>{$label}</option>";
    }
?>
                    </select>
                    <small style="color: #64748b;">
                        Haiku 4.5 es suficiente para modificar páginas Elementor y cuesta ~4x menos que Sonnet 4.
                    </small>
                </div>
            </div>

            <!-- Grafana Integration Removed -->

        </div>

        <!-- ===== BBDD externa de VENTAS (pdc_pagos) ===== -->
        <div class="endtrack-card" style="margin-top: 25px;">
            <h2 style="margin-top:0;"><span class="dashicons dashicons-database" style="margin-right:8px;"></span>Base de datos de ventas (pdc_pagos)</h2>
            <p style="color:#64748b; margin-top:0;">Servidor MySQL externo desde el que se leen las ventas reales (<code>pdc_pagos</code>). Los <strong>id_producto</strong> a contar se configuran en cada lanzamiento (pestaña Lanzamientos).</p>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label for="ext_db_host"><strong>Host</strong></label>
                    <input type="text" id="ext_db_host" name="ext_db_host" class="endtrack-input"
                        value="<?php echo esc_attr($texts['ext_db_host'] ?? ''); ?>" placeholder="ej: 123.45.67.89 o localhost">
                </div>
                <div class="form-group">
                    <label for="ext_db_name"><strong>Nombre de la BBDD</strong></label>
                    <input type="text" id="ext_db_name" name="ext_db_name" class="endtrack-input"
                        value="<?php echo esc_attr($texts['ext_db_name'] ?? ''); ?>" placeholder="ej: pablogil_db">
                </div>
                <div class="form-group">
                    <label for="ext_db_user"><strong>Usuario</strong></label>
                    <input type="text" id="ext_db_user" name="ext_db_user" class="endtrack-input"
                        value="<?php echo esc_attr($texts['ext_db_user'] ?? ''); ?>" placeholder="usuario MySQL">
                </div>
                <div class="form-group">
                    <label for="ext_db_pass"><strong>Contraseña</strong></label>
                    <input type="password" id="ext_db_pass" name="ext_db_pass" class="endtrack-input"
                        value="" placeholder="<?php echo !empty($texts['ext_db_pass']) ? '•••••• (sin cambios)' : 'contraseña MySQL'; ?>">
                    <small style="color:#64748b;">Déjalo vacío para conservar la contraseña actual.</small>
                </div>
            </div>

            <!-- ===== Stripe global ===== -->
            <?php $_sk_saved = !empty($texts['stripe_secret_key']); $_sk_n = $_sk_saved ? count(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $texts['stripe_secret_key'])))) : 0; ?>
            <h3 style="margin:22px 0 6px; font-size:14px; color:#334155;"><span class="dashicons dashicons-lock" style="margin-right:6px;"></span>Stripe (global)</h3>
            <p style="color:#64748b; margin-top:0; font-size:13px;">Secret Key(s) que usa el botón <strong>"Verificar con Stripe"</strong> de <code>/ventas-completas/</code> cuando el lanzamiento no tiene una clave propia (se configura por lanzamiento en la pestaña <em>Lanzamientos → 🔑 Editar</em>). Varias cuentas: una clave por línea.</p>
            <div class="form-group">
                <label for="stripe_secret_key"><strong>Secret Key(s)</strong>
                    <?php if ($_sk_saved): ?><span style="color:#10b981; font-weight:600; margin-left:8px;">● <?php echo (int) $_sk_n; ?> clave<?php echo $_sk_n == 1 ? '' : 's'; ?> guardada<?php echo $_sk_n == 1 ? '' : 's'; ?></span><?php else: ?><span style="color:#94a3b8; margin-left:8px;">○ sin configurar</span><?php endif; ?>
                </label>
                <textarea id="stripe_secret_key" name="stripe_secret_key" rows="2" class="endtrack-input" style="font-family:monospace; font-size:12px;"
                    placeholder="<?php echo $_sk_saved ? '•••••• (sin cambios)' : 'sk_live_… (una por línea si hay varias cuentas)'; ?>"></textarea>
                <small style="color:#64748b;">Déjalo vacío para conservar la(s) clave(s) actual(es).
                    <?php if ($_sk_saved): ?> <label style="margin-left:10px; color:#dc2626;"><input type="checkbox" name="stripe_secret_key_clear" value="1" style="margin:0 4px 0 0;">Borrar la clave global</label><?php endif; ?>
                </small>
            </div>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <button type="submit" class="btn-primary" style="padding: 15px 40px; font-size: 16px;">
                Guardar Integraciones
            </button>
        </div>
    </form>

    <?php
elseif ($active_tab == 'help'): ?>
    <div class="endtrack-card">
        <h2><span class="dashicons dashicons-book-alt" style="margin-right: 8px;"></span>Guía Maestra y Novedades del
            Sistema</h2>

        <div class="instruction-step" style="border-left: 4px solid #10b981; background: #ecfdf5;">
            <div class="step-number" style="background: #10b981;">N</div>
            <div>
                <strong>🚀 Creación Automática de Lanzamientos</strong>
                <p>Ahora crear un lanzamiento es tan fácil como escribir el nombre y pulsar un botón. El sistema se
                    encarga de todo:</p>
                <ul style="list-style-type: '🍕'; padding-left: 20px;">
                    <li style="padding-left: 10px; margin-bottom: 5px;"><strong>Crea automáticamente todas las
                            páginas:</strong> Registro, Gracias Registro, Venta y Gracias Compra.</li>
                    <li style="padding-left: 10px; margin-bottom: 5px;"><strong>Clona tus plantillas de
                            Elementor:</strong> Copia el diseño, los estilos y la configuración responsive de las
                        plantillas base que hayas definido en "Textos y Configuración".</li>
                    <li style="padding-left: 10px; margin-bottom: 5px;"><strong>Asigna categorías e IDs:</strong>
                        Etiqueta cada página con su función (<code>venta</code>, <code>registro</code>, etc.) y la
                        categoría del lanzamiento (ej. <code>marzo2025</code>).</li>
                    <li style="padding-left: 10px; margin-bottom: 5px;"><strong>Crea carpetas en Wicked
                            Folders:</strong> Organiza las páginas en una carpeta dedicada para que no se pierdan.</li>
                </ul>
            </div>
        </div>

        <div class="instruction-step">
            <div class="step-number">1</div>
            <div>
                <strong>Plantillas de Elementor</strong>
                <p>Para que la magia funcione, debes tener configuradas las <strong>IDs de tus plantillas base</strong>
                    en la pestaña "Textos y Configuración".</p>
                <p>El sistema usará estas plantillas como molde. Si actualizas la plantilla base en Elementor, los
                    futuros lanzamientos heredarán esos cambios.</p>
            </div>
        </div>

        <div class="instruction-step">
            <div class="step-number">2</div>
            <div>
                <strong>Acceso Inmediato para Afiliados</strong>
                <p>Cuando un afiliado se registra a través de cualquiera de tus formularios de captación,
                    <strong>automáticamente obtiene acceso al Panel de Afiliado</strong>.
                </p>
                <p>No hace falta aprobación manual. El sistema detecta el registro, asigna el rol y le muestra su panel
                    personalizado al instante.</p>
            </div>
        </div>

        <div>
            <div class="step-number">3</div>
            <strong>Estadísticas Nativas</strong>
            <p>Accede al botón "Ver Panel" en cada lanzamiento para ver las métricas en tiempo real sin salir de tu web.
            </p>
        </div>

        <div class="instruction-step">
            <div class="step-number">4</div>
            <div>
                <strong>Configuración Técnica (IDs y Shortcodes)</strong>
                <p>Recuerda las reglas de oro para que el rastreo no falle:</p>
                <ul>
                    <li>Botón de registro en Elementor: ID <code>add_suscrito</code>.</li>
                    <li>Shortcode para mostrar el panel de afiliado en el frontend:
                        <code>[endtrack_affiliate_panel]</code>.
                    </li>
                    <li>Shortcode para el panel de administración de afiliados: <code>[endtrack_admin_dashboard]</code>
                        (solo visible para admins).</li>
                </ul>
            </div>
        </div>
    </div>
    <?php
endif; ?>
</div>