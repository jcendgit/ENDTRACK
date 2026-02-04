<?php

class ENDTrack_AI
{
    private static function log($message)
    {
        // Use a more reliable path for logging in WP environments
        $log_file = dirname(dirname(__FILE__)) . '/endtrack_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    }

    /**
     * Generate AI copy for a specific page
     * 
     * @param int $post_id WordPress post ID
     * @param string $page_type Type of page: 'ventas', 'registro', 'gracias', 'gracias_registro'
     * @param string $user_prompt User's instructions for the AI
     * @return array|WP_Error Success message or error
     */
    public static function generate_copy_for_page($post_id, $page_type, $user_prompt)
    {
        self::log("--- START AI GENERATION ---");
        self::log("Post ID: $post_id, Page Type: $page_type");
        self::log("User Prompt: $user_prompt");

        // Get OpenAI API key
        $texts = get_option('endtrack_texts', array());
        $api_key = isset($texts['openai_key']) ? $texts['openai_key'] : '';

        if (empty($api_key)) {
            self::log("Error: Missing OpenAI API Key");
            return new WP_Error('missing_api_key', 'No se ha configurado la API Key de OpenAI.');
        }

        // Extract current content, structure and page settings
        $current_texts = self::extract_elementor_text($post_id);
        $current_structure = self::extract_elementor_structure($post_id);
        $page_settings = get_post_meta($post_id, '_elementor_page_settings', true);

        $context = array(
            'copy_items' => $current_texts,
            'structural_blocks' => $current_structure,
            'page_settings' => is_array($page_settings) ? $page_settings : array(),
            'instruction_tip' => 'Si el usuario pide un cambio de color masivo (ej: fondo negro y letras blancas), puedes usar el campo "custom_css" para inyectar estilos globales.'
        );

        $content_summary = "Contexto (JSON):\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $messages = array(
            array('role' => 'system', 'content' => self::get_system_prompt($page_type)),
            array('role' => 'user', 'content' => $user_prompt),
            array('role' => 'user', 'content' => $content_summary)
        );

        // Call OpenAI API
        $ai_response = self::call_openai_api($api_key, $messages);

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
        return array('success' => true, 'message' => 'Cambios aplicados correctamente.');
    }

    /**
     * Extract text content from Elementor page
     */
    public static function extract_elementor_text($post_id)
    {
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);

        if (empty($elementor_data)) {
            return array();
        }

        $data = json_decode($elementor_data, true);

        if (!is_array($data)) {
            return array();
        }

        $texts = array();
        self::recursive_extract_text($data, $texts);

        return $texts;
    }

    /**
     * Update Elementor page with new AI-generated content and design
     */
    public static function update_elementor_text($post_id, $new_content)
    {
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        $data = !empty($elementor_data) ? json_decode($elementor_data, true) : array();

        // 1. Update structure and texts
        $update_count = 0;
        $updated_data = self::recursive_update_text($data, $new_content, $update_count);

        // 2. Append new blocks if provided
        if (isset($new_content['append_blocks']) && is_array($new_content['append_blocks'])) {
            foreach ($new_content['append_blocks'] as $new_block) {
                if (is_array($new_block) && isset($new_block['elType'])) {
                    $updated_data[] = $new_block;
                    $update_count++;
                }
            }
        }

        if (empty($updated_data) || !self::validate_elementor_data($updated_data)) {
            self::log("Error: Updated data is empty or structurally invalid. Aborting update.");
            return new WP_Error('invalid_structure', 'La estructura generada por la IA es inválida y podría romper la página.');
        }

        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($updated_data)));

        // 3. Update Page Settings & Custom CSS
        $current_settings = get_post_meta($post_id, '_elementor_page_settings', true);
        if (!is_array($current_settings))
            $current_settings = array();

        if (isset($new_content['page_settings']) && is_array($new_content['page_settings'])) {
            $current_settings = array_merge($current_settings, $new_content['page_settings']);
            self::log("Merged page settings.");
        }

        if (isset($new_content['custom_css'])) {
            $current_settings['custom_css'] = $new_content['custom_css'];
            self::log("Applied custom CSS.");
        }

        update_post_meta($post_id, '_elementor_page_settings', $current_settings);

        self::log("Total updates: $update_count");

        // Clear Elementor cache
        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        return true;
    }

    /**
     * Call OpenAI API
     * 
     * @param string $api_key OpenAI API key
     * @param array $messages Array of messages for the chat
     * @return array|WP_Error AI response or error
     */
    private static function call_openai_api($api_key, $messages)
    {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4',
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 3000
            )),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Error al conectar con OpenAI: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if ($code !== 200) {
            $error_msg = isset($result['error']['message']) ? $result['error']['message'] : 'Error desconocido';
            return new WP_Error('openai_error', "Error de OpenAI ($code): $error_msg");
        }

        if (!isset($result['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', 'Respuesta inválida de OpenAI');
        }

        $ai_content = $result['choices'][0]['message']['content'];
        self::log("Raw AI Response: " . $ai_content);

        // Clean up markdown code blocks if present (e.g., ```json ... ``` or ``` ...)
        $clean_content = $ai_content;
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $ai_content, $matches)) {
            $clean_content = $matches[1];
        }

        // Try to parse as JSON
        $parsed_content = json_decode($clean_content, true);

        if (is_array($parsed_content)) {
            return $parsed_content;
        }

        // Fallback for non-JSON responses
        return array('content' => $ai_content);
    }

    /**
     * Get system prompt based on page type
     * 
     * @param string $page_type Type of page
     * @param bool $is_design_mode Whether we are in design mode (structure/colors)
     * @return string System prompt
     */
    private static function get_system_prompt($page_type, $is_design_mode = true)
    {
        $base_instructions = 'Eres un experto copywriter y Diseñador de Elementor (JSON Engine). Tu tarea es generar copy persuasivo y diseño estructural.
        
        REGLAS DE SALIDA (JSON):
        1. Para cambios de texto simples: {"texts": [{"id": "id_original", "content": "nuevo_texto"}]}
        2. Para cambios de diseño en bloques existentes (colores, fondos, mover widgets): usa "blocks". Debes devolver el JSON completo del bloque modificado.
        3. Para cambios GLOBALES de página (fondo de toda la página, layout), usa "page_settings".
           Ej: {"page_settings": {"background_background": "classic", "background_color": "#000000"}}
        4. Para CREAR bloques nuevos: usa "append_blocks".
        5. **MAGIA DE ESTILO (CRÍTICOS)**: Si el usuario pide un cambio de color masivo (ej: fondo oscuro, letras blancas), usa el campo "custom_css".
           Ej: {"custom_css": "selector { background-color: #000 !important; color: #fff !important; } .elementor-heading-title { color: #fff !important; }"}
           No borres el CSS existente si lo hay en "page_settings", pero prioriza solucionar la petición del usuario.
        
        REGLAS DE ESTILO (DETALLE):
        - Fondo Negro: settings -> background_background = "classic", background_color = "#000000".
        - Texto Blanco: Si usas blocks, cambia settings -> title_color o text_color a "#ffffff".';

        $prompts = array(
            'ventas' => $base_instructions . ' Esta es una página de VENTAS. El tono debe ser persuasivo, resaltar beneficios y llamar a la acción.',
            'registro' => $base_instructions . ' Esta es una página de REGISTRO (Lead Magnet). El tono debe ser directo, enfocado en el valor gratuito y generar curiosidad.',
            'gracias' => $base_instructions . ' Esta es una página de GRACIAS POR COMPRA. El tono debe ser entusiasta, confirmar la compra y dar instrucciones claras del siguiente paso.',
            'gracias_registro' => $base_instructions . ' Esta es una página de GRACIAS POR REGISTRO. El tono debe ser acogedor y preparar al usuario para lo que recibirá por email.'
        );

        return isset($prompts[$page_type]) ? $prompts[$page_type] : $prompts['ventas'];
    }

    /**
     * Extract full structure from Elementor
     */
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
            if (!empty($label)) {
                $structure[$label] = $element;
            }
            if (isset($element['elements'])) {
                self::recursive_extract_structure($element['elements'], $structure);
            }
        }
    }

    /**
     * Recursively extract text from Elementor elements
     * 
     * @param array $elements Elementor elements
     * @param array &$texts Reference to texts array
     * @param string $parent_label Label from parent section/column
     */
    private static function recursive_extract_text($elements, &$texts, $parent_label = '')
    {
        if (!is_array($elements)) {
            return;
        }

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            // Determine labels
            $my_label = isset($element['settings']['_admin_label']) ? $element['settings']['_admin_label'] : '';

            // Extract text from common widgets
            if (isset($element['widgetType'])) {
                $widget_id = isset($element['id']) ? $element['id'] : uniqid();
                $s = $element['settings'];
                $w_type = $element['widgetType'];

                // Base item metadata
                $base_item = array(
                    'id' => $widget_id,
                    'type' => $w_type,
                    'bloque_padre' => $parent_label, // Section/Column name
                    'nombre_elemento' => $my_label   // Widget admin label
                );

                self::log("Extracting widget: $w_type (ID: $widget_id, Block: $parent_label, Name: $my_label)");

                switch ($w_type) {
                    case 'heading':
                        if (isset($s['title'])) {
                            $texts[] = array_merge($base_item, array('content' => $s['title']));
                        }
                        break;

                    case 'text-editor':
                        if (isset($s['editor'])) {
                            $texts[] = array_merge($base_item, array('content' => $s['editor']));
                        }
                        break;

                    case 'button':
                        if (isset($s['text'])) {
                            $texts[] = array_merge($base_item, array('content' => $s['text']));
                        }
                        break;

                    case 'icon-box':
                    case 'image-box':
                        if (isset($s['title_text'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|title_text', 'content' => $s['title_text']));
                        }
                        if (isset($s['description_text'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|description_text', 'content' => $s['description_text']));
                        }
                        break;

                    case 'pricing-table':
                        if (isset($s['heading'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|heading', 'content' => $s['heading']));
                        }
                        if (isset($s['sub_heading'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|sub_heading', 'content' => $s['sub_heading']));
                        }
                        if (isset($s['period'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|period', 'content' => $s['period']));
                        }
                        if (isset($s['features_list']) && is_array($s['features_list'])) {
                            foreach ($s['features_list'] as $idx => $feature) {
                                if (isset($feature['text'])) {
                                    $texts[] = array_merge($base_item, array('id' => $widget_id . '|features_list|' . $idx . '|text', 'content' => $feature['text']));
                                }
                            }
                        }
                        break;

                    case 'accordion':
                    case 'toggle':
                        $repeater_keys = array('tabs', 'accordion', 'items');
                        foreach ($repeater_keys as $rk) {
                            if (isset($s[$rk]) && is_array($s[$rk])) {
                                foreach ($s[$rk] as $idx => $item) {
                                    $title_keys = array('tab_title', 'title', 'label');
                                    foreach ($title_keys as $tk) {
                                        if (isset($item[$tk])) {
                                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|' . $rk . '|' . $idx . '|' . $tk, 'content' => $item[$tk]));
                                            break;
                                        }
                                    }
                                    $content_keys = array('tab_content', 'content', 'description');
                                    foreach ($content_keys as $ck) {
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
                                if (isset($item['text'])) {
                                    $texts[] = array_merge($base_item, array('id' => $widget_id . '|icon_list|' . $idx . '|text', 'content' => $item['text']));
                                }
                            }
                        }
                        break;

                    case 'testimonial':
                        if (isset($s['testimonial_content'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|testimonial_content', 'content' => $s['testimonial_content']));
                        }
                        if (isset($s['testimonial_name'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|testimonial_name', 'content' => $s['testimonial_name']));
                        }
                        if (isset($s['testimonial_job'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|testimonial_job', 'content' => $s['testimonial_job']));
                        }
                        break;

                    case 'counter':
                        if (isset($s['title'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|title', 'content' => $s['title']));
                        }
                        break;

                    case 'progress':
                        if (isset($s['title'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|title', 'content' => $s['title']));
                        }
                        break;

                    case 'alert':
                        if (isset($s['alert_title'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|alert_title', 'content' => $s['alert_title']));
                        }
                        if (isset($s['alert_description'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|alert_description', 'content' => $s['alert_description']));
                        }
                        break;

                    case 'call-to-action':
                        if (isset($s['title'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|title', 'content' => $s['title']));
                        }
                        if (isset($s['description'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|description', 'content' => $s['description']));
                        }
                        if (isset($s['button_text'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|button_text', 'content' => $s['button_text']));
                        }
                        break;

                    case 'icon':
                        if (isset($s['title'])) {
                            $texts[] = array_merge($base_item, array('id' => $widget_id . '|title', 'content' => $s['title']));
                        }
                        break;

                    default:
                        if (strpos($w_type, 'accordion') !== false || strpos($w_type, 'faq') !== false || strpos($w_type, 'toggle') !== false) {
                            $repeater_keys = array('tabs', 'accordion', 'items', 'faq_list', 'list_items');
                            foreach ($repeater_keys as $rk) {
                                if (isset($s[$rk]) && is_array($s[$rk])) {
                                    foreach ($s[$rk] as $idx => $item) {
                                        $title_keys = array('tab_title', 'title', 'label', 'question');
                                        foreach ($title_keys as $tk) {
                                            if (isset($item[$tk]) && is_string($item[$tk])) {
                                                $texts[] = array_merge($base_item, array('id' => $widget_id . '|' . $rk . '|' . $idx . '|' . $tk, 'content' => $item[$tk]));
                                                break;
                                            }
                                        }
                                        $content_keys = array('tab_content', 'content', 'description', 'answer');
                                        foreach ($content_keys as $ck) {
                                            if (isset($item[$ck]) && is_string($item[$ck])) {
                                                $texts[] = array_merge($base_item, array('id' => $widget_id . '|' . $rk . '|' . $idx . '|' . $ck, 'content' => $item[$ck]));
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        break;
                }
            }

            // Recurse into nested elements (sections and columns also have 'elements')
            if (isset($element['elements'])) {
                self::recursive_extract_text($element['elements'], $texts, !empty($my_label) ? $my_label : $parent_label);
            }
        }
    }

    /**
     * Recursively update text in Elementor elements
     * 
     * @param array $elements Elementor elements
     * @param array $new_content New content from AI
     * @param int &$update_count Counter for updated widgets
     * @return array Updated elements
     */
    private static function recursive_update_text($elements, $new_content, &$update_count = 0)
    {
        if (!is_array($elements)) {
            return $elements;
        }

        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }

            // Check for Block (Design) Update first
            $label = isset($element['settings']['_admin_label']) ? $element['settings']['_admin_label'] : '';
            if (!empty($label) && isset($new_content['blocks'][$label]) && is_array($new_content['blocks'][$label])) {
                self::log("Surgical Design Update: Replacing block '$label'");
                $element = $new_content['blocks'][$label];
                $update_count++;
                continue; // Skip text updates for this element since it was replaced
            }

            // Update text in widgets
            if (isset($element['widgetType']) && isset($element['id'])) {
                $widget_id = $element['id'];

                if (isset($new_content['texts']) && is_array($new_content['texts'])) {
                    foreach ($new_content['texts'] as $text_item) {
                        $id_parts = explode('|', $text_item['id']);
                        $target_id = $id_parts[0];

                        if ($target_id !== $widget_id)
                            continue;

                        $new_text = $text_item['content'];
                        $update_count++;

                        // Simple widget update
                        if (count($id_parts) === 1) {
                            switch ($element['widgetType']) {
                                case 'heading':
                                    $element['settings']['title'] = $new_text;
                                    break;
                                case 'text-editor':
                                    $element['settings']['editor'] = $new_text;
                                    break;
                                case 'button':
                                    $element['settings']['text'] = $new_text;
                                    break;
                            }
                        }
                        // Complex widget update (synthetic IDs)
                        else {
                            $field = $id_parts[1];
                            if (count($id_parts) === 2) {
                                // icon-box, image-box, pricing-table simple fields, testimonials
                                $element['settings'][$field] = $new_text;
                            } elseif (count($id_parts) === 4) {
                                // pricing-table features, accordion/toggle tabs, icon-list items
                                $idx = (int) $id_parts[2];
                                $subfield = $id_parts[3];
                                if (isset($element['settings'][$field][$idx])) {
                                    $element['settings'][$field][$idx][$subfield] = $new_text;
                                }
                            }
                        }
                    }
                }
            }

            // Recurse into nested elements
            if (isset($element['elements'])) {
                $element['elements'] = self::recursive_update_text($element['elements'], $new_content, $update_count);
            }
        }

        return $elements;
    }

    /**
     * Validate the entire Elementor data structure for critical flaws (missing elType)
     */
    private static function validate_elementor_data($data)
    {
        if (!is_array($data))
            return false;
        foreach ($data as $element) {
            if (!self::recursive_validate_element($element))
                return false;
        }
        return true;
    }

    private static function recursive_validate_element($element)
    {
        if (!is_array($element))
            return false;
        if (empty($element['elType'])) {
            self::log("Validation Failure: Missing elType in element.");
            return false;
        }
        if (isset($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as $sub_element) {
                if (!self::recursive_validate_element($sub_element))
                    return false;
            }
        }
        return true;
    }
}
