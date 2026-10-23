<?php
return [
    // Keamanan: Ollama selalu loopback, tidak ada cloud AI atau API key.
    'ollama_model' => env('EDUXID_WORD_AI_MODEL', 'qwen3.5:0.8b'),
    'preview_ttl_seconds' => 3600,
];
