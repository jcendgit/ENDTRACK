<?php

class ENDTrack_AI
{
    private static function log($message)
    {
        $log_file = dirname(dirname(__FILE__)) . '/endtrack_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    }

    /**
     * Generate AI copy for a specific page
     * 
     * @param int $post_id WordPress post ID
     * @param string $page_type Type of page: 'ventas', 'registro', 'gracias', 'gracias_registro', 'general'
     * @param string $user_prompt User's instructions for the AI
     * @return array|WP_Error Success message or error
     */
    public static function generate_copy_for_page($post_id, $page_type, $user_prompt)
    {
        self::log("--- START AI GENERATION (Claude) ---");
        self::log("Post ID: $post_id, Page Type: $page_type");
        self::log("User Prompt: $user_prompt");

        // Get Anthropic API key and model
        $texts = get_option('endtrack_texts', array());
        $api_key = isset($texts['anthropic_key']) ? $texts['anthropic_key'] : '';

        if (empty($api_key)) {
            self::log("Error: Missing Anthropic API Key");
            return new WP_Error('missing_api_key', 'No se ha configurado la API Key de Claude (Anthropic).');
        }

        // Get FULL Elementor JSON for smart editing
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        $full_json = !empty($elementor_data) ? json_decode($elementor_data, true) : array();

        // Extract readable text map for context
        $current_texts = self::extract_elementor_text($post_id);
        $page_settings = get_post_meta($post_id, '_elementor_page_settings', true);

        // Build a concise widget map
        $widget_map = array();
        foreach ($current_texts as $item) {
            $widget_map[] = array(
                'id' => $item['id'],
                'type' => $item['type'],
                'parent_block' => $item['bloque_padre'],
                'label' => $item['nombre_elemento'],
                'current_content' => mb_substr($item['content'], 0, 200)
            );
        }

        $context_json = json_encode(array(
            'widget_map' => $widget_map,
            'page_settings' => is_array($page_settings) ? $page_settings : array(),
            'total_sections' => count($full_json),
            'full_elementor_json' => $full_json
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $system_prompt = self::get_system_prompt($page_type);
        $user_message = "INSTRUCCIÓN DEL USUARIO:\n" . $user_prompt . "\n\n---\n\nCONTEXTO COMPLETO DE LA PÁGINA (Elementor JSON + widget map):\n" . $context_json;

        // Call Claude API
        $claude_model = !empty($texts['claude_model']) ? $texts['claude_model'] : 'claude-haiku-4-5-20251001';
        $ai_response = self::call_claude_api($api_key, $system_prompt, $user_message, $claude_model);

        if (is_wp_error($ai_response)) {
            self::log("API Error: " . $ai_response->get_error_message());
            return $ai_response;
        }

        // Update Elementor content with AI-generated data
        $update_result = self::update_elementor_text($post_id, $ai_response);

        if (is_wp_error($update_result)) {
            self::log("Update Error: " . $update_result->get_error_message());
            return $update_result;
        }

        self::log("--- SUCCESS ---");
        return array('success' => true, 'message' => 'Cambios aplicados correctamente por Claude.');
    }

    public static function extract_elementor_text($post_id)
    {
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (empty($elementor_data))
            return array();
        $data = json_decode($elementor_data, true);
        if (!is_array($data))
            return array();
        $texts = array();
        self::recursive_extract_text($data, $texts);
        return $texts;
    }

    public static function update_elementor_text($post_id, $new_content)
    {
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        $data = !empty($elementor_data) ? json_decode($elementor_data, true) : array();

        if (isset($new_content['full_elementor_json']) && is_array($new_content['full_elementor_json'])) {
            $updated_data = $new_content['full_elementor_json'];
            self::log("Full JSON replacement mode.");
            if (!self::validate_elementor_data($updated_data)) {
                return new WP_Error('invalid_structure', 'La estructura JSON completa generada por Claude es inválida.');
            }
            update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($updated_data)));
        }
        else {
            $update_count = 0;
            $updated_data = self::recursive_update_text($data, $new_content, $update_count);

            if (isset($new_content['append_blocks']) && is_array($new_content['append_blocks'])) {
                foreach ($new_content['append_blocks'] as $new_block) {
                    if (is_array($new_block) && isset($new_block['elType'])) {
                        $updated_data[] = $new_block;
                        $update_count++;
                    }
                }
            }

            if (isset($new_content['insert_blocks']) && is_array($new_content['insert_blocks'])) {
                foreach ($new_content['insert_blocks'] as $insert) {
                    if (isset($insert['after_index']) && isset($insert['block']) && is_array($insert['block'])) {
                        array_splice($updated_data, intval($insert['after_index']) + 1, 0, array($insert['block']));
                        $update_count++;
                    }
                }
            }

            if (isset($new_content['delete_blocks']) && is_array($new_content['delete_blocks'])) {
                $updated_data = self::recursive_delete_by_label($updated_data, $new_content['delete_blocks']);
            }

            if (empty($updated_data) || !self::validate_elementor_data($updated_data)) {
                return new WP_Error('invalid_structure', 'La estructura generada por Claude es inválida.');
            }

            update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($updated_data)));
            self::log("Surgical update: $update_count changes.");
        }

        $current_settings = get_post_meta($post_id, '_elementor_page_settings', true);
        if (!is_array($current_settings))
            $current_settings = array();

        if (isset($new_content['page_settings']) && is_array($new_content['page_settings'])) {
            $current_settings = array_merge($current_settings, $new_content['page_settings']);
        }
        if (isset($new_content['custom_css'])) {
            $current_settings['custom_css'] = $new_content['custom_css'];
        }

        update_post_meta($post_id, '_elementor_page_settings', $current_settings);

        if (class_exists('\\Elementor\\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
            delete_post_meta($post_id, '_elementor_css');
        }

        return true;
    }

    private static function call_claude_api($api_key, $system_prompt, $user_message, $model = 'claude-haiku-4-5-20251001')
    {
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => $model,
                'max_tokens' => 8192,
                'system' => $system_prompt,
                'messages' => array(
                        array('role' => 'user', 'content' => $user_message)
                )
            )),
            'timeout' => 120
        ));

        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Error al conectar con Claude: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if ($code !== 200) {
            $error_msg = isset($result['error']['message']) ? $result['error']['message'] : 'Error desconocido';
            $error_type = isset($result['error']['type']) ? $result['error']['type'] : 'unknown';
            return new WP_Error('claude_error', "Error de Claude ($code - $error_type): $error_msg");
        }

        if (!isset($result['content'][0]['text'])) {
            return new WP_Error('invalid_response', 'Respuesta inválida de Claude.');
        }

        $ai_content = $result['content'][0]['text'];
        self::log("Raw Claude Response length: " . strlen($ai_content));

        $clean_content = $ai_content;
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $ai_content, $matches)) {
            $clean_content = $matches[1];
        }

        $parsed_content = json_decode($clean_content, true);
        if (is_array($parsed_content)) {
            self::log("Parsed Claude response: " . count($parsed_content) . " keys.");
            return $parsed_content;
        }

        self::log("Warning: Could not parse as JSON. Raw: " . mb_substr($ai_content, 0, 500));
        return array('content' => $ai_content);
    }

    private static function get_system_prompt($page_type, $is_design_mode = true)
    {
        $base = 'Eres un experto en Elementor JSON y copywriting. Tu trabajo es modificar el contenido y diseño de páginas de Elementor de forma PRECISA.

MODOS DE OPERACIÓN:
==================
MODO 1 — Cambios quirúrgicos (texto, estilos puntuales):
{"texts":[{"id":"WIDGET_ID","content":"nuevo texto"}],"blocks":{"admin_label":{ bloque completo }},"custom_css":"...","page_settings":{...}}

MODO 2 — Cambios estructurales completos:
{"full_elementor_json":[ array completo de secciones ]}

MODO 3 — Añadir bloques:
{"append_blocks":[{"id":"end_abc1234","elType":"section","settings":{},"elements":[...]}]}

MODO 4 — Insertar en posición:
{"insert_blocks":[{"after_index":2,"block":{...}}]}

MODO 5 — Eliminar bloques:
{"delete_blocks":["admin_label_a_eliminar"]}

REGLAS:
1. SIEMPRE devuelve JSON válido. Nada de texto extra.
2. Cada elemento: "id" (string), "elType" ("section"|"column"|"widget"), "elements" (array).
3. Widgets: "widgetType" (heading, text-editor, button, image, icon-box, spacer, etc).
4. IDs nuevos: "end_" + 7 chars aleatorios.
5. NO elimines elementos sin que lo pida el usuario.
6. Usa el MODO más simple posible.
7. Para cambios de color masivos usa "custom_css".
8. Si no entiendes: {"error":"descripción"}.

ESTRUCTURA: Section → Column → Widget
Settings comunes:
- heading: {title, header_size, title_color}
- text-editor: {editor (HTML), text_color}
- button: {text, link:{url}, button_text_color, background_color}
- image: {image:{url,id}}
- Fondos: background_background, background_color, background_image
- Spacing: padding/margin = {top,right,bottom,left,unit}';

        $contexts = array(
            'ventas' => "\n\nCONTEXTO: Página de VENTAS. Tono persuasivo, beneficios, urgencia.",
            'registro' => "\n\nCONTEXTO: Página de REGISTRO. Tono directo, valor gratuito, FOMO.",
            'gracias' => "\n\nCONTEXTO: Página GRACIAS COMPRA. Tono entusiasta, confirmar compra.",
            'gracias_registro' => "\n\nCONTEXTO: Página GRACIAS REGISTRO. Tono acogedor, próximos pasos.",
            'general' => "\n\nCONTEXTO: Página genérica. Adapta según contenido existente."
        );

        return $base . (isset($contexts[$page_type]) ? $contexts[$page_type] : $contexts['general']);
    }

    public static function extract_elementor_structure($post_id)
    {
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (empty($elementor_data))
            return array();
        $data = json_decode($elementor_data, true);
        if (!is_array($data))
            return array();
        $structure = array();
        self::recursive_extract_structure($data, $structure);
        return $structure;
    }

    private static function recursive_extract_structure($elements, &$structure)
    {
        if (!is_array($elements))
            return;
        foreach ($elements as $element) {
            $label = isset($element['settings']['_admin_label']) ? $element['settings']['_admin_label'] : '';
            if (!empty($label))
                $structure[$label] = $element;
            if (isset($element['elements']))
                self::recursive_extract_structure($element['elements'], $structure);
        }
    }

    private static function recursive_extract_text($elements, &$texts, $parent_label = '')
    {
        if (!is_array($elements))
            return;
        foreach ($elements as $element) {
            if (!is_array($element))
                continue;
            $my_label = isset($element['settings']['_admin_label']) ? $element['settings']['_admin_label'] : '';
            if (isset($element['widgetType'])) {
                $widget_id = isset($element['id']) ? $element['id'] : uniqid();
                $s = $element['settings'];
                $w_type = $element['widgetType'];
                $base_item = array('id' => $widget_id, 'type' => $w_type, 'bloque_padre' => $parent_label, 'nombre_elemento' => $my_label);

                switch ($w_type) {
                    case 'heading':
                        if (isset($s['title']))
                            $texts[] = array_merge($base_item, array('content' => $s['title']));
                        break;
                    case 'text-editor':
                        if (isset($s['editor']))
                            $texts[] = array_merge($base_item, array('content' => $s['editor']));
                        break;
                    case 'button':
                        if (isset($s['text']))
                            $texts[] = array_merge($base_item, array('content' => $s['text']));
                        break;
                    case 'icon-box':
                    case 'image-box':
                        if (isset($s['title_text']))
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|title_text', 'content' => $s['title_text']));
                        if (isset($s['description_text']))
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|description_text', 'content' => $s['description_text']));
                        break;
                    case 'testimonial':
                        if (isset($s['testimonial_content']))
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|testimonial_content', 'content' => $s['testimonial_content']));
                        if (isset($s['testimonial_name']))
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|testimonial_name', 'content' => $s['testimonial_name']));
                        break;
                    case 'accordion':
                    case 'toggle':
                        foreach (array('tabs', 'accordion', 'items') as $rk) {
                            if (isset($s[$rk]) && is_array($s[$rk])) {
                                foreach ($s[$rk] as $idx => $item) {
                                    foreach (array('tab_title', 'title', 'label') as $tk) {
                                        if (isset($item[$tk])) {
                                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|' . $rk . '|' . $idx . '|' . $tk, 'content' => $item[$tk]));
                                            break;
                                        }
                                    }
                                    foreach (array('tab_content', 'content', 'description') as $ck) {
                                        if (isset($item[$ck])) {
                                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|' . $rk . '|' . $idx . '|' . $ck, 'content' => $item[$ck]));
                                            break;
                                        }
                                    }
                                }
                                break;
                            }
                        }
                        break;
                    case 'icon-list':
                        if (isset($s['icon_list']) && is_array($s['icon_list'])) {
                            foreach ($s['icon_list'] as $idx => $item) {
                                if (isset($item['text']))
                                    $texts[] = array_merge($base_item, array('id' => $widget_id . '|icon_list|' . $idx . '|text', 'content' => $item['text']));
                            }
                        }
                        break;
                    case 'alert':
                        if (isset($s['alert_title']))
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|alert_title', 'content' => $s['alert_title']));
                        if (isset($s['alert_description']))
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|alert_description', 'content' => $s['alert_description']));
                        break;
                    case 'call-to-action':
                        if (isset($s['title']))
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|title', 'content' => $s['title']));
                        if (isset($s['description']))
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|description', 'content' => $s['description']));
                        if (isset($s['button_text']))
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|button_text', 'content' => $s['button_text']));
                        break;
                }
            }
            if (isset($element['elements'])) {
                self::recursive_extract_text($element['elements'], $texts, !empty($my_label) ? $my_label : $parent_label);
            }
        }
    }

    private static function recursive_update_text($elements, $new_content, &$update_count = 0)
    {
        if (!is_array($elements))
            return $elements;
        foreach ($elements as &$element) {
            if (!is_array($element))
                continue;
            $label = isset($element['settings']['_admin_label']) ? $element['settings']['_admin_label'] : '';
            if (!empty($label) && isset($new_content['blocks'][$label]) && is_array($new_content['blocks'][$label])) {
                $element = $new_content['blocks'][$label];
                $update_count++;
                continue;
            }
            if (isset($element['widgetType']) && isset($element['id']) && isset($new_content['texts']) && is_array($new_content['texts'])) {
                foreach ($new_content['texts'] as $text_item) {
                    $id_parts = explode('|', $text_item['id']);
                    if ($id_parts[0] !== $element['id'])
                        continue;
                    $update_count++;
                    if (count($id_parts) === 1) {
                        switch ($element['widgetType']) {
                            case 'heading':
                                $element['settings']['title'] = $text_item['content'];
                                break;
                            case 'text-editor':
                                $element['settings']['editor'] = $text_item['content'];
                                break;
                            case 'button':
                                $element['settings']['text'] = $text_item['content'];
                                break;
                        }
                    }
                    elseif (count($id_parts) === 2) {
                        $element['settings'][$id_parts[1]] = $text_item['content'];
                    }
                    elseif (count($id_parts) === 4) {
                        if (isset($element['settings'][$id_parts[1]][(int)$id_parts[2]])) {
                            $element['settings'][$id_parts[1]][(int)$id_parts[2]][$id_parts[3]] = $text_item['content'];
                        }
                    }
                }
            }
            if (isset($element['elements'])) {
                $element['elements'] = self::recursive_update_text($element['elements'], $new_content, $update_count);
            }
        }
        return $elements;
    }

    private static function recursive_delete_by_label($elements, $labels)
    {
        if (!is_array($elements))
            return $elements;
        $filtered = array();
        foreach ($elements as $el) {
            $label = isset($el['settings']['_admin_label']) ? $el['settings']['_admin_label'] : '';
            if (!empty($label) && in_array($label, $labels))
                continue;
            if (isset($el['elements']))
                $el['elements'] = self::recursive_delete_by_label($el['elements'], $labels);
            $filtered[] = $el;
        }
        return $filtered;
    }

    private static function validate_elementor_data($data)
    {
        if (!is_array($data))
            return false;
        foreach ($data as $el) {
            if (!self::recursive_validate_element($el))
                return false;
        }
        return true;
    }

    private static function recursive_validate_element($el)
    {
        if (!is_array($el) || empty($el['elType']))
            return false;
        if (isset($el['elements']) && is_array($el['elements'])) {
            foreach ($el['elements'] as $sub) {
                if (!self::recursive_validate_element($sub))
                    return false;
            }
        }
        return true;
    }
}