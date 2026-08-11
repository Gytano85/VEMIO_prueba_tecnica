<?php
/**
 * Configuración de PromoGuard.
 *
 * El sistema funciona completo sin configurar nada: el asesor de IA opera en modo local
 * (motor de reglas determinista). Si se define una API key de Anthropic, el dictamen se
 * enriquece con Claude y degrada al modo local si la llamada falla.
 */
return [
    'app_name' => 'PromoGuard',
    'client'   => 'VEMIO · Inteligencia comercial con IA para CPG',

    'database' => __DIR__ . '/data/promoguard.sqlite',

    'ai' => [
        // Dejar vacío para operar 100% offline con el motor local.
        'api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
        'model'   => 'claude-sonnet-5',
        'timeout' => 20,
    ],

    // Umbrales de aprobación sobre la cobertura (uplift obtenido / uplift necesario).
    'thresholds' => [
        'approve' => 1.00,
        'review'  => 0.75,
    ],
];
