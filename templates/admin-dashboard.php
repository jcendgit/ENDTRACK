<style>
    :root {
        --endtrack-primary: #4F46E5;
        --endtrack-secondary: #0EA5E9;
        --endtrack-bg: #F8FAFC;
        --endtrack-card: #FFFFFF;
        --endtrack-text: #1E293B;
        --endtrack-text-muted: #64748B;
        --endtrack-border: #E2E8F0;
        --endtrack-accent: #818CF8;
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
        border-radius: 12px;
        padding: 30px;
        border: 1px solid var(--endtrack-border);
        box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
    }

    .endtrack-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        border-bottom: 2px solid var(--endtrack-border);
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
        border-radius: 8px;
        font-weight: 600;
        color: var(--endtrack-text-muted);
        transition: all 0.2s;
        background: #ECEFF1;
    }

    .endtrack-nav a.active {
        background: var(--endtrack-primary);
        color: white;
        box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.4);
    }

    .endtrack-card {
        background: var(--endtrack-card);
        border-radius: 12px;
        padding: 24px;
        border: 1px solid var(--endtrack-border);
        box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
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
        background: #F1F5F9;
        font-weight: 600;
        color: var(--endtrack-text-muted);
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
    }

    .endtrack-table td {
        padding: 16px;
        border-bottom: 1px solid var(--endtrack-border);
    }

    .endtrack-input {
        width: 100%;
        padding: 8px 12px;
        border-radius: 6px;
        border: 1px solid var(--endtrack-border);
        transition: border-color 0.2s;
    }

    .endtrack-input:focus {
        outline: none;
        border-color: var(--endtrack-primary);
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    }

    .btn-primary {
        background: var(--endtrack-primary);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: opacity 0.2s;
    }

    .btn-primary:hover {
        opacity: 0.9;
    }

    .btn-outline {
        border: 2px solid var(--endtrack-primary);
        color: var(--endtrack-primary);
        background: transparent;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
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
        background: white;
        border: 1px solid var(--endtrack-border);
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        display: flex;
        flex-direction: column;
    }

    .settings-section-header {
        background: #f8fafc;
        padding: 16px 20px;
        border-bottom: 1px solid var(--endtrack-border);
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
        <?php else: ?>
            <h2 style="margin:0; color:#fff;">ENDTrack</h2>
        <?php endif; ?>
        <div style="display: flex; gap: 15px; align-items: center;">
            <a href="<?php echo site_url('/endtrack-panel-admin-afiliado/'); ?>" target="_blank" class="btn-outline">
                <span class="dashicons dashicons-chart-bar" style="margin-top: 4px;"></span>
                Panel de Estadísticas Pro
            </a>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin: 0;" id="update-grafanas-form">
                <input type="hidden" name="action" value="endtrack_update_all_grafanas">
                <input type="hidden" name="grafana_password" id="grafana_password" value="">
                <?php wp_nonce_field('endtrack_update_all_grafanas_action', 'endtrack_update_all_grafanas_nonce'); ?>
                <button type="submit" class="btn-primary" style="background: #059669;"
                    onclick="var p = prompt('Introduce la contraseña para actualizar:'); if(!p) return false; document.getElementById('grafana_password').value = p; return true;">
                    <span class="dashicons dashicons-update" style="margin-top: 4px; vertical-align: middle;"></span>
                    Actualizar Grafanas
                </button>
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
        <a href="?page=endtrack&tab=launches"
            class="<?php echo $active_tab == 'launches' ? 'active' : ''; ?>">Lanzamientos</a>
        <a href="?page=endtrack&tab=texts" class="<?php echo $active_tab == 'texts' ? 'active' : ''; ?>">Textos y
            Configuración</a>
        <a href="?page=endtrack&tab=integrations"
            class="<?php echo $active_tab == 'integrations' ? 'active' : ''; ?>">Integraciones</a>
        <a href="?page=endtrack&tab=help" class="<?php echo $active_tab == 'help' ? 'active' : ''; ?>">Instrucciones y
            Ayuda</a>
    </nav>

    <?php if (isset($_GET['message'])): ?>
        <div style="margin: 20px 0; padding: 15px; border-radius: 12px; border-left: 6px solid; font-weight: 500; font-size: 15px; 
            <?php
            $m = $_GET['message'];
            if (strpos($m, 'error') !== false || strpos($m, 'fail') !== false) {
                echo 'background: #fef2f2; border-color: #ef4444; color: #991b1b; box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.1);';
            } else {
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
                default:
                    echo 'ℹ️ ' . esc_html($_GET['message']);
                    break;
            }
            ?>
        </div>
    <?php endif; ?>

    <?php if ($active_tab == 'launches'): ?>
        <div class="endtrack-card" style="background: #f1f5f9; border: 2px dashed #cbd5e1;">
            <h2 style="justify-content: center; font-size: 1.5rem; margin-bottom: 30px;">
                <span class="dashicons dashicons-warning"
                    style="color: #dc2626; font-size: 24px; width: 24px; height: 24px;"></span>
                CENTRO DE ACTIVACIÓN DE LANZAMIENTOS
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
                    style="background: #FFFBEB; border: 1px solid #FEF3C7; color: #92400E; padding: 10px 15px; border-radius: 8px; font-size: 13px; max-width: 500px;">
                    <span class="dashicons dashicons-info"
                        style="font-size: 18px; margin-right: 5px; vertical-align: text-bottom;"></span>
                    Los checkbox que se marquen en las páginas serán los que aparezcan en el panel de afiliados.
                </div>
            </div>
            <?php
            if (!empty($launches)): ?>
                <table class="endtrack-table">
                    <thead>
                        <tr>
                            <th>Lanzamiento</th>
                            <th>Visible Afiliados</th>
                            <th>Tipo</th>
                            <th>Páginas Registro</th>
                            <th>Gracias Registro</th>
                            <th>Páginas Venta</th>
                            <th>Páginas Gracias</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($launches as $launch):
                            $type_id = isset($launch_configs[$launch]['type']) ? $launch_configs[$launch]['type'] : 1;

                            if ($type_id == 3) {
                                $type_label = 'Sin crear páginas';
                                $type_class = 'badge-direct'; // Or create a new style
                            } elseif ($type_id == 2) {
                                $type_label = 'Con Registro';
                                $type_class = 'badge-registration';
                            } else {
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
                                    return '<span class="description" style="font-style:italic;">Sin páginas</span>';

                                $ai_type_map = array(
                                    'registro' => 'registro',
                                    'gracias-registro' => 'gracias_registro',
                                    'venta' => 'ventas',
                                    'gracias' => 'gracias'
                                );
                                $ai_type = isset($ai_type_map[$type_slug]) ? $ai_type_map[$type_slug] : 'registro';

                                $html = '<div style="display:flex; flex-direction:column; gap:8px;">';
                                foreach ($pages as $p) {
                                    $edit_url = admin_url('post.php?post=' . $p->ID . '&action=elementor');
                                    $view_url = get_permalink($p->ID);
                                    $is_affiliate = get_post_meta($p->ID, '_endtrack_is_affiliate_link', true);
                                    $checked = $is_affiliate ? 'checked' : '';

                                    $html .= '<div style="background:#f8fafc; padding:8px; border-radius:6px; border:1px solid #e2e8f0; position:relative;">';
                                    $html .= '<div style="position:absolute; top:8px; right:8px; display:flex; gap:4px; align-items:center;">';
                                    $html .= '<button class="btn-ai-page" data-post-id="' . $p->ID . '" data-type="' . $ai_type . '" data-title="' . esc_attr($p->post_title) . '" title="Regenerar Copy IA para esta página"><span class="dashicons dashicons-reddit"></span></button>';
                                    $html .= '<input type="checkbox" class="endtrack-affiliate-toggle" data-post-id="' . $p->ID . '" ' . $checked . ' title="Mostrar en panel de afiliados">';
                                    $html .= '</div>';
                                    $html .= '<div style="font-weight:600; font-size:13px; margin-bottom:4px; padding-right: 45px;">' . esc_html($p->post_title) . '</div>';
                                    $html .= '<div style="display:flex; gap:6px;">';
                                    $html .= '<a href="' . esc_url($view_url) . '" target="_blank" style="font-size:11px; text-decoration:none; color:#4F46E5; background:#e0e7ff; padding:2px 6px; border-radius:4px;">Ver</a>';
                                    $html .= '<a href="' . esc_url($edit_url) . '" target="_blank" style="font-size:11px; text-decoration:none; color:#0f172a; background:#e2e8f0; padding:2px 6px; border-radius:4px;">Elementor</a>';
                                    $html .= '</div>';
                                    $html .= '</div>';
                                }
                                $html .= '</div>';
                                return $html;
                            };
                            ?>
                            <tr>
                                <td style="vertical-align:top;">
                                    <strong><?php echo esc_html($launch); ?></strong>
                                    <?php if (!$launch_cat): ?>
                                        <div style="color:#ef4444; font-size:11px; margin-top:4px;">⚠️ Cat. no encontrada:
                                            <?php echo esc_html($launch_cat_slug); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="vertical-align:top; text-align: center;">
                                    <?php
                                    $visibility_map = get_option('endtrack_launch_visibility', array());
                                    $is_visible = isset($visibility_map[$launch]) ? $visibility_map[$launch] : false;
                                    ?>
                                    <label class="switch" title="Mostrar/Ocultar a Afiliados">
                                        <input type="checkbox" class="endtrack-visibility-toggle"
                                            data-launch="<?php echo esc_attr($launch); ?>" <?php checked($is_visible, true); ?>>
                                        <span class="slider round"></span>
                                    </label>
                                </td>
                                <td style="vertical-align:top;">
                                    <span class="badge <?php echo $type_class; ?>">
                                        <?php echo $type_label; ?>
                                    </span>
                                </td>
                                <td style="vertical-align:top;"><?php echo $render_page_list($registros, 'registro'); ?></td>
                                <td style="vertical-align:top;"><?php echo $render_page_list($gracias_reg, 'gracias-registro'); ?>
                                </td>
                                <td style="vertical-align:top;"><?php echo $render_page_list($ventas, 'venta'); ?></td>
                                <td style="vertical-align:top;"><?php echo $render_page_list($gracias, 'gracias'); ?></td>
                                <td style="vertical-align:top; text-align: center;">
                                    <?php
                                    $dashboards = get_option('endtrack_launch_dashboards', array());
                                    $dashboard_url = isset($dashboards[$launch]) ? $dashboards[$launch] : false;
                                    ?>

                                    <div style="margin-bottom: 10px;">
                                        <button type="button" class="button button-secondary button-small btn-regenerate-launch-ai"
                                            data-launch="<?php echo esc_attr($launch); ?>"
                                            style="color: #6366f1; border-color: #6366f1; width: 100%; display: flex; align-items: center; justify-content: center; gap: 5px;"
                                            title="Regenerar Copy de todas las páginas con IA">
                                            <span class="dashicons dashicons-rest-api"></span> IA Copy
                                        </button>
                                    </div>

                                    <div style="margin-bottom: 10px;">
                                        <?php if ($dashboard_url): ?>
                                            <?php
                                            // Construct internal URL: /launch-slug-endtrack/
                                            // We use sanitize_title to ensure the URL part is clean.
                                            // The template will try to match this back to the launch name.
                                            $internal_url = site_url(sanitize_title($launch) . '-endtrack');
                                            ?>
                                            <a href="<?php echo esc_url($internal_url); ?>" target="_blank"
                                                class="button button-secondary button-small"
                                                style="color: #4f46e5; border-color: #4f46e5;" title="Ver Dashboard Grafana">
                                                <span class="dashicons dashicons-chart-area" style="line-height: 1.3;"></span> Ver
                                            </a>
                                        <?php else: ?>
                                            <a href="<?php echo admin_url('admin-post.php?action=endtrack_create_grafana&launch=' . urlencode($launch)); ?>"
                                                class="button button-secondary button-small"
                                                style="color: #059669; border-color: #059669;" title="Generar Dashboard en Grafana">
                                                <span class="dashicons dashicons-plus-alt2" style="line-height: 1.3;"></span> Generar
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=endtrack_delete_launch&launch=' . urlencode($launch)), 'endtrack_delete_launch_action', 'endtrack_delete_launch_nonce'); ?>"
                                        class="btn-delete-launch" style="color: #f56565; text-decoration: none; font-size: 20px;"
                                        title="Borrar Lanzamiento">
                                        <span class="dashicons dashicons-trash"></span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description" style="margin-top:20px;">
                    <strong>Nota:</strong> Las páginas aparecen aquí automáticamente si tienen asignadas DOS categorías: la del
                    <strong>Lanzamiento</strong> y el <strong>Tipo</strong> (registro, venta, gracias).
                </p>
                <script>
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

                        // Launch-wide AI Regeneration
                        $('.btn-regenerate-launch-ai').on('click', async function (e) {
                            var self = $(this);
                            var launchName = self.data('launch');
                            var userPrompt = await openAIPromptModal("REGENERAR LANZAMIENTO: " + launchName);

                            if (userPrompt === null) return;

                            loader.css('display', 'flex');

                            $.ajax({
                                type: 'POST',
                                url: ajaxurl,
                                data: {
                                    action: 'endtrack_regenerate_launch_copy',
                                    launch: launchName,
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
                    });
                </script>
            <?php else: ?>
                <div style="padding: 40px; text-align: center; color: #64748B;">
                    <span class="dashicons dashicons-calendar-alt"
                        style="font-size: 48px; width: 48px; height: 48px; margin-bottom: 16px; opacity: 0.5;"></span>
                    <p style="font-size: 16px; margin: 0;">No hay lanzamientos activos.</p>
                    <p style="font-size: 14px; margin-top: 8px;">Crea uno arriba para empezar.</p>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($active_tab == 'texts'): ?>
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

                <!-- Section: Page Automation -->
                <div class="settings-section-card">
                    <div class="settings-section-header">
                        <span class="dashicons dashicons-layout" style="color: var(--endtrack-primary);"></span>
                        <h3>Configuración de Páginas (Automática)</h3>
                    </div>
                    <div class="settings-section-content">
                        <div class="settings-row">
                            <label>Taxonomía Wicked Folders</label>
                            <input type="text" name="texts[wf_taxonomy]"
                                value="<?php echo esc_attr($texts['wf_taxonomy']); ?>" class="endtrack-input">
                            <p class="description">Por defecto: <code>wf_page_folders</code></p>
                        </div>
                        <div class="settings-row">
                            <label>Plantilla: Ventas</label>
                            <select name="texts[template_venta]" class="endtrack-input">
                                <option value="">Selecciona una plantilla...</option>
                                <?php foreach ($templates as $tmpl): ?>
                                    <option value="<?php echo $tmpl->ID; ?>" <?php selected($texts['template_venta'], $tmpl->ID); ?>><?php echo esc_html($tmpl->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="settings-row">
                            <label>Plantilla: Registro</label>
                            <select name="texts[template_registro]" class="endtrack-input">
                                <option value="">Selecciona una plantilla...</option>
                                <?php foreach ($templates as $tmpl): ?>
                                    <option value="<?php echo $tmpl->ID; ?>" <?php selected($texts['template_registro'], $tmpl->ID); ?>><?php echo esc_html($tmpl->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="settings-row">
                                <label>Gracias (Compra)</label>
                                <select name="texts[template_gracias_compra]" class="endtrack-input">
                                    <option value="">Selecciona...</option>
                                    <?php foreach ($templates as $tmpl): ?>
                                        <option value="<?php echo $tmpl->ID; ?>" <?php selected($texts['template_gracias_compra'], $tmpl->ID); ?>>
                                            <?php echo esc_html($tmpl->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="settings-row">
                                <label>Gracias (Reg)</label>
                                <select name="texts[template_gracias_reg]" class="endtrack-input">
                                    <option value="">Selecciona...</option>
                                    <?php foreach ($templates as $tmpl): ?>
                                        <option value="<?php echo $tmpl->ID; ?>" <?php selected($texts['template_gracias_reg'], $tmpl->ID); ?>><?php echo esc_html($tmpl->post_title); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
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
                        $all_launches_creatives = array_merge(['global'], $launches);
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
                            <?php endforeach; ?>
                        </div>

                        <div id="creative-editor" style="padding: 20px;">
                            <label style="display: block; margin-bottom: 10px;"><strong>Editando creatividades para:
                                    <?php echo ($active_creative_tab == 'global') ? 'Global' : ucfirst($active_creative_tab); ?></strong></label>
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
                        $all_launches_billing = array_merge(['global'], $launches);
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
                            <?php endforeach; ?>
                        </div>

                        <div id="billing-editor" style="padding: 20px;">
                            <label style="display: block; margin-bottom: 10px;"><strong>Editando facturación para:
                                    <?php echo ($active_billing_tab == 'global') ? 'Global' : ucfirst($active_billing_tab); ?></strong></label>
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

    <?php elseif ($active_tab == 'integrations'): ?>
        <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="endtrack_save_integrations">
            <?php wp_nonce_field('endtrack_save_integrations_action', 'endtrack_save_integrations_nonce'); ?>

            <div class="endtrack-card">
                <h2><span class="dashicons dashicons-admin-plugins" style="margin-right: 8px;"></span>Integraciones Externas
                </h2>

                <!-- OpenAI Integration -->
                <div
                    style="background: #f0f9ff; padding: 20px; border-radius: 8px; margin-bottom: 30px; border-left: 4px solid #0ea5e9;">
                    <h3 style="margin-top: 0; color: #0369a1;">
                        <span class="dashicons dashicons-admin-customizer" style="margin-right: 8px;"></span>
                        OpenAI - Generación de Copy con IA
                    </h3>
                    <p style="color: #64748b; margin-bottom: 20px;">
                        Conecta con OpenAI GPT-4 para generar automáticamente el copy de tus landing pages.
                        <a href="https://platform.openai.com/api-keys" target="_blank" style="color: #0ea5e9;">Obtén tu API
                            Key aquí →</a>
                    </p>

                    <div class="form-group">
                        <label for="openai_key">
                            <strong>OpenAI API Key</strong>
                            <span style="color: #dc2626;">*</span>
                        </label>
                        <input type="password" id="openai_key" name="openai_key"
                            value="<?php echo esc_attr($texts['openai_key'] ?? ''); ?>" placeholder="sk-proj-..."
                            class="endtrack-input" style="font-family: monospace;">
                        <small style="color: #64748b;">
                            Tu API key se almacena de forma segura. Formato: sk-proj-...
                        </small>
                    </div>
                </div>

                <!-- Grafana Integration -->
                <div style="background: #fef3c7; padding: 20px; border-radius: 8px; border-left: 4px solid #f59e0b;">
                    <h3 style="margin-top: 0; color: #92400e;">
                        <span class="dashicons dashicons-chart-area" style="margin-right: 8px;"></span>
                        Grafana - Dashboards de Analítica
                    </h3>
                    <p style="color: #64748b; margin-bottom: 20px;">
                        Configura la conexión con Grafana para generar dashboards automáticos de cada lanzamiento.
                    </p>

                    <div class="form-group">
                        <label for="grafana_url"><strong>URL de Grafana</strong></label>
                        <input type="url" id="grafana_url" name="grafana_url"
                            value="<?php echo esc_attr($texts['grafana_url'] ?? ''); ?>"
                            placeholder="https://grafana.example.com" class="endtrack-input">
                        <small style="color: #64748b;">URL completa de tu instancia de Grafana (sin / al final)</small>
                    </div>

                    <div class="form-group">
                        <label for="grafana_token"><strong>API Token de Grafana</strong></label>
                        <input type="password" id="grafana_token" name="grafana_token"
                            value="<?php echo esc_attr($texts['grafana_token'] ?? ''); ?>" placeholder="glsa_..."
                            class="endtrack-input" style="font-family: monospace;">
                        <small style="color: #64748b;">Token con permisos de escritura en dashboards</small>
                    </div>

                    <div class="form-group">
                        <label for="grafana_datasource_uid"><strong>DataSource UID</strong></label>
                        <input type="text" id="grafana_datasource_uid" name="grafana_datasource_uid"
                            value="<?php echo esc_attr($texts['grafana_datasource_uid'] ?? ''); ?>" placeholder="P..."
                            class="endtrack-input" style="font-family: monospace;">
                        <small style="color: #64748b;">UID del datasource MySQL en Grafana</small>
                    </div>
                </div>
            </div>

            <div style="text-align: center; margin-top: 30px;">
                <button type="submit" class="btn-primary" style="padding: 15px 40px; font-size: 16px;">
                    Guardar Integraciones
                </button>
            </div>
        </form>

    <?php elseif ($active_tab == 'help'): ?>
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

            <div class="instruction-step">
                <div class="step-number">3</div>
                <div>
                    <strong>Botón "Actualizar Grafanas"</strong>
                    <p>Situado en la esquina superior derecha, este botón es tu "botón de pánico" (en el buen sentido) para
                        los dashboards.</p>
                    <p>Si alguna vez sientes que los datos en los iframes de Grafana no se ven bien o has cambiado URLs,
                        púlsalo. forzará una actualización de los enlaces de las visualizaciones en todos los lanzamientos
                        activos.</p>
                </div>
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
    <?php endif; ?>
</div>